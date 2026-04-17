import { json } from './utils';
import type { Env, AuthUser } from './types';

export async function handleChat(
  _req: Request,
  _env: Env,
  _user: AuthUser
): Promise<Response> {
  return json({ error: 'Chat proxy not yet implemented. Coming in Phase 3.' }, 501);
}
