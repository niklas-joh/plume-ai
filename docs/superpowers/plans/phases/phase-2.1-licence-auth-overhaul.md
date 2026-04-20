# Phase 2.1: Licence Auth & Zero-Friction Activation

> **Status: ✅ Tasks 1–12 complete — PR #208 (`feat/phase-2.1-licence-auth` → `feat/api-overhaul`) open, pending merge.**
> Task 13 (end-to-end Docker smoke test) is intentionally deferred to post-merge.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace HMAC shared-secret proxy auth with site-token Bearer auth; add LemonSqueezy webhook handling to the Cloudflare Worker; enable zero-friction activation (plugin auto-registers on first activation, paid upgrades apply automatically via webhook); wire `NJ_Proxy_Client` into `ClaudeProvider`; fix trial period to 30 days.

**Architecture:** The plugin auto-registers with the Worker on first `init` and receives a site token stored in `wp_options` — no user action required. All proxy requests authenticate with `Authorization: Bearer <token>`. LemonSqueezy webhooks fire to the Worker `/webhook` route, which upgrades the site token's tier in KV. `ClaudeProvider::complete()` checks the user's tier and routes free/trial/pro_managed requests through `NJ_Proxy_Client` and pro_byok requests directly to Anthropic. The WordPress DB tier mirrors KV for local dashboard display and fail-fast checks; KV remains authoritative.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare KV, WordPress PHP, LemonSqueezy Webhooks API

**Depends on:** Phase 2 complete — Worker deployed at `https://wp-ai-mind-proxy.wp-ai-mind.workers.dev`, KV namespaces created, `ANTHROPIC_API_KEY` and `LS_WEBHOOK_SECRET` secrets set.

---

## Design Decisions

| Decision | Choice | Reason |
|---|---|---|
| Auth | Bearer site token | No shared secret distributed in plugin code |
| Token generation | Worker `/register` | Centralised; no collision risk |
| Rate limiting scope | Per site token per month | Multi-user WP sites share the tier's allowance |
| Tier upgrade | LS webhook → Worker upgrades token in KV | Zero user action after purchase |
| Checkout URL | Plugin embeds `site_token` in LS checkout custom data | LS passes it back in webhook for automatic site association |
| Pro BYOK routing | Bypasses proxy, direct Anthropic call | No token cost; user's own key |
| Double-logging prevention | `NJ_Proxy_Client::chat()` logs locally; `AbstractProvider::maybe_log()` only runs for direct calls | `ClaudeProvider::complete()` short-circuits before `parent::complete()` for proxy tiers |
| Stale token recovery | 401 from Worker → delete token → re-register on next `init` | Silent recovery; user sees one error, next request succeeds |
| Old WP webhook | Disabled in `Plugin.php`; file kept for rollback | Webhook is now handled by Worker |
| `system` prompt | Forwarded in proxy payload | Required for ClaudeProvider parity |

---

## KV Schema

| Key | Value | Purpose |
|---|---|---|
| `site:<token>` | `{ site_url, tier, created_at, ls_licence_key? }` | Site record + tier |
| `site_url:<sha256(url)>` | `<token>` | Reverse lookup for idempotent re-registration |
| `licence:<ls_key>` | `{ tier, site_token, activated_at }` | Licence → site mapping for webhook downgrade |
| `usage:<token>:<YYYY-MM>` | `<number>` | Monthly token usage per site |

---

## File Map

### Cloudflare Worker (`wp-ai-mind-proxy/`)

| File | Action | Responsibility |
|---|---|---|
| `src/types.ts` | Modify | Add `SiteRecord`, `LicenceRecord`; add `system` to `ProxyRequest`; remove `PROXY_SIGNATURE_SECRET` from `Env` |
| `src/auth.ts` | **Create** | `authenticateRequest()` — reads `site:<token>` from KV |
| `src/registration.ts` | **Create** | `/register` — issues site token; idempotent |
| `src/webhook.ts` | **Create** | `/webhook` — verifies LS signature; upgrades/downgrades tier in KV |
| `src/signature.ts` | Modify | Repurpose for LS webhook HMAC only; rename function to `verifyLsSignature` |
| `src/index.ts` | Modify | Add `/register` + `/webhook` routes; replace HMAC with Bearer auth; forward `system` to Anthropic |

### WordPress Plugin (`includes/`)

| File | Action | Responsibility |
|---|---|---|
| `includes/Tiers/NJ_Tier_Config.php` | Modify | `TRIAL_DAYS` 7 → 30; add `PROXY_URL` constant |
| `includes/Proxy/NJ_Site_Registration.php` | **Create** | Auto-register on `init`; generate checkout URLs with embedded site token |
| `includes/Proxy/NJ_Proxy_Client.php` | Modify | Use Bearer token; remove `sign()`; add `system` to payload |
| `includes/Providers/ClaudeProvider.php` | Modify | Override `complete()` to route by tier; make `parse_response()` protected |
| `includes/Core/Plugin.php` | Modify | Hook `NJ_Site_Registration::maybe_register()`; disable old WP webhook |

### Tests

| File | Action |
|---|---|
| `tests/Unit/Tiers/NJTierManagerTest.php` | Modify — update trial duration test to 30 days |
| `tests/Unit/Proxy/NJSiteRegistrationTest.php` | **Create** |
| `tests/Unit/Proxy/NJProxyClientTest.php` | **Create** |
| `tests/Unit/Providers/ClaudeProviderTest.php` | Modify — add tier routing tests |

---

## Task 1: Fix Trial Period (7 → 30 Days)

