import { json } from './utils';
import type { Env } from './types';

// eslint-disable-next-line @typescript-eslint/no-unused-vars
export async function handleLemonSqueezy(
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	_req: Request,
	// eslint-disable-next-line @typescript-eslint/no-unused-vars
	_env: Env
): Promise< Response > {
	return json(
		{ error: 'Webhook handler not yet implemented. Coming in Phase 3.' },
		501
	);
}
