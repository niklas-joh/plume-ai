# Phase 2: Minimal Cloudflare Proxy

> ⚠️ **SUPERSEDED.** This plan describes the original HMAC shared-secret design. It was partially implemented and then replaced by **[Phase 2.1](phase-2.1-licence-auth-overhaul.md)** (Bearer site-token auth, zero-friction registration, LemonSqueezy webhook in Worker). Keep this file for historical reference only — do not implement it.
>
> **Status: ✅ Complete (superseded by Phase 2.1)**

---

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A minimal Cloudflare Worker (~200 lines) that protects API keys for Free/Trial/Pro Managed users. WordPress signs requests; proxy validates signatures and forwards to Anthropic. Enables Free/Trial/Pro Managed tiers to function.

**Architecture:** Simple TypeScript Worker with single endpoint `/v1/chat`. No auth, user management, or webhooks. WordPress handles all business logic and sends signed requests containing user_id, tier, and chat data. Server-to-server only — no browser CORS needed.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare KV (rate limiting only), Wrangler CLI, Web Crypto API (HMAC verification)

**Depends on:** Phase 1 complete (WordPress tier system working)

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Authentication | WordPress signs requests with HMAC | No external auth system needed |
| User Management | None — WordPress passes user_id in payload | Proxy is stateless |
| Rate Limiting | KV storage authoritative; WordPress pre-check is fail-fast optimisation | Double-check at edge + avoids unnecessary round-trips |
| API Key Protection | Only proxy purpose | 70% simpler than full microservices |
| Error Handling | Pass through to WordPress | WordPress handles upgrade UX |
| CORS | None — server-to-server only | WordPress PHP calls proxy, not a browser |
| Signature location | `X-WP-Signature` header only | Avoids double-signature in body; sign raw body string |
| Proxy URL config | `WP_AI_MIND_PROXY_URL` constant → `wp_options` fallback | No hardcoded URL; deployable without code change |
| Tiers in scope | `free`, `trial`, `pro_managed` | `pro_byok` bypasses proxy entirely (direct ClaudeProvider) |

### Acknowledged limitation

`NJ_Tier_Config::FEATURES` PHP constants can be edited on the filesystem by anyone with server access. Token rate limits are **not** affected (enforced by Cloudflare KV). Model selection enforcement lives in the Worker (`getModelForTier`) and cannot be bypassed by editing PHP. Feature flag hardening (LemonSqueezy signed tier certificate) is a tracked future phase — out of scope here.

---

## Project Structure

```
wp-ai-mind-proxy/
├── src/
│   ├── index.ts              # Main proxy logic (~150 lines)
│   ├── types.ts              # Simple interfaces
│   └── signature.ts          # HMAC verification (constant-time)
├── wrangler.toml             # Minimal Cloudflare config
├── package.json
├── tsconfig.json
└── README.md
```

---

## Task 0: Pre-implementation Audit

> **Mandatory.** Understand what already exists before creating proxy.

- [ ] **Step 0.1: Check if proxy project exists**
```bash
ls -la wp-ai-mind-proxy/ 2>/dev/null || echo "No existing proxy project"
# If files exist, audit them before proceeding
```

- [ ] **Step 0.2: Verify Phase 1 WordPress integration**
```bash
grep -rn "NJ_Tier_Manager\|NJ_Usage_Tracker" includes/ --include="*.php" | head -20
# Confirm WordPress tier system is in place before building proxy
```

---

## Task 1: Cloudflare Project Setup

**Files:** Create basic Cloudflare Worker project

- [ ] **Step 1.1: Create project directory**
```bash
mkdir -p wp-ai-mind-proxy/src
```

- [ ] **Step 1.2: Create package.json**
```json
{
  "name": "wp-ai-mind-proxy",
  "version": "0.1.0",
  "private": true,
  "scripts": {
    "dev": "wrangler dev",
    "deploy": "wrangler deploy",
    "typecheck": "tsc --noEmit"
  },
  "devDependencies": {
    "@cloudflare/workers-types": "^4.0.0",
    "typescript": "^5.0.0",
    "wrangler": "^3.0.0"
  }
}
```

- [ ] **Step 1.3: Create wrangler.toml**
```toml
name = "wp-ai-mind-proxy"
main = "src/index.ts"
compatibility_date = "2024-01-01"

[[kv_namespaces]]
binding = "USAGE_KV"
id = "REPLACE_AFTER_KV_CREATE"
preview_id = "REPLACE_AFTER_KV_CREATE_PREVIEW"

# Secrets (set via CLI — never commit):
# wrangler secret put ANTHROPIC_API_KEY
# wrangler secret put PROXY_SIGNATURE_SECRET
```