**Files:**
- Modify: `includes/Tiers/NJ_Tier_Config.php:46`
- Modify: `tests/Unit/Tiers/NJTierManagerTest.php`

- [x] **Step 1.1: Update the test first**

In `tests/Unit/Tiers/NJTierManagerTest.php`, rename `test_is_trial_active_returns_false_after_seven_days` and update the offset. Also add a 29-day active assertion:

```php
public function test_is_trial_active_returns_false_after_thirty_days(): void {
    $user_id = 1;
    update_user_meta( $user_id, 'wp_ai_mind_trial_started', time() - ( 31 * DAY_IN_SECONDS ) );
    update_user_meta( $user_id, 'wp_ai_mind_tier', 'trial' );

    $this->assertFalse( NJ_Tier_Manager::is_trial_active( $user_id ) );
}

public function test_is_trial_active_returns_true_within_thirty_days(): void {
    $user_id = 1;
    update_user_meta( $user_id, 'wp_ai_mind_trial_started', time() - ( 29 * DAY_IN_SECONDS ) );
    update_user_meta( $user_id, 'wp_ai_mind_tier', 'trial' );

    $this->assertTrue( NJ_Tier_Manager::is_trial_active( $user_id ) );
}
```

- [x] **Step 1.2: Run — expect failure**
```bash
./vendor/bin/phpunit tests/Unit/Tiers/NJTierManagerTest.php --colors=always
```
Expected: `test_is_trial_active_returns_false_after_thirty_days` FAILS (31 days not expired under the 7-day constant).

- [x] **Step 1.3: Update the constant**

In `includes/Tiers/NJ_Tier_Config.php`, change:
```php
const TRIAL_DAYS = 7;
```
to:
```php
const TRIAL_DAYS = 30;
```

- [x] **Step 1.4: Run — expect pass**
```bash
./vendor/bin/phpunit tests/Unit/Tiers/NJTierManagerTest.php --colors=always
```

- [x] **Step 1.5: Commit**
```bash
git add includes/Tiers/NJ_Tier_Config.php tests/Unit/Tiers/NJTierManagerTest.php
git commit -m "fix(tiers): extend Pro Trial period from 7 to 30 days"
```

---

## Task 2: Add PROXY_URL Constant to NJ_Tier_Config

**Files:**
- Modify: `includes/Tiers/NJ_Tier_Config.php`

This keeps the proxy URL in one place — both `NJ_Site_Registration` and `NJ_Proxy_Client` reference it.

- [x] **Step 2.1: Add constant**

In `includes/Tiers/NJ_Tier_Config.php`, add alongside the existing constants:

```php
const PROXY_URL = 'https://wp-ai-mind-proxy.wp-ai-mind.workers.dev';
```

- [x] **Step 2.2: Run full suite — expect no regressions**
```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

- [x] **Step 2.3: Commit**
```bash
git add includes/Tiers/NJ_Tier_Config.php
git commit -m "chore(tiers): add PROXY_URL constant"
```

---

## Task 3: Worker — Update Types

**Files:**
- Modify: `wp-ai-mind-proxy/src/types.ts`

- [x] **Step 3.1: Replace types.ts**

```typescript
// src/types.ts

export interface Env {
  USAGE_KV: KVNamespace;
  ANTHROPIC_API_KEY: string;
  LS_WEBHOOK_SECRET: string;
  // PROXY_SIGNATURE_SECRET intentionally removed — replaced by site token Bearer auth.
}

export type ProxyTier = 'free' | 'trial' | 'pro_managed';

export interface SiteRecord {
  site_url: string;
  tier: ProxyTier;
  created_at: number;
  ls_licence_key?: string;
}

export interface LicenceRecord {
  tier: ProxyTier;
  site_token: string;
  activated_at: number;
}

export interface ProxyRequest {
  user_id: number; // kept for logging only — rate limiting is per site token
  tier: ProxyTier;
  messages: MessageParam[];
  model?: string;
  max_tokens?: number;
  system?: string;
}

export interface MessageParam {
  role: 'user' | 'assistant';
  content: string;
}
```

- [x] **Step 3.2: Typecheck**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [x] **Step 3.3: Commit**
```bash
git add wp-ai-mind-proxy/src/types.ts
git commit -m "chore(proxy): update types for site-token auth, KV schema, system prompt"
```

---

## Task 4: Worker — Bearer Token Auth Module

**Files:**
- Create: `wp-ai-mind-proxy/src/auth.ts`

- [x] **Step 4.1: Create auth.ts**

```typescript
// src/auth.ts

import { Env, SiteRecord, ProxyTier } from './types';

export interface AuthResult {
  authenticated: boolean;
  site_token?: string;
  tier?: ProxyTier;
  site_url?: string;
}

export async function authenticateRequest(
  request: Request,
  env: Env
): Promise<AuthResult> {
  const authHeader = request.headers.get('Authorization') ?? '';
  if (!authHeader.startsWith('Bearer ')) {
    return { authenticated: false };
  }

  const token = authHeader.slice(7).trim();
  if (!token) {
    return { authenticated: false };
  }

  const record = await env.USAGE_KV.get<SiteRecord>(`site:${token}`, 'json');
  if (!record) {
    return { authenticated: false };
  }

  return {
    authenticated: true,
    site_token: token,
    tier: record.tier,
    site_url: record.site_url,
  };
}

