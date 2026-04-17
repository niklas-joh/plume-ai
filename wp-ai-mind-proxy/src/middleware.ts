import { verifyJWT } from './jwt';
import { json } from './utils';
import type { Env, AuthUser } from './types';

export async function requireAuth(
	req: Request,
	env: Env
): Promise< { user: AuthUser } | Response > {
	const auth = req.headers.get( 'Authorization' ) ?? '';
	if ( ! auth.startsWith( 'Bearer ' ) ) {
		return json( { error: 'Unauthorized' }, 401 );
	}

	const payload = await verifyJWT( auth.slice( 7 ), env.JWT_SECRET );
	if ( ! payload ) {
		return json( { error: 'Invalid or expired token' }, 401 );
	}

	return { user: payload as AuthUser };
}