- [ ] **Step 1.4: Create tsconfig.json**
```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ES2022",
    "moduleResolution": "Bundler",
    "lib": ["ES2022"],
    "types": ["@cloudflare/workers-types"],
    "strict": true,
    "noEmit": true
  },
  "include": ["src/**/*.ts"]
}
```

---

## Task 2: Cloudflare Resources

**Prerequisites:** Cloudflare account, wrangler CLI installed

- [ ] **Step 2.1: Login to Cloudflare**
```bash
wrangler login
```

- [ ] **Step 2.2: Create KV namespace**
```bash
wrangler kv:namespace create USAGE_KV
# Copy ID to wrangler.toml [[kv_namespaces]] id

wrangler kv:namespace create USAGE_KV --preview
# Copy ID to wrangler.toml [[kv_namespaces]] preview_id
```

- [ ] **Step 2.3: Set secrets**
```bash
wrangler secret put ANTHROPIC_API_KEY
# Enter your Anthropic API key

wrangler secret put PROXY_SIGNATURE_SECRET
# Enter a random 64-character string — must match WP_AI_MIND_PROXY_SECRET in wp-config.php
```

---

## Task 3: Minimal Proxy Implementation

**Files:** Create `src/types.ts`, `src/signature.ts`, `src/index.ts`

- [ ] **Step 3.1: Create types interface**

```typescript
// src/types.ts

export interface Env {
  USAGE_KV: KVNamespace;
  ANTHROPIC_API_KEY: string;
  PROXY_SIGNATURE_SECRET: string;
}

// Tiers routed through proxy. Mirrors NJ_Tier_Config::TIERS (excludes pro_byok).
export type ProxyTier = 'free' | 'trial' | 'pro_managed';

export interface ProxyRequest {
  user_id: number;
  tier: ProxyTier;
  messages: MessageParam[];
  model?: string;
  max_tokens?: number;
}

export interface MessageParam {
  role: 'user' | 'assistant';
  content: string;
}
```

- [ ] **Step 3.2: Create signature verification**

```typescript
// src/signature.ts
//
// Signs the raw request body bytes — avoids JSON key-ordering fragility.
// Uses crypto.subtle.verify() for constant-time comparison (no timing oracle).

import { Env } from './types';

export async function verifySignature(
  bodyText: string,
  signature: string,
  env: Env
): Promise<boolean> {
  try {
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(env.PROXY_SIGNATURE_SECRET),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['verify']
    );
    const sigBytes = hexToBytes(signature);
    return await crypto.subtle.verify(
      'HMAC',
      key,
      sigBytes,
      new TextEncoder().encode(bodyText)
    );
  } catch {
    return false;
  }
}

function hexToBytes(hex: string): Uint8Array {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    out[i / 2] = parseInt(hex.slice(i, i + 2), 16);
  }
  return out;
}
```

- [ ] **Step 3.3: Create main proxy logic**