export function generateToken(): string {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);
  return Array.from(bytes)
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}
```

- [x] **Step 4.2: Typecheck**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [x] **Step 4.3: Commit**
```bash
git add wp-ai-mind-proxy/src/auth.ts
git commit -m "feat(proxy): add Bearer token auth module"
```

---

## Task 5: Worker — Site Registration Endpoint

**Files:**
- Create: `wp-ai-mind-proxy/src/registration.ts`

- [x] **Step 5.1: Create registration.ts**

```typescript
// src/registration.ts

import { Env, SiteRecord } from './types';
import { generateToken } from './auth';

export async function handleRegistration(
  request: Request,
  env: Env
): Promise<Response> {
  if (request.method !== 'POST') {
    return jsonResponse({ error: 'Method not allowed' }, 405);
  }

  let body: { site_url?: string };
  try {
    body = await request.json() as { site_url?: string };
  } catch {
    return jsonResponse({ error: 'Invalid JSON' }, 400);
  }

  const site_url = (body.site_url ?? '').trim();
  if (!site_url || !isValidUrl(site_url)) {
    return jsonResponse({ error: 'Invalid site_url' }, 400);
  }

  // Idempotent — return the existing token if already registered.
  const urlHash = await sha256(site_url);
  const existingToken = await env.USAGE_KV.get(`site_url:${urlHash}`);
  if (existingToken) {
    const record = await env.USAGE_KV.get<SiteRecord>(`site:${existingToken}`, 'json');
    if (record) {
      return jsonResponse({ token: existingToken, tier: record.tier });
    }
  }

  const token = generateToken();
  const record: SiteRecord = { site_url, tier: 'free', created_at: Date.now() };

  await env.USAGE_KV.put(`site:${token}`, JSON.stringify(record));
  await env.USAGE_KV.put(`site_url:${urlHash}`, token);

  return jsonResponse({ token, tier: 'free' }, 201);
}

async function sha256(input: string): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return Array.from(new Uint8Array(bytes))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

function isValidUrl(url: string): boolean {
  try {
    const { protocol } = new URL(url);
    return protocol === 'http:' || protocol === 'https:';
  } catch {
    return false;
  }
}

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
```

- [x] **Step 5.2: Typecheck**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [x] **Step 5.3: Commit**
```bash
git add wp-ai-mind-proxy/src/registration.ts
git commit -m "feat(proxy): add /register endpoint — idempotent site token issuance"
```

---

## Task 6: Worker — LemonSqueezy Webhook Endpoint

**Files:**
- Modify: `wp-ai-mind-proxy/src/signature.ts`
- Create: `wp-ai-mind-proxy/src/webhook.ts`

- [x] **Step 6.1: Repurpose signature.ts for LS webhook verification**

Replace the full contents of `src/signature.ts`:

```typescript
// src/signature.ts
// Verifies LemonSqueezy webhook signatures sent in the X-Signature header.
// Uses constant-time comparison to prevent timing attacks.

import { Env } from './types';

export async function verifyLsSignature(
  bodyText: string,
  signature: string,
  env: Env
): Promise<boolean> {
  try {
    if (!signature || signature.length % 2 !== 0 || !/^[0-9a-f]+$/i.test(signature)) {
      return false;
    }
    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(env.LS_WEBHOOK_SECRET),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['verify']
    );
    const sigBytes = new Uint8Array(signature.length / 2);
    for (let i = 0; i < signature.length; i += 2) {
      sigBytes[i / 2] = parseInt(signature.slice(i, i + 2), 16);
    }
    return await crypto.subtle.verify('HMAC', key, sigBytes, new TextEncoder().encode(bodyText));
  } catch {
    return false;
  }
}
```

- [x] **Step 6.2: Get your LemonSqueezy variant IDs**

In the LemonSqueezy dashboard go to **Products → WP AI Mind Pro → Variants**. For each variant, open it and copy the numeric **Variant ID** from the URL or the variant settings. You need:
- Pro Monthly variant ID
- Pro Annual variant ID

You will paste these into the `VARIANT_TIER_MAP` in the next step.

- [x] **Step 6.3: Create webhook.ts**

Replace the variant IDs in the map below. The Pro Monthly variant ID is **988108**.
The Pro Annual variant ID must be confirmed from the LS dashboard (Products → WP AI Mind Pro → Variants → Annual → copy the numeric ID from the URL).
Pro BYOK (1550517) is a one-time purchase that bypasses the proxy — it is not in this map; BYOK webhook handling is Phase 3 scope.

```typescript
// src/webhook.ts

import { Env, SiteRecord, LicenceRecord, ProxyTier } from './types';
import { verifyLsSignature } from './signature';

// Map LemonSqueezy variant IDs → plugin tier.
// Pro Monthly: 988108. Pro Annual: confirm ID in LS dashboard (Products → WP AI Mind Pro → Variants).
// Pro BYOK (1550517) is not here — it bypasses the proxy; handled in Phase 3.
const VARIANT_TIER_MAP: Record<string, ProxyTier> = {
  '988108': 'pro_managed', // Pro Monthly
  'CONFIRM_PRO_ANNUAL_VARIANT_ID': 'pro_managed', // Pro Annual — replace with actual ID
};

