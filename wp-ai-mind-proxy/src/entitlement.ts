import { yyyyMM, nextMonthStart } from './utils';
import { getTierConfig } from './tier-config';
import type { Env, AuthUser } from './types';

export async function handleEntitlement(
  _req: Request,
  env: Env,
  user: AuthUser
): Promise<Response> {
  const config   = getTierConfig(user.plan);
  const monthKey = `usage:${user.sub}:${yyyyMM()}`;
  const usedStr  = await env.USAGE_KV.get(monthKey);
  const used     = usedStr ? parseInt(usedStr, 10) : 0;
  const limit    = config.tokens_per_month;

  return new Response(JSON.stringify({
    plan:              user.plan,
    tokens_used:       used,
    tokens_limit:      limit,
    tokens_remaining:  limit === null ? null : Math.max(0, limit - used),
    resets_at:         nextMonthStart(),
    features:          config.features,
    allowed_models:    config.allowed_models,
  }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}
