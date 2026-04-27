// src/webhook.ts

import { Env, SiteRecord, LicenceRecord, ProxyTier } from './types';
import { verifyLsSignature } from './signature';

// Build LemonSqueezy variant ID → plugin tier map from environment variables.
// Pro BYOK (1550517) is not here — it bypasses the proxy; handled in Phase 3.
function buildVariantTierMap( env: Env ): Record< string, ProxyTier > {
	const map: Record< string, ProxyTier > = {};
	if ( env.LS_PRO_MONTHLY_VARIANT_ID ) {
		map[ env.LS_PRO_MONTHLY_VARIANT_ID ] = 'pro_managed';
	}
	if ( env.LS_PRO_ANNUAL_VARIANT_ID ) {
		map[ env.LS_PRO_ANNUAL_VARIANT_ID ] = 'pro_managed';
	}
	return map;
}

export async function handleWebhook(
	request: Request,
	env: Env
): Promise< Response > {
	if ( request.method !== 'POST' ) {
		return new Response( 'Method not allowed', { status: 405 } );
	}

	const MAX_WEBHOOK_BYTES = 524_288; // 512 KB — LemonSqueezy payloads are small
	const contentLength = Number( request.headers.get( 'Content-Length' ) ?? 0 );
	if ( contentLength > MAX_WEBHOOK_BYTES ) {
		return new Response( 'Payload too large', { status: 413 } );
	}

	const bodyText = await request.text();
	if ( new TextEncoder().encode( bodyText ).length > MAX_WEBHOOK_BYTES ) {
		return new Response( 'Payload too large', { status: 413 } );
	}

	const signature = request.headers.get( 'X-Signature' ) ?? '';

	if ( ! ( await verifyLsSignature( bodyText, signature, env ) ) ) {
		return new Response( 'Invalid signature', { status: 401 } );
	}

	let payload: Record< string, unknown >;
	try {
		payload = JSON.parse( bodyText ) as Record< string, unknown >;
	} catch {
		return new Response( 'Invalid JSON', { status: 400 } );
	}

	const meta = payload.meta as Record< string, unknown > | undefined;
	const eventName = ( meta?.event_name as string | undefined ) ?? '';

	switch ( eventName ) {
		case 'order_created':
		case 'subscription_created':
		case 'subscription_resumed':
		case 'subscription_unpaused':
			await handleActivation( payload, env );
			break;

		case 'subscription_cancelled':
		case 'subscription_expired':
		case 'subscription_paused':
			await handleDeactivation( payload, env );
			break;

		case 'licence_key_created':
		case 'licence_key_updated':
			await handleLicenceKey( payload, env );
			break;

		default:
			break;
	}

	return new Response( 'OK', { status: 200 } );
}

async function handleActivation(
	payload: Record< string, unknown >,
	env: Env
): Promise< void > {
	const meta = payload.meta as Record< string, unknown > | undefined;
	const customData = meta?.custom_data as
		| Record< string, string >
		| undefined;
	const siteToken = customData?.site_token;
	if ( ! siteToken ) {
		return;
	}

	const data = payload.data as Record< string, unknown > | undefined;
	const attrs = data?.attributes as Record< string, unknown > | undefined;
	// first_order_item is an object on order_created events; extract its variant_id.
	const firstOrderItem = attrs?.first_order_item as
		| Record< string, unknown >
		| undefined;
	const variantId = String(
		( ( firstOrderItem?.variant_id ?? attrs?.variant_id ) as unknown ) ?? ''
	);
	const tier = buildVariantTierMap( env )[ variantId ] ?? null;
	if ( ! tier ) {
		return;
	}

	const licenceKey = ( attrs?.identifier as string | undefined ) ?? '';
	await upgradeSiteTier( siteToken, tier, licenceKey, env );
}

async function handleDeactivation(
	payload: Record< string, unknown >,
	env: Env
): Promise< void > {
	const data = payload.data as Record< string, unknown > | undefined;
	const attrs = data?.attributes as Record< string, unknown > | undefined;
	const licenceKey = ( attrs?.licence_key ?? attrs?.identifier ) as
		| string
		| undefined;
	if ( ! licenceKey ) {
		return;
	}

	const record = await env.USAGE_KV.get< LicenceRecord >(
		`licence:${ licenceKey }`,
		'json'
	);
	if ( record?.site_token ) {
		await downgradeSiteTier( record.site_token, env );
		await env.USAGE_KV.delete( `licence:${ licenceKey }` );
	}
}

async function handleLicenceKey(
	payload: Record< string, unknown >,
	env: Env
): Promise< void > {
	const data = payload.data as Record< string, unknown > | undefined;
	const attrs = data?.attributes as Record< string, unknown > | undefined;
	const key = ( attrs?.key as string | undefined ) ?? '';
	const status = ( attrs?.status as string | undefined ) ?? '';
	const meta = payload.meta as Record< string, unknown > | undefined;
	const customData = meta?.custom_data as
		| Record< string, string >
		| undefined;
	const siteToken = customData?.site_token;

	if ( ! key || ! siteToken ) {
		return;
	}

	if ( status === 'active' ) {
		const variantId = String( ( attrs?.variant_id as unknown ) ?? '' );
		const tier = buildVariantTierMap( env )[ variantId ] ?? 'pro_managed';
		const record: LicenceRecord = {
			tier,
			site_token: siteToken,
			activated_at: Date.now(),
		};
		await env.USAGE_KV.put( `licence:${ key }`, JSON.stringify( record ) );
	} else if ( status === 'disabled' || status === 'expired' ) {
		const record = await env.USAGE_KV.get< LicenceRecord >(
			`licence:${ key }`,
			'json'
		);
		if ( record ) {
			await downgradeSiteTier( record.site_token, env );
		}
		await env.USAGE_KV.delete( `licence:${ key }` );
	}
}

async function upgradeSiteTier(
	token: string,
	tier: ProxyTier,
	licenceKey: string,
	env: Env
): Promise< void > {
	const existing = await env.USAGE_KV.get< SiteRecord >(
		`site:${ token }`,
		'json'
	);
	if ( ! existing ) {
		return;
	}
	const updated: SiteRecord = {
		...existing,
		tier,
		...( licenceKey ? { ls_licence_key: licenceKey } : {} ),
	};
	await env.USAGE_KV.put( `site:${ token }`, JSON.stringify( updated ) );

	if ( licenceKey ) {
		const lr: LicenceRecord = {
			tier,
			site_token: token,
			activated_at: Date.now(),
		};
		await env.USAGE_KV.put(
			`licence:${ licenceKey }`,
			JSON.stringify( lr )
		);
	}
}

async function downgradeSiteTier( token: string, env: Env ): Promise< void > {
	const existing = await env.USAGE_KV.get< SiteRecord >(
		`site:${ token }`,
		'json'
	);
	if ( ! existing ) {
		return;
	}
	const { ls_licence_key: _removed, ...rest } = existing;
	await env.USAGE_KV.put(
		`site:${ token }`,
		JSON.stringify( { ...rest, tier: 'free' } )
	);
}
