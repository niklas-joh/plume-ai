# Phase 3: Cloudflare Worker — Chat Proxy + LemonSqueezy Webhooks

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Worker can receive authenticated chat requests, enforce plan token limits via KV, forward to Anthropic, and handle LemonSqueezy subscription webhooks to update D1 plan state. No WordPress plugin changes in this phase.

**Architecture:** Implements `POST /v1/chat` (real Anthropic proxy) and `POST /webhooks/lemonsqueezy` (HMAC-verified plan update) in the existing `wp-ai-mind-proxy/` project. Replaces the Phase 1 501 stubs.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare KV (usage tracking), Cloudflare D1 (plan updates), Web Crypto (HMAC-SHA256 webhook signature verification), Anthropic API

**Depends on:** Phase 1 complete (auth endpoints live, D1 schema exists)
**Runs in parallel with:** Phase 2 (PHP auth layer)

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Token counting | Read KV before request, write after response | TOCTOU race is acceptable — soft-cap approach; a few over-limit requests are harmless |
| KV key TTL | Set to seconds until end of current month | KV handles cleanup automatically; no cron job needed |
| Response passthrough | Return Anthropic response body verbatim | Wire format is identical — PHP `NJ_Proxy_Client` (Phase 4) needs zero adaptation |
| Webhook verification | Constant-time HMAC comparison | Prevents timing attacks on signature validation |
| Pro users | Skip KV limit check entirely | Pro users route direct (Phase 4); Worker still accepts their tokens if they call directly |

---

## File Map

**Modified files (in `wp-ai-mind-proxy/`):**
- `src/chat.ts` — replace 501 stub with real Anthropic proxy + KV usage tracking
- `src/webhooks.ts` — replace 501 stub with HMAC-verified LemonSqueezy handler
- `test/chat.test.ts` — new test file
- `test/webhooks.test.ts` — new test file

---

## Task 1: Chat Proxy

**Files:** Replace `src/chat.ts` stub, create `test/chat.test.ts`

- [ ] **Step 1.1: Write failing tests** (`test/chat.test.ts`)

```typescript
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { handleChat } from '../src/chat';
import type { Env, AuthUser } from '../src/types';

const trialUser: AuthUser = { sub: 1, email: 'a@b.com', plan: 'trial', iat: 0, exp: 9999999999 };
const freeUser:  AuthUser = { sub: 2, email: 'b@c.com', plan: 'free',  iat: 0, exp: 9999999999 };
const proUser:   AuthUser = { sub: 3, email: 'c@d.com', plan: 'pro',   iat: 0, exp: 9999999999 };

function makeEnv(usedTokens: number, fetchResponse: Response): Env {
  return {
    USAGE_KV: {
      get: vi.fn().mockResolvedValue(usedTokens > 0 ? String(usedTokens) : null),
      put: vi.fn().mockResolvedValue(undefined),
    },
    ANTHROPIC_API_KEY: 'test-key',
  } as unknown as Env;
}

function makeRequest(body: unknown): Request {
  return new Request('http://localhost/v1/chat', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

describe('handleChat — token limit enforcement', () => {
  it('returns 429 when free user is at token limit', async () => {
    const env = makeEnv(50_000, new Response('{}', { status: 200 }));
    globalThis.fetch = vi.fn();

    const res = await handleChat(
      makeRequest({ model: 'claude-haiku-4-5', messages: [] }),
      env,
      freeUser
    );

    expect(res.status).toBe(429);
    const body = await res.json() as Record<string, unknown>;
    expect(body.error).toBe('token_limit_exceeded');
    expect(body.limit).toBe(50_000);
    // fetch should NOT be called when limit is exceeded
    expect(globalThis.fetch).not.toHaveBeenCalled();
  });

  it('returns 429 when trial user is at token limit', async () => {
    const env = makeEnv(300_000, new Response('{}', { status: 200 }));
    globalThis.fetch = vi.fn();

    const res = await handleChat(
      makeRequest({ model: 'claude-haiku-4-5', messages: [] }),
      env,
      trialUser
    );

    expect(res.status).toBe(429);
  });

  it('does not enforce limit for pro users', async () => {
    const anthropicBody = JSON.stringify({
      content: [{ type: 'text', text: 'hello' }],
      usage: { input_tokens: 100, output_tokens: 50 }
    });
    globalThis.fetch = vi.fn().mockResolvedValue(
      new Response(anthropicBody, { status: 200, headers: { 'Content-Type': 'application/json' } })
    );
    const env = makeEnv(999_999, new Response('{}'));

    const res = await handleChat(
      makeRequest({ model: 'claude-opus-4-7', messages: [] }),
      env,
      proUser
    );

    // fetch should be called (no limit check for pro)
    expect(globalThis.fetch).toHaveBeenCalledOnce();
  });
});
```

