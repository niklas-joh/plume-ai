import apiFetch from '@wordpress/api-fetch';
import { DEFAULT_WEBSITE_URL } from './constants';

/**
 * Helpers for opt-in usage events and UTM-tagged website links.
 *
 * Client events are only ever posted to the site's own REST API
 * (`/plume/v1/telemetry/event`). The server queues them only when the site
 * owner has opted in, and sends them later from WP-Cron, so a click never
 * waits on, or talks to, an outside service.
 *
 * @example
 * import { withUtm, recordUpgradeClick } from '../shared/telemetry';
 *
 * <a
 *     href={ withUtm( `${ websiteUrl }/pricing`, 'chat', 'out_of_credits' ) }
 *     onClick={ () => recordUpgradeClick( 'out_of_credits', 'chat' ) }
 * >
 *     Get more credits
 * </a>
 */

const UTM_KEYS = [ 'utm_source', 'utm_medium', 'utm_content' ];

/**
 * Add `utm_source=plugin`, `utm_medium` and (optionally) `utm_content` to a
 * link to the Plume website. Links to any other host are returned unchanged.
 *
 * Existing UTM values are replaced; other query parameters are kept.
 *
 * @param {string} url       Link URL.
 * @param {string} medium    Placement, for example `'dashboard'`.
 * @param {string} [content] Optional link identifier within the placement.
 * @return {string} Tagged URL, or `url` unchanged.
 */
export function withUtm( url, medium, content = '' ) {
	let parsed;
	try {
		parsed = new URL( url );
	} catch {
		return url;
	}
	const siteHost = new URL( DEFAULT_WEBSITE_URL ).hostname;
	if ( parsed.hostname.toLowerCase() !== siteHost ) {
		return url;
	}
	UTM_KEYS.forEach( ( key ) => parsed.searchParams.delete( key ) );
	parsed.searchParams.set( 'utm_source', 'plugin' );
	parsed.searchParams.set( 'utm_medium', medium );
	if ( content ) {
		parsed.searchParams.set( 'utm_content', content );
	}
	return parsed.toString();
}

/**
 * Post a client-originated usage event to the site's REST API.
 *
 * Fire and forget: `keepalive` lets the request finish when the click also
 * navigates away, and every failure is swallowed.
 *
 * @param {string} event   `'onboarding_step'` or `'upgrade_click'`.
 * @param {Object} [props] Event properties (see the event allowlist).
 * @return {void}
 */
export function recordClientEvent( event, props = {} ) {
	try {
		const request = apiFetch( {
			path: '/plume/v1/telemetry/event',
			method: 'POST',
			data: { event, props },
			keepalive: true,
		} );
		if ( request && typeof request.catch === 'function' ) {
			request.catch( () => {} );
		}
	} catch {
		// Usage events must never break the screen.
	}
}

/**
 * Record a click on an upgrade or pricing link.
 *
 * @param {string} placement `'onboarding'`, `'dashboard_banner'` or `'out_of_credits'`.
 * @param {string} screen    Screen the link is on, for example `'chat'`.
 * @param {string} [plan]    Plan the link leads to (default `'pricing'`).
 * @return {void}
 */
export function recordUpgradeClick( placement, screen, plan = 'pricing' ) {
	recordClientEvent( 'upgrade_click', { placement, screen, plan } );
}