export async function handleWebhook(request: Request, env: Env): Promise<Response> {
  if (request.method !== 'POST') {
    return new Response('Method not allowed', { status: 405 });
  }

  const bodyText = await request.text();
  const signature = request.headers.get('X-Signature') ?? '';

  if (!(await verifyLsSignature(bodyText, signature, env))) {
    return new Response('Invalid signature', { status: 401 });
  }

  let payload: Record<string, unknown>;
  try {
    payload = JSON.parse(bodyText) as Record<string, unknown>;
  } catch {
    return new Response('Invalid JSON', { status: 400 });
  }

  const meta = payload['meta'] as Record<string, unknown> | undefined;
  const eventName = (meta?.['event_name'] as string | undefined) ?? '';

  switch (eventName) {
    case 'order_created':
    case 'subscription_created':
    case 'subscription_resumed':
    case 'subscription_unpaused':
      await handleActivation(payload, env);
      break;

    case 'subscription_cancelled':
    case 'subscription_expired':
    case 'subscription_paused':
      await handleDeactivation(payload, env);
      break;

    case 'licence_key_created':
    case 'licence_key_updated':
      await handleLicenceKey(payload, env);
      break;

    default:
      break; // acknowledge unknown events without processing
  }

  return new Response('OK', { status: 200 });
}

async function handleActivation(payload: Record<string, unknown>, env: Env): Promise<void> {
  const meta = payload['meta'] as Record<string, unknown> | undefined;
  const customData = meta?.['custom_data'] as Record<string, string> | undefined;
  const site_token = customData?.['site_token'];
  if (!site_token) return;

  const data = payload['data'] as Record<string, unknown> | undefined;
  const attrs = data?.['attributes'] as Record<string, unknown> | undefined;
  const variantId = String(
    ((attrs?.['first_order_item'] ?? attrs?.['variant_id']) as unknown) ?? ''
  );
  const tier = VARIANT_TIER_MAP[variantId] ?? null;
  if (!tier) return;

  const licenceKey = (attrs?.['identifier'] as string | undefined) ?? '';
  await upgradeSiteTier(site_token, tier, licenceKey, env);
}

async function handleDeactivation(payload: Record<string, unknown>, env: Env): Promise<void> {
  const data = payload['data'] as Record<string, unknown> | undefined;
  const attrs = data?.['attributes'] as Record<string, unknown> | undefined;
  const licenceKey = (attrs?.['licence_key'] ?? attrs?.['identifier']) as string | undefined;
  if (!licenceKey) return;

  const record = await env.USAGE_KV.get<LicenceRecord>(`licence:${licenceKey}`, 'json');
  if (record?.site_token) {
    await downgradeSiteTier(record.site_token, env);
  }
}

async function handleLicenceKey(payload: Record<string, unknown>, env: Env): Promise<void> {
  const data = payload['data'] as Record<string, unknown> | undefined;
  const attrs = data?.['attributes'] as Record<string, unknown> | undefined;
  const key = (attrs?.['key'] as string | undefined) ?? '';
  const status = (attrs?.['status'] as string | undefined) ?? '';
  const meta = payload['meta'] as Record<string, unknown> | undefined;
  const customData = meta?.['custom_data'] as Record<string, string> | undefined;
  const site_token = customData?.['site_token'];

  if (!key || !site_token) return;

  if (status === 'active') {
    const variantId = String((attrs?.['variant_id'] as unknown) ?? '');
    const tier = VARIANT_TIER_MAP[variantId] ?? 'pro_managed';
    const record: LicenceRecord = { tier, site_token, activated_at: Date.now() };
    await env.USAGE_KV.put(`licence:${key}`, JSON.stringify(record));
  } else if (status === 'disabled' || status === 'expired') {
    const record = await env.USAGE_KV.get<LicenceRecord>(`licence:${key}`, 'json');
    if (record) await downgradeSiteTier(record.site_token, env);
    await env.USAGE_KV.delete(`licence:${key}`);
  }
}

async function upgradeSiteTier(
  token: string, tier: ProxyTier, licenceKey: string, env: Env
): Promise<void> {
  const existing = await env.USAGE_KV.get<SiteRecord>(`site:${token}`, 'json');
  if (!existing) return;
  const updated: SiteRecord = { ...existing, tier, ...(licenceKey ? { ls_licence_key: licenceKey } : {}) };
  await env.USAGE_KV.put(`site:${token}`, JSON.stringify(updated));

  if (licenceKey) {
    const lr: LicenceRecord = { tier, site_token: token, activated_at: Date.now() };
    await env.USAGE_KV.put(`licence:${licenceKey}`, JSON.stringify(lr));
  }
}

async function downgradeSiteTier(token: string, env: Env): Promise<void> {
  const existing = await env.USAGE_KV.get<SiteRecord>(`site:${token}`, 'json');
  if (!existing) return;
  const { ls_licence_key: _removed, ...rest } = existing;
  await env.USAGE_KV.put(`site:${token}`, JSON.stringify({ ...rest, tier: 'free' }));
}
```

- [x] **Step 6.4: Typecheck**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [x] **Step 6.5: Commit**
```bash
git add wp-ai-mind-proxy/src/signature.ts wp-ai-mind-proxy/src/webhook.ts
git commit -m "feat(proxy): add /webhook endpoint for LemonSqueezy events"
```

---

## Task 7: Worker — Update index.ts (Routes + Bearer Auth + System Prompt)

**Files:**
- Modify: `wp-ai-mind-proxy/src/index.ts`
- Modify: `wp-ai-mind-proxy/wrangler.toml`

- [x] **Step 7.1: Replace index.ts**

```typescript
// src/index.ts

import { Env, ProxyRequest, ProxyTier } from './types';
import { authenticateRequest } from './auth';
import { handleRegistration } from './registration';
import { handleWebhook } from './webhook';