```typescript
// src/index.ts

import { Env, ProxyRequest, ProxyTier } from './types';
import { verifySignature } from './signature';

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (request.method !== 'POST') {
      return jsonResponse({ error: 'Method not allowed' }, 405);
    }

    const url = new URL(request.url);
    if (url.pathname === '/v1/chat') {
      return handleChatProxy(request, env);
    }

    return jsonResponse({ error: 'Not found' }, 404);
  },
};

async function handleChatProxy(request: Request, env: Env): Promise<Response> {
  try {
    // Read raw body before parsing — signature covers the exact bytes WordPress sent.
    const bodyText = await request.text();
    const signature = request.headers.get('X-WP-Signature') ?? '';

    if (!signature || !(await verifySignature(bodyText, signature, env))) {
      return jsonResponse({ error: 'Invalid signature' }, 401);
    }

    const body = JSON.parse(bodyText) as ProxyRequest;
    const { user_id, tier, messages, model, max_tokens } = body;

    // Validate tier is one we proxy for.
    const validTiers: ProxyTier[] = ['free', 'trial', 'pro_managed'];
    if (!validTiers.includes(tier)) {
      return jsonResponse({ error: 'Invalid tier for proxy' }, 400);
    }

    // Check rate limits in KV — authoritative enforcement.
    // WordPress pre-check is a fail-fast optimisation only.
    const rateLimitCheck = await checkRateLimit(user_id, tier, env);
    if (!rateLimitCheck.allowed) {
      return jsonResponse({
        error: 'Rate limit exceeded',
        used: rateLimitCheck.used,
        limit: rateLimitCheck.limit,
      }, 429);
    }

    // Enforce model selection per tier regardless of what WordPress sent.
    // Mirrors NJ_Tier_Config::FEATURES['model_selection'] enforcement.
    const selectedModel = getModelForTier(tier, model);

    const anthropicResponse = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'x-api-key': env.ANTHROPIC_API_KEY,
        'anthropic-version': '2023-06-01',
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        model: selectedModel,
        max_tokens: max_tokens ?? (tier === 'free' ? 1000 : 4000),
        messages,
      }),
    });

    const result = await anthropicResponse.json() as Record<string, unknown>;

    // Update KV usage counter after successful response.
    if (anthropicResponse.ok && result.usage) {
      const usage = result.usage as { input_tokens: number; output_tokens: number };
      const tokensUsed = usage.input_tokens + usage.output_tokens;
      await updateUsage(user_id, tokensUsed, env);
    }

    return new Response(JSON.stringify(result), {
      status: anthropicResponse.status,
      headers: { 'Content-Type': 'application/json' },
    });

  } catch (error) {
    console.error('Proxy error:', error);
    return jsonResponse({ error: 'Internal proxy error' }, 500);
  }
}

// Mirrors NJ_Tier_Config::MONTHLY_LIMITS (PHP). Keep in sync.
const MONTHLY_LIMITS: Record<ProxyTier, number> = {
  free:        50_000,
  trial:       300_000,
  pro_managed: 2_000_000,
};

async function checkRateLimit(
  userId: number,
  tier: ProxyTier,
  env: Env
): Promise<{ allowed: boolean; used: number; limit: number }> {
  const limit = MONTHLY_LIMITS[tier];
  const monthKey = `usage:${userId}:${getCurrentMonth()}`;
  const currentUsage = parseInt(await env.USAGE_KV.get(monthKey) ?? '0', 10);

  return { allowed: currentUsage < limit, used: currentUsage, limit };
}

async function updateUsage(userId: number, tokens: number, env: Env): Promise<void> {
  const monthKey = `usage:${userId}:${getCurrentMonth()}`;
  const currentUsage = parseInt(await env.USAGE_KV.get(monthKey) ?? '0', 10);
  await env.USAGE_KV.put(monthKey, String(currentUsage + tokens), {
    expirationTtl: getSecondsUntilNextMonth(),
  });
}

// Mirrors ClaudeProvider::MODELS (PHP). Keep in sync.
const TIER_MODELS: Record<ProxyTier, string[]> = {
  free:        ['claude-haiku-4-5-20251001'],
  trial:       ['claude-haiku-4-5-20251001'],
  pro_managed: ['claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-6'],
};

function getModelForTier(tier: ProxyTier, requestedModel?: string): string {
  const allowed = TIER_MODELS[tier];
  if (requestedModel && allowed.includes(requestedModel)) {
    return requestedModel;
  }
  return allowed[0];
}

function getCurrentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function getSecondsUntilNextMonth(): number {
  const now = new Date();
  const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  return Math.floor((next.getTime() - now.getTime()) / 1000);
}

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
```

---

## Task 4: WordPress Integration

**Files:** Create `includes/Proxy/NJ_Proxy_Client.php`

- [ ] **Step 4.1: Implement WordPress proxy client**

Key design points:
- `get_proxy_url()` reads `WP_AI_MIND_PROXY_URL` constant first, falls back to `get_option('wp_ai_mind_proxy_url')`
- Signature covers raw `wp_json_encode($payload)` bytes, sent in `X-WP-Signature` header only (not embedded in body)
- `NJ_Usage_Tracker::check_limit()` (not `check_rate_limit()`) is the correct method name
- On success, `NJ_Usage_Tracker::log_usage()` mirrors usage locally for dashboard display only — KV is authoritative
- No `direct_chat()` method — Pro BYOK routing is Phase 3