Run: `npm test` → Expected: FAIL (chat module returns 501)

- [ ] **Step 1.2: Implement `src/chat.ts`**

```typescript
import { yyyyMM, nextMonthStart, secondsUntilMonthEnd, json } from './utils';
import type { Env, AuthUser } from './types';

const PLAN_LIMITS: Record<string, number | null> = {
  free:  50_000,
  trial: 300_000,
  pro:   null,
};

export async function handleChat(req: Request, env: Env, user: AuthUser): Promise<Response> {
  const limit = PLAN_LIMITS[user.plan] ?? null;

  // Step 1: Enforce token limits for free/trial plans.
  if (limit !== null) {
    const monthKey = `usage:${user.sub}:${yyyyMM()}`;
    const usedStr  = await env.USAGE_KV.get(monthKey);
    const used     = usedStr ? parseInt(usedStr, 10) : 0;

    if (used >= limit) {
      return json({
        error:     'token_limit_exceeded',
        used,
        limit,
        resets_at: nextMonthStart(),
      }, 429);
    }
  }

  // Step 2: Forward request to Anthropic.
  const body = await req.json<Record<string, unknown>>();

  const anthropicResp = await fetch('https://api.anthropic.com/v1/messages', {
    method:  'POST',
    headers: {
      'x-api-key':         env.ANTHROPIC_API_KEY,
      'anthropic-version': '2023-06-01',
      'content-type':      'application/json',
    },
    body: JSON.stringify(body),
  });

  // Step 3: Update KV usage asynchronously after successful response.
  if (anthropicResp.ok && limit !== null) {
    const cloned  = anthropicResp.clone();
    const data    = await cloned.json<{ usage?: { input_tokens?: number; output_tokens?: number } }>();
    const tokens  = (data.usage?.input_tokens ?? 0) + (data.usage?.output_tokens ?? 0);

    if (tokens > 0) {
      const monthKey = `usage:${user.sub}:${yyyyMM()}`;
      const current  = parseInt((await env.USAGE_KV.get(monthKey)) ?? '0', 10);
      await env.USAGE_KV.put(monthKey, String(current + tokens), {
        expirationTtl: secondsUntilMonthEnd(),
      });
    }
  }

  // Step 4: Return Anthropic response verbatim (body passthrough).
  return new Response(anthropicResp.body, {
    status:  anthropicResp.status,
    headers: {
      'Content-Type': anthropicResp.headers.get('Content-Type') ?? 'application/json',
    },
  });
}
```

- [ ] **Step 1.3: Run tests to verify they pass**

```bash
npm test
# Expected: All chat.test.ts tests PASS
```

- [ ] **Step 1.4: Commit**

```bash
git add src/chat.ts test/chat.test.ts
git commit -m "feat(proxy): implement POST /v1/chat with Anthropic proxy and KV usage tracking"
```

---

## Task 2: LemonSqueezy Webhook Handler

**Files:** Replace `src/webhooks.ts` stub, create `test/webhooks.test.ts`

- [ ] **Step 2.1: Write failing tests** (`test/webhooks.test.ts`)