const MAX_BODY_BYTES = 1_048_576; // 1 MB

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (request.method !== 'POST') {
      return jsonResponse({ error: 'Method not allowed' }, 405);
    }

    const { pathname } = new URL(request.url);

    if (pathname === '/register') return handleRegistration(request, env);
    if (pathname === '/webhook')  return handleWebhook(request, env);
    if (pathname === '/v1/chat')  return handleChatProxy(request, env);

    return jsonResponse({ error: 'Not found' }, 404);
  },
};

async function handleChatProxy(request: Request, env: Env): Promise<Response> {
  try {
    const auth = await authenticateRequest(request, env);
    if (!auth.authenticated || !auth.site_token || !auth.tier) {
      return jsonResponse({ error: 'Unauthorised' }, 401);
    }

    const contentLength = Number(request.headers.get('Content-Length') ?? 0);
    if (contentLength > MAX_BODY_BYTES) {
      return jsonResponse({ error: 'Request too large' }, 413);
    }

    const bodyText = await request.text();
    if (new TextEncoder().encode(bodyText).length > MAX_BODY_BYTES) {
      return jsonResponse({ error: 'Request too large' }, 413);
    }

    const body = JSON.parse(bodyText) as ProxyRequest;
    const { messages, model, max_tokens, system } = body;
    const { site_token, tier } = auth;

    const rateLimitCheck = await checkRateLimit(site_token, tier, env);
    if (!rateLimitCheck.allowed) {
      return jsonResponse({
        error: 'Rate limit exceeded',
        used: rateLimitCheck.used,
        limit: rateLimitCheck.limit,
      }, 429);
    }

    const selectedModel = getModelForTier(tier, model);
    const maxTokens = Math.min(
      max_tokens ?? (tier === 'free' ? 1000 : 4000),
      MAX_TOKENS[tier]
    );

    const anthropicBody: Record<string, unknown> = {
      model: selectedModel,
      max_tokens: maxTokens,
      messages,
    };
    if (system) anthropicBody['system'] = system;

    const anthropicResponse = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'x-api-key': env.ANTHROPIC_API_KEY,
        'anthropic-version': '2023-06-01',
        'content-type': 'application/json',
      },
      body: JSON.stringify(anthropicBody),
    });

    const result = await anthropicResponse.json() as Record<string, unknown>;

    if (anthropicResponse.ok && result['usage']) {
      const usage = result['usage'] as { input_tokens: number; output_tokens: number };
      await updateUsage(site_token, usage.input_tokens + usage.output_tokens, env);
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

const MAX_TOKENS: Record<ProxyTier, number> = {
  free:        1_000,
  trial:       4_000,
  pro_managed: 8_000,
};

async function checkRateLimit(
  siteToken: string, tier: ProxyTier, env: Env
): Promise<{ allowed: boolean; used: number; limit: number }> {
  const limit = MONTHLY_LIMITS[tier];
  const key = `usage:${siteToken}:${getCurrentMonth()}`;
  const used = parseInt(await env.USAGE_KV.get(key) ?? '0', 10);
  return { allowed: used < limit, used, limit };
}

async function updateUsage(siteToken: string, tokens: number, env: Env): Promise<void> {
  const key = `usage:${siteToken}:${getCurrentMonth()}`;
  const current = parseInt(await env.USAGE_KV.get(key) ?? '0', 10);
  await env.USAGE_KV.put(key, String(current + tokens), {
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
  if (requestedModel && allowed.includes(requestedModel)) return requestedModel;
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

- [x] **Step 7.2: Update wrangler.toml secrets comment**

In `wp-ai-mind-proxy/wrangler.toml`, replace the secrets comment block:

```toml
# Secrets (set via CLI — never commit values):
# wrangler secret put ANTHROPIC_API_KEY
# wrangler secret put LS_WEBHOOK_SECRET   ← must match LemonSqueezy webhook signing secret
# PROXY_SIGNATURE_SECRET is no longer used and can be deleted:
# wrangler secret delete PROXY_SIGNATURE_SECRET
```

- [x] **Step 7.3: Typecheck**
```bash
cd wp-ai-mind-proxy && npx tsc --noEmit
```

- [x] **Step 7.4: Smoke test — missing token → 401**
```bash
wrangler dev &
sleep 3
curl -s -X POST http://localhost:8787/v1/chat \
  -H "Content-Type: application/json" \
  -d '{"user_id":1,"tier":"free","messages":[]}' | jq .
# Expected: {"error":"Unauthorised"}

curl -s -X POST http://localhost:8787/register \
  -H "Content-Type: application/json" \
  -d '{"site_url":"https://test.example.com"}' | jq .
# Expected: {"token":"<64-char hex>","tier":"free"} with HTTP 201

kill %1
```

- [x] **Step 7.5: Commit**
```bash
git add wp-ai-mind-proxy/src/index.ts wp-ai-mind-proxy/wrangler.toml
git commit -m "feat(proxy): wire /register + /webhook routes; replace HMAC with Bearer auth"
```

---

## Task 8: Worker — Deploy

- [x] **Step 8.1: Deploy**
```bash
cd wp-ai-mind-proxy && wrangler deploy
```
Expected: `Deployed wp-ai-mind-proxy` — `https://wp-ai-mind-proxy.wp-ai-mind.workers.dev`

- [x] **Step 8.2: Smoke test registration (production)**
```bash
# First call — expect 201
curl -s -o /dev/null -w "%{http_code}" -X POST \
  https://wp-ai-mind-proxy.wp-ai-mind.workers.dev/register \
  -H "Content-Type: application/json" \
  -d '{"site_url":"https://test.example.com"}'
# Expected: 201

# Second call same URL — expect 200 (idempotent)
curl -s -o /dev/null -w "%{http_code}" -X POST \
  https://wp-ai-mind-proxy.wp-ai-mind.workers.dev/register \
  -H "Content-Type: application/json" \
  -d '{"site_url":"https://test.example.com"}'
# Expected: 200
```

- [x] **Step 8.3: Commit**
```bash
git add wp-ai-mind-proxy/
git commit -m "chore(proxy): deploy updated Worker with Bearer auth, /register, /webhook"
```

---

## Task 9: WordPress — NJ_Site_Registration

**Files:**
- Create: `includes/Proxy/NJ_Site_Registration.php`
- Create: `tests/Unit/Proxy/NJSiteRegistrationTest.php`

- [x] **Step 9.1: Write failing test**

Create `tests/Unit/Proxy/NJSiteRegistrationTest.php`:

```php
<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use WP_AI_Mind\Proxy\NJ_Site_Registration;
use WP_Mock\Tools\TestCase;

class NJSiteRegistrationTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_get_site_token_returns_stored_token(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( 'abc123' );

        $this->assertSame( 'abc123', NJ_Site_Registration::get_site_token() );
    }

    public function test_get_site_token_returns_empty_string_when_not_registered(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( '' );

        $this->assertSame( '', NJ_Site_Registration::get_site_token() );
    }

    public function test_is_registered_returns_true_when_token_exists(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( 'some-token' );

        $this->assertTrue( NJ_Site_Registration::is_registered() );
    }

    public function test_is_registered_returns_false_when_no_token(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( '' );

        $this->assertFalse( NJ_Site_Registration::is_registered() );
    }

    public function test_checkout_url_embeds_site_token(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( 'mytoken' );

        $url = NJ_Site_Registration::checkout_url( 'variant-abc' );

        $this->assertStringContainsString( 'variant-abc', $url );
        $this->assertStringContainsString( 'mytoken', $url );
    }
}
```

- [x] **Step 9.2: Run — expect failure**
```bash
./vendor/bin/phpunit tests/Unit/Proxy/NJSiteRegistrationTest.php --colors=always
```
Expected: FAIL — class not found.

- [x] **Step 9.3: Create NJ_Site_Registration.php**

Create `includes/Proxy/NJ_Site_Registration.php`:

```php
<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Config;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Site_Registration {

    private const OPTION_TOKEN = 'wp_ai_mind_site_token';

    public static function get_site_token(): string {
        return (string) get_option( self::OPTION_TOKEN, '' );
    }

    public static function is_registered(): bool {
        return '' !== self::get_site_token();
    }

    /**
     * Register with the proxy Worker if not already registered.
     * Idempotent — skips if a token is already stored.
     * Hooked to `init` in Plugin.php.
     */
    public static function maybe_register(): void {
        if ( self::is_registered() ) {
            return;
        }

        $result = self::register();
        if ( is_wp_error( $result ) ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[WP AI Mind] Site registration failed: ' . $result->get_error_message() );
        }
    }

    public static function register(): string|WP_Error {
        $response = wp_remote_post(
            NJ_Tier_Config::PROXY_URL . '/register',
            [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'site_url' => home_url() ] ),
                'timeout' => 15,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

        if ( ( $code !== 200 && $code !== 201 ) || empty( $body['token'] ) ) {
            return new WP_Error( 'registration_failed', "Proxy registration returned HTTP {$code}" );
        }

        update_option( self::OPTION_TOKEN, sanitize_text_field( $body['token'] ) );
        return $body['token'];
    }

    /**
     * Build a LemonSqueezy checkout URL for the given variant ID,
     * embedding the site token in custom data so the Worker can associate
     * the purchase with this site automatically (zero-friction activation).
     */
    public static function checkout_url( string $variant_id ): string {
        $token = self::get_site_token();
        $url   = 'https://wp-ai-mind.lemonsqueezy.com/checkout/buy/' . rawurlencode( $variant_id );
        if ( $token ) {
            $url .= '?checkout[custom][site_token]=' . rawurlencode( $token );
        }
        return $url;
    }
}
```

- [x] **Step 9.4: Run — expect pass**
```bash
./vendor/bin/phpunit tests/Unit/Proxy/NJSiteRegistrationTest.php --colors=always
```

- [x] **Step 9.5: Run full suite**
```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

- [x] **Step 9.6: Commit**
```bash
git add includes/Proxy/NJ_Site_Registration.php tests/Unit/Proxy/NJSiteRegistrationTest.php
git commit -m "feat(proxy): add NJ_Site_Registration — auto-register on activation, checkout URL helper"
```

---

## Task 10: WordPress — Update NJ_Proxy_Client (Bearer Token)

**Files:**
- Modify: `includes/Proxy/NJ_Proxy_Client.php`
- Create: `tests/Unit/Proxy/NJProxyClientTest.php`

- [x] **Step 10.1: Write failing test**

Create `tests/Unit/Proxy/NJProxyClientTest.php`:

```php
<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_Mock\Tools\TestCase;

class NJProxyClientTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        \WP_Mock::setUp();
    }

    public function tearDown(): void {
        \WP_Mock::tearDown();
        parent::tearDown();
    }

    public function test_chat_returns_error_when_not_registered(): void {
        \WP_Mock::userFunction( 'get_option' )
            ->with( 'wp_ai_mind_site_token', '' )
            ->andReturn( '' );

        $result = NJ_Proxy_Client::chat( [] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'not_registered', $result->get_error_code() );
    }
}
```

- [x] **Step 10.2: Run — expect failure**
```bash
./vendor/bin/phpunit tests/Unit/Proxy/NJProxyClientTest.php --colors=always
```

- [x] **Step 10.3: Replace NJ_Proxy_Client.php**

```php
<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Config;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Proxy_Client {

    public static function chat( array $messages, array $options = [] ): array|WP_Error {
        $token = NJ_Site_Registration::get_site_token();
        if ( empty( $token ) ) {
            return new WP_Error( 'not_registered', __( 'Site not registered with AI proxy.', 'wp-ai-mind' ) );
        }

        $user_id = get_current_user_id();

        // Fail-fast pre-check (WordPress meta). KV is authoritative.
        if ( ! NJ_Usage_Tracker::check_limit( $user_id ) ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
        }

        $payload = array_filter( [
            'user_id'    => $user_id,
            'tier'       => NJ_Tier_Manager::get_user_tier( $user_id ),
            'messages'   => $messages,
            'model'      => $options['model'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
            'system'     => $options['system'] ?? null,
        ], fn( $v ) => null !== $v );

        $response = wp_remote_post(
            NJ_Tier_Config::PROXY_URL . '/v1/chat',
            [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body'    => wp_json_encode( $payload ),
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

        if ( $code === 401 ) {
            // Token may be stale — clear it so maybe_register() re-issues on next init.
            delete_option( 'wp_ai_mind_site_token' );
            return new WP_Error( 'proxy_auth_failed', __( 'Proxy authentication failed. Please try again.', 'wp-ai-mind' ) );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'proxy_error', $body['error'] ?? "Proxy returned HTTP {$code}" );
        }

        // Mirror usage locally for dashboard display. KV is authoritative for enforcement.
        if ( isset( $body['usage'] ) ) {
            NJ_Usage_Tracker::log_usage(
                (int) $body['usage']['input_tokens'] + (int) $body['usage']['output_tokens'],
                $user_id
            );
        }

        return $body;
    }
}
```

- [x] **Step 10.4: Run — expect pass**
```bash
./vendor/bin/phpunit tests/Unit/Proxy/ --colors=always
```

- [x] **Step 10.5: Commit**
```bash
git add includes/Proxy/NJ_Proxy_Client.php tests/Unit/Proxy/NJProxyClientTest.php
git commit -m "feat(proxy): use Bearer token in NJ_Proxy_Client; remove HMAC signing"
```

---

## Task 11: WordPress — Tier-Based Routing in ClaudeProvider

**Files:**
- Modify: `includes/Providers/ClaudeProvider.php`
- Modify: `tests/Unit/Providers/ClaudeProviderTest.php`

The integration strategy: override `complete()` in `ClaudeProvider` to intercept proxy tiers before the parent's retry/logging machinery. This prevents double-logging — `NJ_Proxy_Client::chat()` handles local usage logging for proxy tiers; `AbstractProvider::maybe_log()` handles it for pro_byok (direct calls). Also make `parse_response()` protected so the proxy path can reuse it.

- [x] **Step 11.1: Write failing tests**

In `tests/Unit/Providers/ClaudeProviderTest.php`, add:

```php
public function test_complete_routes_free_tier_through_proxy(): void {
    \WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );
    \WP_Mock::userFunction( 'get_user_meta' )
        ->with( 1, 'wp_ai_mind_tier', true )
        ->andReturn( 'free' );
    \WP_Mock::userFunction( 'get_option' )
        ->with( 'wp_ai_mind_site_token', '' )
        ->andReturn( 'site-token-abc' );
    \WP_Mock::userFunction( 'get_option' )
        ->with( 'wp_ai_mind_usage_' . date( 'Y_m' ), 0 )
        ->andReturn( 0 );

    // Proxy HTTP call returns a valid Anthropic-shaped response.
    \WP_Mock::userFunction( 'wp_remote_post' )
        ->andReturn( [
            'response' => [ 'code' => 200 ],
            'body'     => wp_json_encode( [
                'model'   => 'claude-haiku-4-5-20251001',
                'content' => [ [ 'type' => 'text', 'text' => 'Hello' ] ],
                'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
            ] ),
        ] );
    \WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    \WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
        wp_json_encode( [
            'model'   => 'claude-haiku-4-5-20251001',
            'content' => [ [ 'type' => 'text', 'text' => 'Hello' ] ],
            'usage'   => [ 'input_tokens' => 10, 'output_tokens' => 5 ],
        ] )
    );

    $provider = new \WP_AI_Mind\Providers\ClaudeProvider( '' );
    $request  = new \WP_AI_Mind\Providers\CompletionRequest(
        messages: [ [ 'role' => 'user', 'content' => 'Hi' ] ],
        max_tokens: 100,
    );

    $response = $provider->complete( $request );
    $this->assertSame( 'Hello', $response->content );
}
```

- [x] **Step 11.2: Run — expect failure**
```bash
./vendor/bin/phpunit tests/Unit/Providers/ClaudeProviderTest.php --colors=always
```

- [x] **Step 11.3: Make parse_response() protected**

In `includes/Providers/ClaudeProvider.php`, change:
```php
private function parse_response(
```
to:
```php
protected function parse_response(
```

- [x] **Step 11.4: Override complete() and add complete_via_proxy()**

Add these two methods to `ClaudeProvider`, before `do_complete()`:

```php
public function complete( CompletionRequest $request ): CompletionResponse {
    $tier = \WP_AI_Mind\Tiers\NJ_Tier_Manager::get_user_tier( get_current_user_id() );

    if ( in_array( $tier, [ 'free', 'trial', 'pro_managed' ], true ) ) {
        return $this->complete_via_proxy( $request );
    }

    return parent::complete( $request ); // pro_byok — direct call with retry + logging
}

private function complete_via_proxy( CompletionRequest $request ): CompletionResponse {
    $result = \WP_AI_Mind\Proxy\NJ_Proxy_Client::chat(
        $request->messages,
        array_filter( [
            'model'      => $request->model ?: null,
            'max_tokens' => $request->max_tokens,
            'system'     => $request->system !== '' ? $request->system : null,
        ], fn( $v ) => null !== $v )
    );

    if ( is_wp_error( $result ) ) {
        throw new ProviderException( $result->get_error_message(), 'claude' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    return $this->parse_response( $result, $request );
}
```

Also add at the top of the class with the existing `use` declarations (if not already present):
```php
use WP_AI_Mind\Proxy\NJ_Proxy_Client;
```

- [x] **Step 11.5: Run — expect pass**
```bash
./vendor/bin/phpunit tests/Unit/Providers/ClaudeProviderTest.php --colors=always
```

- [x] **Step 11.6: Run full suite**
```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

- [x] **Step 11.7: Lint**
```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/Providers/ClaudeProvider.php
```

- [x] **Step 11.8: Commit**
```bash
git add includes/Providers/ClaudeProvider.php tests/Unit/Providers/ClaudeProviderTest.php
git commit -m "feat(providers): route free/trial/pro_managed through proxy; pro_byok direct"
```

---

## Task 12: WordPress — Update Plugin.php

**Files:**
- Modify: `includes/Core/Plugin.php`

- [x] **Step 12.1: Hook registration; disable old webhook**

In `includes/Core/Plugin.php`:

1. Add `use WP_AI_Mind\Proxy\NJ_Site_Registration;` with the other `use` statements.

2. In the method that registers `init` hooks (wherever other `add_action( 'init', ... )` calls live), add:
```php
add_action( 'init', [ NJ_Site_Registration::class, 'maybe_register' ] );
```

3. Find the line that calls `NJ_LemonSqueezy_Webhook::register_routes()` and disable it:
```php
// Deprecated in Phase 2.1: LemonSqueezy webhook is now handled by the Cloudflare Worker.
// NJ_LemonSqueezy_Webhook::register_routes();
```

- [x] **Step 12.2: Run full suite**
```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

- [x] **Step 12.3: Lint**
```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/Core/Plugin.php
```

- [x] **Step 12.4: Commit**
```bash
git add includes/Core/Plugin.php
git commit -m "feat(core): hook site auto-registration on init; disable WordPress webhook endpoint"
```

---

## Task 13: End-to-End Smoke Test

- [ ] **Step 13.1: Start local environment**
```bash
docker compose up -d
```

- [ ] **Step 13.2: Verify auto-registration fires**

Deactivate and reactivate the plugin from WP admin (or clear the option manually):
```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "DELETE FROM wp_options WHERE option_name = 'wp_ai_mind_site_token';"
```
Then visit any admin page (triggers `init`). Then check:
```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "SELECT option_value FROM wp_options WHERE option_name = 'wp_ai_mind_site_token';"
```
Expected: a 64-character hex token.

- [ ] **Step 13.3: Verify free tier request routes through proxy**

Tail the Worker logs in a separate terminal:
```bash
cd wp-ai-mind-proxy && wrangler tail
```
Make a chat request via the WP admin as a free-tier user. Confirm the Worker receives a `POST /v1/chat` with `Authorization: Bearer <token>` and responds (or rate-limits).

- [ ] **Step 13.4: Verify pro_byok bypasses proxy**

Set a test user's tier to `pro_byok` and make a chat request. The Worker tail should show **no** `/v1/chat` call — the request goes directly to Anthropic.

- [ ] **Step 13.5: Verify stale token recovery**

Manually corrupt the stored token:
```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "UPDATE wp_options SET option_value = 'invalid' WHERE option_name = 'wp_ai_mind_site_token';"
```
Make a chat request — expect a user-facing error. Reload any admin page (triggers `init` → `maybe_register()` → re-issues token). Make the chat request again — should succeed.

---

## Phase 2.1 Acceptance Criteria

- [x] `NJ_Tier_Config::TRIAL_DAYS === 30`
- [x] Plugin auto-registers with Worker on `init` — no user action required
- [x] Site token stored in `wp_options`; re-issued automatically if stale or missing
- [x] `/register` is idempotent — same site URL always returns the same token
- [x] `/v1/chat` accepts `Authorization: Bearer <token>`; rejects requests without a valid token with HTTP 401
- [x] `/webhook` verifies `X-Signature` from LemonSqueezy; upgrades/downgrades site token tier in KV on purchase/cancellation events
- [x] Free/Trial/Pro Managed chat requests route through `NJ_Proxy_Client` → Worker → Anthropic
- [x] Pro BYOK chat requests bypass the proxy and call Anthropic directly
- [x] LemonSqueezy checkout URLs embed site token via `NJ_Site_Registration::checkout_url()` — purchase upgrades tier automatically, no user action needed
- [x] Stale/invalid token clears itself and re-registers on next `init`
- [x] Old WordPress `/wp-json/wp-ai-mind/v1/webhook` endpoint is disabled
- [x] `./vendor/bin/phpunit tests/Unit/ --colors=always` — all pass
- [x] `npx tsc --noEmit` in `wp-ai-mind-proxy/` — no errors
- [x] `./vendor/bin/phpcs --standard=phpcs.xml.dist` — no violations on modified files
