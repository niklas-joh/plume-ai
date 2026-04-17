import { json } from './utils';
import type { Env, AuthUser } from './types';

// eslint-disable-next-line @typescript-eslint/no-unused-vars
export async function handleChat(
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	_req: Request,
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	_env: Env,
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	_user: AuthUser
): Promise< Response > {
	return json(
		{ error: 'Chat proxy not yet implemented. Coming in Phase 3.' },
		501
	);
}