```typescript
import { describe, it, expect, vi } from 'vitest';
import { handleLemonSqueezy } from '../src/webhooks';
import type { Env } from '../src/types';

async function makeSignedRequest(body: unknown, secret: string): Promise<Request> {
  const bodyStr = JSON.stringify(body);
  const key     = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sigBytes = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(bodyStr)));
  const sig      = Array.from(sigBytes).map(b => b.toString(16).padStart(2, '0')).join('');

  return new Request('http://localhost/webhooks/lemonsqueezy', {
    method:  'POST',
    headers: { 'Content-Type': 'application/json', 'X-Signature': sig },
    body:    bodyStr,
  });
}

const WEBHOOK_SECRET = 'test-webhook-secret-value';

function makeEnv(): Env {
  return {
    DB: {
      prepare: vi.fn().mockReturnValue({
        bind: vi.fn().mockReturnThis(),
        run:  vi.fn().mockResolvedValue({ success: true }),
      }),
    },
    LEMONSQUEEZY_WEBHOOK_SECRET: WEBHOOK_SECRET,
  } as unknown as Env;
}

describe('handleLemonSqueezy', () => {
  it('returns 401 for invalid signature', async () => {
    const req = new Request('http://localhost/webhooks/lemonsqueezy', {
      method:  'POST',
      headers: { 'X-Signature': 'badhash' },
      body:    JSON.stringify({ meta: { event_name: 'order_created' } }),
    });
    const res = await handleLemonSqueezy(req, makeEnv());
    expect(res.status).toBe(401);
  });

  it('upgrades user to pro on subscription_created', async () => {
    const env  = makeEnv();
    const body = {
      meta: { event_name: 'subscription_created' },
      data: { attributes: { user_email: 'user@example.com' } },
    };
    const req = await makeSignedRequest(body, WEBHOOK_SECRET);
    const res = await handleLemonSqueezy(req, env);

    expect(res.status).toBe(200);
    expect(env.DB.prepare).toHaveBeenCalledWith(
      expect.stringContaining("plan = 'pro'")
    );
  });

  it('downgrades user to free on subscription_cancelled', async () => {
    const env  = makeEnv();
    const body = {
      meta: { event_name: 'subscription_cancelled' },
      data: { attributes: { user_email: 'user@example.com' } },
    };
    const req = await makeSignedRequest(body, WEBHOOK_SECRET);
    const res = await handleLemonSqueezy(req, env);

    expect(res.status).toBe(200);
    expect(env.DB.prepare).toHaveBeenCalledWith(
      expect.stringContaining("plan = 'free'")
    );
  });

  it('returns 200 for unknown event types (graceful)', async () => {
    const env  = makeEnv();
    const body = { meta: { event_name: 'some_other_event' }, data: {} };
    const req  = await makeSignedRequest(body, WEBHOOK_SECRET);
    const res  = await handleLemonSqueezy(req, env);
    expect(res.status).toBe(200);
  });
});
```

Run: `npm test` → Expected: FAIL (webhooks module returns 501)

- [ ] **Step 2.2: Implement `src/webhooks.ts`**

```typescript
import { hex, json } from './utils';
import type { Env } from './types';

const UPGRADE_EVENTS   = ['order_created', 'subscription_created', 'subscription_resumed'];
const DOWNGRADE_EVENTS = ['subscription_cancelled', 'subscription_expired'];

export async function handleLemonSqueezy(req: Request, env: Env): Promise<Response> {
  const sig  = req.headers.get('X-Signature') ?? '';
  const body = await req.text();

  // Verify HMAC-SHA256 signature.
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(env.LEMONSQUEEZY_WEBHOOK_SECRET),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const computed = hex(new Uint8Array(
    await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body))
  ));

  // Constant-time comparison to prevent timing attacks.
  if (!constantTimeEqual(computed, sig)) {
    return json({ error: 'Invalid signature' }, 401);
  }

  const event = JSON.parse(body) as {
    meta?: { event_name?: string };
    data?: { attributes?: { user_email?: string } };
  };

  const eventName = event?.meta?.event_name ?? '';
  const email     = event?.data?.attributes?.user_email?.toLowerCase().trim();

  if (email) {
    if (UPGRADE_EVENTS.includes(eventName)) {
      await env.DB.prepare(
        `UPDATE users SET plan = 'pro', plan_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE email = ?`
      ).bind(email).run();
    } else if (DOWNGRADE_EVENTS.includes(eventName)) {
      await env.DB.prepare(
        `UPDATE users SET plan = 'free', plan_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE email = ?`
      ).bind(email).run();
    }
    // Unknown events are silently accepted — LemonSqueezy expects 200 for all events.
  }

  return json({ received: true });
}

function constantTimeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
}
```