```php
<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Proxy_Client {

    private static function get_proxy_url(): string {
        return defined( 'WP_AI_MIND_PROXY_URL' )
            ? WP_AI_MIND_PROXY_URL
            : (string) get_option( 'wp_ai_mind_proxy_url', '' );
    }

    public static function chat( array $messages, array $options = [] ): array|WP_Error {
        $url = self::get_proxy_url();
        if ( empty( $url ) ) {
            return new WP_Error( 'proxy_not_configured', __( 'Proxy URL not configured.', 'wp-ai-mind' ) );
        }

        $user_id = get_current_user_id();
        $tier    = NJ_Tier_Manager::get_user_tier( $user_id );

        // Fail-fast pre-check (WordPress meta). Cloudflare KV is the authoritative limit.
        if ( ! NJ_Usage_Tracker::check_limit( $user_id ) ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
        }

        $payload = [
            'user_id'    => $user_id,
            'tier'       => $tier,
            'messages'   => $messages,
            'model'      => $options['model'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
        ];

        $body_json = wp_json_encode( $payload );
        $signature = self::sign( $body_json );

        $response = wp_remote_post(
            trailingslashit( $url ) . 'v1/chat',
            [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'X-WP-Signature' => $signature,
                ],
                'body'    => $body_json,
                'timeout' => 60,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

        if ( $code === 429 ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'proxy_error', $body['error'] ?? "Proxy returned HTTP {$code}" );
        }

        // Mirror usage locally for dashboard display. KV is authoritative for enforcement.
        if ( isset( $body['usage'] ) ) {
            $tokens = (int) $body['usage']['input_tokens'] + (int) $body['usage']['output_tokens'];
            NJ_Usage_Tracker::log_usage( $tokens, $user_id );
        }

        return $body;
    }

    private static function sign( string $body ): string {
        if ( ! defined( 'WP_AI_MIND_PROXY_SECRET' ) || '' === WP_AI_MIND_PROXY_SECRET ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WP AI Mind] WP_AI_MIND_PROXY_SECRET is not defined in wp-config.php' );
        }
        $secret = defined( 'WP_AI_MIND_PROXY_SECRET' ) ? WP_AI_MIND_PROXY_SECRET : '';
        return hash_hmac( 'sha256', $body, $secret );
    }
}
```

- [ ] **Step 4.2: Add constants to wp-config.php**

```php
// In wp-config.php (same value as PROXY_SIGNATURE_SECRET set in Cloudflare)
define( 'WP_AI_MIND_PROXY_SECRET', 'your-64-char-random-string' );
define( 'WP_AI_MIND_PROXY_URL',    'https://wp-ai-mind-proxy.YOUR-ACCOUNT.workers.dev' );
```

---

## Task 5: Local Testing & Deployment

- [ ] **Step 5.1: TypeScript type-check**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [ ] **Step 5.2: Local development**
```bash
cd wp-ai-mind-proxy && wrangler dev
# Test at http://localhost:8787
```

- [ ] **Step 5.3: Smoke test — missing signature → 401**
```bash
curl -s -X POST http://localhost:8787/v1/chat \
  -H "Content-Type: application/json" \
  -d '{"user_id":1,"tier":"free","messages":[]}' | jq .
# Expected: {"error":"Invalid signature"}
```

- [ ] **Step 5.4: Verify trial tier gets correct limit**

In `wrangler dev` logs, check `checkRateLimit()` with `tier='trial'` returns `limit: 300000` (not 0).

- [ ] **Step 5.5: Deploy to Cloudflare**
```bash
cd wp-ai-mind-proxy && wrangler deploy
# Note the deployed URL; set WP_AI_MIND_PROXY_URL in wp-config.php
```

---

## Task 6: Integration Testing

- [ ] **Step 6.1: Test Free tier (50k limit, Haiku only)**
- [ ] **Step 6.2: Test Trial tier (300k limit, Haiku only)**
- [ ] **Step 6.3: Test Pro Managed tier (2M limit, Haiku/Sonnet/Opus)**
- [ ] **Step 6.4: Test invalid signature → 401**
- [ ] **Step 6.5: Test exceeded rate limit → 429**
- [ ] **Step 6.6: Verify Pro BYOK tier is NOT sent to proxy**

---

## Phase 2 Acceptance Criteria

- [ ] Cloudflare Worker deployed at `wp-ai-mind-proxy.YOUR.workers.dev`
- [ ] Single endpoint `/v1/chat` handles requests from WordPress
- [ ] HMAC signature verification (constant-time) prevents unauthorised access
- [ ] Rate limiting via KV: `free` 50k, `trial` 300k, `pro_managed` 2M tokens/month
- [ ] Model enforcement: free/trial = Haiku only; pro_managed = Haiku/Sonnet/Opus
- [ ] Model IDs match `ClaudeProvider::MODELS`: `claude-haiku-4-5-20251001`, `claude-sonnet-4-6`, `claude-opus-4-6`
- [ ] Proxy URL configurable via `WP_AI_MIND_PROXY_URL` constant or `wp_options` (no hardcoded URL)
- [ ] Signature in `X-WP-Signature` header only; body is plain JSON (not duplicated)
- [ ] No CORS headers (server-to-server only)
- [ ] Error handling returns appropriate HTTP status codes
- [ ] `NJ_Proxy_Client::chat()` works end-to-end with signed requests

**After Phase 2: Free/Trial/Pro Managed use proxy. Pro BYOK uses direct API calls (Phase 3).**
