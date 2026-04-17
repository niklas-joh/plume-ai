import { json } from './utils';
import type { Env } from './types';

export async function handleLemonSqueezy(
  _req: Request,
  _env: Env
): Promise<Response> {
  return json({ error: 'Webhook handler not yet implemented. Coming in Phase 3.' }, 501);
}