- [ ] **Step 2.3: Run full test suite**

```bash
npm test
# Expected: All tests PASS (jwt, password, auth, entitlement, chat, webhooks)
```

- [ ] **Step 2.4: Commit**

```bash
git add src/webhooks.ts test/webhooks.test.ts
git commit -m "feat(proxy): implement LemonSqueezy webhook handler with HMAC verification"
```

---

## Task 3: Deploy and Smoke Test

- [ ] **Step 3.1: Deploy updated Worker**

```bash
wrangler deploy
# Expected: ✓ Deployed to https://wp-ai-mind-proxy.YOUR.workers.dev
```

- [ ] **Step 3.2: Smoke test chat endpoint with a real API key**

```bash
BASE="https://wp-ai-mind-proxy.YOUR.workers.dev"

# Get an access token first
TOKEN=$(curl -s -X POST $BASE/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke@example.com","password":"smoketest123"}' | jq -r .access_token)

# Send a minimal chat request
curl -X POST $BASE/v1/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{
    "model": "claude-haiku-4-5",
    "max_tokens": 50,
    "messages": [{"role": "user", "content": "Say hello in one word."}]
  }' | jq .
# Expected: Anthropic response with content array
```

- [ ] **Step 3.3: Smoke test limit enforcement**

```bash
# Insert a user at their limit directly in D1 (for testing only)
wrangler d1 execute wp-ai-mind --remote --command \
  "UPDATE users SET plan = 'free' WHERE email = 'smoke@example.com';"

# Set KV usage to limit
wrangler kv:key put --binding=USAGE_KV "usage:USER_ID:$(date +%Y-%m)" "50000"

# Attempt chat — should be 429
curl -X POST $BASE/v1/chat \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"model":"claude-haiku-4-5","max_tokens":10,"messages":[{"role":"user","content":"hi"}]}' | jq .
# Expected: {"error":"token_limit_exceeded","used":50000,"limit":50000,...}
```

- [ ] **Step 3.4: Test LemonSqueezy webhook with test delivery**

In LemonSqueezy dashboard → Webhooks → Send test event for `subscription_created`.
Verify D1 user plan is updated to `pro`:
```bash
wrangler d1 execute wp-ai-mind --remote --command "SELECT plan FROM users WHERE email = 'your@email.com';"
# Expected: plan = 'pro'
```

- [ ] **Step 3.5: Commit**

```bash
git commit -m "chore(proxy): Phase 3 complete — chat proxy and webhooks deployed"
```

---

## Phase 3 Acceptance Criteria

- [ ] `POST /v1/chat` with valid JWT + within limits → Anthropic response returned
- [ ] `POST /v1/chat` at token limit → 429 with `error: 'token_limit_exceeded'`
- [ ] KV key `usage:{id}:{YYYY-MM}` increments after each successful chat
- [ ] KV key TTL expires at end of month (not a fixed duration from now)
- [ ] `POST /webhooks/lemonsqueezy` with correct HMAC → D1 plan updated to `pro`
- [ ] `POST /webhooks/lemonsqueezy` with incorrect HMAC → 401
- [ ] `subscription_cancelled` event → D1 plan updated to `free`
- [ ] All unit tests pass: `npm test`

---

## Phase 3 Risk Notes

- **KV TOCTOU race:** Two simultaneous requests from the same user could both pass the limit check before either writes usage. This results in slightly over-limit requests — acceptable for a soft cap.
- **Streaming response buffering:** The current implementation buffers the full Anthropic response before returning it to the plugin. This means the PHP side won't see incremental output until Phase 7 (real SSE streaming). For the initial release this is acceptable.
- **Worker Paid plan required for production streaming.** The Cloudflare Workers Free plan has a 10ms CPU time limit per request. Proxying an Anthropic response that takes 10–30 seconds requires the Paid plan (no CPU time limit on I/O-bound work). Ensure the Cloudflare account is on a Paid plan before going live.
- **KV `put` with `expirationTtl`** sets a TTL from the moment of write. Since `secondsUntilMonthEnd()` is computed fresh on each write, this correctly aligns with calendar month end regardless of when in the month the write occurs.
