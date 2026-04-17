import { handleRegister, handleToken, handleRefresh } from './auth';
import { handleEntitlement } from './entitlement';
import { handleChat } from './chat';
import { handleLemonSqueezy } from './webhooks';
import { requireAuth } from './middleware';
import { json } from './utils';
import type { Env } from './types';

export default {
	async fetch( request: Request, env: Env ): Promise< Response > {
		const url = new URL( request.url );
		const method = request.method;
		const path = url.pathname;

		// CORS preflight
		if ( method === 'OPTIONS' ) {
			return new Response( null, {
				status: 204,
				headers: corsHeaders( request ),
			} );
		}

		// Public routes
		if ( method === 'POST' && path === '/v1/auth/register' ) {
			return handleRegister( request, env );
		}
		if ( method === 'POST' && path === '/v1/auth/token' ) {
			return handleToken( request, env );
		}
		if ( method === 'POST' && path === '/v1/auth/refresh' ) {
			return handleRefresh( request, env );
		}
		if ( method === 'POST' && path === '/webhooks/lemonsqueezy' ) {
			return handleLemonSqueezy( request, env );
		}

		// Authenticated routes
		const authResult = await requireAuth( request, env );
		if ( authResult instanceof Response ) {
			return authResult;
		}
		const { user } = authResult;

		if ( method === 'GET' && path === '/v1/entitlement' ) {
			return handleEntitlement( request, env, user );
		}
		if ( method === 'POST' && path === '/v1/chat' ) {
			return handleChat( request, env, user );
		}

		return json( { error: 'Not found' }, 404 );
	},
};

function corsHeaders( req: Request ): HeadersInit {
	return {
		'Access-Control-Allow-Origin': req.headers.get( 'Origin' ) ?? '*',
		'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
		'Access-Control-Allow-Headers': 'Authorization, Content-Type',
		'Access-Control-Max-Age': '86400',
	};
}
