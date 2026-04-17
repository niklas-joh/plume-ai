import { handleRegister, handleToken, handleRefresh } from './auth';
import { handleEntitlement } from './entitlement';
import { handleChat } from './chat';
import { handleLemonSqueezy } from './webhooks';
import { requireAuth } from './middleware';
import { json } from './utils';
import type { Env } from './types';

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url    = new URL(request.url);
    const method = request.method;
    const path   = url.pathname;

    // CORS preflight
    if (method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders(request, env) });
    }

    // Public routes
    if (method === 'POST' && path === '/v1/auth/register')      return addCors(await handleRegister(request, env), request, env);
    if (method === 'POST' && path === '/v1/auth/token')         return addCors(await handleToken(request, env), request, env);
    if (method === 'POST' && path === '/v1/auth/refresh')       return addCors(await handleRefresh(request, env), request, env);
    if (method === 'POST' && path === '/webhooks/lemonsqueezy') return handleLemonSqueezy(request, env);

    // Authenticated routes
    const authResult = await requireAuth(request, env);
    if (authResult instanceof Response) return addCors(authResult, request, env);
    const { user } = authResult;

    if (method === 'GET'  && path === '/v1/entitlement') return addCors(await handleEntitlement(request, env, user), request, env);
    if (method === 'POST' && path === '/v1/chat')        return addCors(await handleChat(request, env, user), request, env);

    return addCors(json({ error: 'Not found' }, 404), request, env);
  },
};

function allowedOrigin(req: Request, env: Env): string | null {
  const origin  = req.headers.get('Origin');
  if (!origin) return null;

  const allowlist = (env.ALLOWED_ORIGINS ?? '')
    .split(',')
    .map(o => o.trim())
    .filter(Boolean);

  return allowlist.includes(origin) ? origin : null;
}

function corsHeaders(req: Request, env: Env): HeadersInit {
  const origin = allowedOrigin(req, env);
  const headers: Record<string, string> = {
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Authorization, Content-Type',
    'Access-Control-Max-Age':       '86400',
  };
  if (origin) {
    headers['Access-Control-Allow-Origin'] = origin;
    headers['Vary'] = 'Origin';
  }
  return headers;
}

function addCors(response: Response, req: Request, env: Env): Response {
  const origin = allowedOrigin(req, env);
  if (!origin) return response;

  const newHeaders = new Headers(response.headers);
  newHeaders.set('Access-Control-Allow-Origin', origin);
  newHeaders.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  newHeaders.set('Access-Control-Allow-Headers', 'Authorization, Content-Type');
  newHeaders.append('Vary', 'Origin');

  return new Response(response.body, {
    status:     response.status,
    statusText: response.statusText,
    headers:    newHeaders,
  });
}
