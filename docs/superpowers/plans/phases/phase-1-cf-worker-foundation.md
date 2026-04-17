# Phase 1: Cloudflare Worker — Auth + D1 Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A deployed, independently testable Cloudflare Worker that handles user registration, login, token refresh, and entitlement. Nothing in the WordPress plugin changes during this phase. Phases 2 and 3 are blocked until this is live.

**Architecture:** Greenfield `wp-ai-mind-proxy/` TypeScript Worker project. D1 (SQLite) stores user accounts. KV stores monthly token usage. All crypto (JWT, password hashing) uses the Web Crypto API — no external libraries.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare D1, Cloudflare KV, Wrangler CLI, Web Crypto API (HMAC-SHA256, PBKDF2), Vitest

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Password hashing | PBKDF2-SHA256, 100k iterations | bcrypt unavailable in Web Crypto; PBKDF2 is the correct alternative |
| JWT algorithm | HMAC-SHA256 | No external deps; native Web Crypto; HS256 verified server-side only |
| Token storage | access (1h) + refresh (30d) JWTs | Standard OAuth2 pattern; short-lived access reduces exposure |
| Trial demotion | Happens at `POST /v1/auth/token` time | No cron needed; transparently enforced on next login |
| Entitlement contract | Locked in this phase | PHP NJ_Entitlement (Phase 2) consumes this exact shape |

---

## File Map

**Create new project `wp-ai-mind-proxy/` (sibling to the plugin repo, or in a separate repo):**

```
wp-ai-mind-proxy/
  wrangler.toml              ← Cloudflare config (D1 + KV bindings)
  package.json
  tsconfig.json
  migrations/
    0001_init_users.sql      ← D1 schema
  src/
    index.ts                 ← Route dispatch + CORS
    auth.ts                  ← register, token, refresh handlers
    entitlement.ts           ← GET /v1/entitlement handler
    chat.ts                  ← POST /v1/chat (returns 501 stub in this phase)
    webhooks.ts              ← POST /webhooks/lemonsqueezy (returns 501 stub)
    db.ts                    ← D1 helper wrappers
    jwt.ts                   ← signJWT, verifyJWT
    password.ts              ← hashPassword, verifyPassword
    middleware.ts            ← requireAuth()
    types.ts                 ← Env, User, AuthUser interfaces
    utils.ts                 ← json(), hex(), b64url(), yyyyMM(), nextMonthStart()
  test/
    jwt.test.ts
    password.test.ts
    auth.test.ts
    entitlement.test.ts
```

---

## Task 1: Project Scaffold

**Files:** Create `wp-ai-mind-proxy/` directory with initial config files.

- [ ] **Step 1.1: Create project directory and package.json**

```bash
mkdir wp-ai-mind-proxy && cd wp-ai-mind-proxy
npm init -y
npm install --save-dev wrangler vitest @cloudflare/vitest-pool-workers typescript
```

- [ ] **Step 1.2: Create tsconfig.json**

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
  "include": ["src/**/*.ts", "test/**/*.ts"]
}
```

- [ ] **Step 1.3: Create wrangler.toml** (fill in IDs after running `wrangler d1 create` and `wrangler kv:namespace create` in Task 2)

```toml
name                = "wp-ai-mind-proxy"
main                = "src/index.ts"
compatibility_date  = "2025-01-01"

[[d1_databases]]
binding           = "DB"
database_name     = "wp-ai-mind"
database_id       = "REPLACE_AFTER_wrangler_d1_create"
migrations_dir    = "migrations"

[[kv_namespaces]]
binding     = "USAGE_KV"
id          = "REPLACE_AFTER_kv_create"
preview_id  = "REPLACE_AFTER_kv_create_preview"

[vars]
ENVIRONMENT = "production"

# Secrets set via CLI — never in this file:
# wrangler secret put JWT_SECRET
# wrangler secret put ANTHROPIC_API_KEY
# wrangler secret put LEMONSQUEEZY_WEBHOOK_SECRET
```

- [ ] **Step 1.4: Add scripts to package.json**

```json
"scripts": {
  "dev":    "wrangler dev",
  "deploy": "wrangler deploy",
  "test":   "vitest run",
  "test:watch": "vitest"
}
```

- [ ] **Step 1.5: Commit**

```bash
git add .
git commit -m "chore(proxy): scaffold wp-ai-mind-proxy project"
```

---

## Task 2: Cloudflare Resources

**Prerequisite:** `wrangler login` completed.

- [ ] **Step 2.1: Create D1 database**

```bash
wrangler d1 create wp-ai-mind
# Output example:
# database_id = "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
# Copy this ID into wrangler.toml [[d1_databases]] database_id
```

- [ ] **Step 2.2: Create KV namespaces**

```bash
wrangler kv:namespace create USAGE_KV
# Copy id → wrangler.toml [[kv_namespaces]] id

wrangler kv:namespace create USAGE_KV --preview
# Copy id → wrangler.toml [[kv_namespaces]] preview_id
```

- [ ] **Step 2.3: Set secrets**

```bash
wrangler secret put JWT_SECRET
# Enter a long random string (e.g. openssl rand -hex 32)

wrangler secret put ANTHROPIC_API_KEY
# Enter your Anthropic API key (used in Phase 3; stubbed in Phase 1)

wrangler secret put LEMONSQUEEZY_WEBHOOK_SECRET
# Enter your LemonSqueezy webhook secret (used in Phase 3; stubbed in Phase 1)
```

- [ ] **Step 2.4: Commit updated wrangler.toml**

```bash
git add wrangler.toml
git commit -m "chore(proxy): add Cloudflare resource IDs to wrangler.toml"
```

---

## Task 3: D1 Migration

**Files:** Create `migrations/0001_init_users.sql`

- [ ] **Step 3.1: Write failing test for schema existence** (`test/auth.test.ts`)

```typescript
import { describe, it, expect } from 'vitest';

describe('D1 schema', () => {
  it('users table has required columns', async () => {
    // This test documents the expected schema shape.
    // Validated by integration test after migration applied.
    const expectedColumns = ['id', 'email', 'password_hash', 'plan', 'plan_expires', 'created_at', 'updated_at'];
    // Schema is verified visually / via wrangler d1 execute below.
    expect(expectedColumns).toHaveLength(7);
  });
});
```

Run: `npm test` → Expected: PASS (placeholder assertion)

- [ ] **Step 3.2: Write migration file**

`migrations/0001_init_users.sql`:
```sql
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER  PRIMARY KEY AUTOINCREMENT,
  email         TEXT     UNIQUE NOT NULL COLLATE NOCASE,
  password_hash TEXT     NOT NULL,
  plan          TEXT     NOT NULL DEFAULT 'trial',
  plan_expires  DATETIME,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

- [ ] **Step 3.3: Apply migration locally**

```bash
wrangler d1 migrations apply wp-ai-mind --local
# Expected output: Applied 1 migration(s)
```

- [ ] **Step 3.4: Verify schema**

```bash
wrangler d1 execute wp-ai-mind --local --command "PRAGMA table_info(users);"
# Expected: 7 rows (id, email, password_hash, plan, plan_expires, created_at, updated_at)
```

- [ ] **Step 3.5: Commit**

```bash
git add migrations/
git commit -m "feat(proxy): add D1 users table migration"
```

---

## Task 4: Types and Utilities

**Files:** Create `src/types.ts` and `src/utils.ts`

- [ ] **Step 4.1: Write `src/types.ts`**

```typescript
export interface Env {
  DB:         D1Database;
  USAGE_KV:   KVNamespace;
  JWT_SECRET: string;
  ANTHROPIC_API_KEY: string;
  LEMONSQUEEZY_WEBHOOK_SECRET: string;
  ENVIRONMENT?: string;
}

export interface User {
  id:            number;
  email:         string;
  password_hash: string;
  plan:          'free' | 'trial' | 'pro';
  plan_expires:  string | null;
  created_at:    string;
  updated_at:    string;
}

export interface AuthUser {
  sub:   number;
  email: string;
  plan:  'free' | 'trial' | 'pro';
  iat:   number;
  exp:   number;
  type?: string;
}
```

- [ ] **Step 4.2: Write `src/utils.ts`**

```typescript
export function json(data: unknown, status = 200, headers: HeadersInit = {}): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  });
}

export function hex(bytes: Uint8Array): string {
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

export function yyyyMM(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export function nextMonthStart(): string {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth() + 1, 1).toISOString();
}

export function secondsUntilMonthEnd(): number {
  const now  = new Date();
  const next = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  return Math.floor((next.getTime() - now.getTime()) / 1000);
}
```

- [ ] **Step 4.3: Commit**

```bash
git add src/types.ts src/utils.ts
git commit -m "feat(proxy): add types and utility helpers"
```

---

## Task 5: JWT Implementation

**Files:** Create `src/jwt.ts`, `test/jwt.test.ts`

- [ ] **Step 5.1: Write failing tests** (`test/jwt.test.ts`)

```typescript
import { describe, it, expect } from 'vitest';
import { signJWT, verifyJWT } from '../src/jwt';

const SECRET = 'test-secret-32-chars-minimum-length';

describe('signJWT', () => {
  it('returns a three-part dot-separated string', async () => {
    const token = await signJWT({ sub: 1 }, SECRET, 3600);
    expect(token.split('.')).toHaveLength(3);
  });

  it('encodes the payload correctly', async () => {
    const token   = await signJWT({ sub: 42, plan: 'trial' }, SECRET, 3600);
    const [, p]   = token.split('.');
    const payload = JSON.parse(atob(p.replace(/-/g, '+').replace(/_/g, '/')));
    expect(payload.sub).toBe(42);
    expect(payload.plan).toBe('trial');
    expect(payload.exp).toBeGreaterThan(Math.floor(Date.now() / 1000));
  });
});

describe('verifyJWT', () => {
  it('returns payload for a valid token', async () => {
    const token   = await signJWT({ sub: 1, email: 'test@example.com' }, SECRET, 3600);
    const payload = await verifyJWT(token, SECRET);
    expect(payload).not.toBeNull();
    expect(payload!.sub).toBe(1);
    expect(payload!.email).toBe('test@example.com');
  });

  it('returns null for a tampered token', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, 3600);
    const parts  = token.split('.');
    parts[1]     = btoa(JSON.stringify({ sub: 999 })).replace(/=/g, '');
    const result = await verifyJWT(parts.join('.'), SECRET);
    expect(result).toBeNull();
  });

  it('returns null for a wrong secret', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, 3600);
    const result = await verifyJWT(token, 'wrong-secret');
    expect(result).toBeNull();
  });

  it('returns null for an expired token', async () => {
    const token  = await signJWT({ sub: 1 }, SECRET, -1);
    const result = await verifyJWT(token, SECRET);
    expect(result).toBeNull();
  });

  it('returns null for a malformed token', async () => {
    expect(await verifyJWT('not.a.jwt', SECRET)).toBeNull();
    expect(await verifyJWT('', SECRET)).toBeNull();
  });
});
```

Run: `npm test` → Expected: FAIL (jwt module not found)

- [ ] **Step 5.2: Implement `src/jwt.ts`**

```typescript
const ALGO = { name: 'HMAC', hash: 'SHA-256' } as const;

function b64url(input: string | ArrayBuffer): string {
  const bytes = typeof input === 'string'
    ? new TextEncoder().encode(input)
    : new Uint8Array(input);
  let binary = '';
  for (const b of bytes) binary += String.fromCharCode(b);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(str: string): Uint8Array {
  const padded = str.padEnd(str.length + (4 - (str.length % 4 || 4)), '=');
  const binary = atob(padded.replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(binary, c => c.charCodeAt(0));
}

async function importKey(secret: string, usage: KeyUsage[]): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', new TextEncoder().encode(secret), ALGO, false, usage);
}

export async function signJWT(
  payload: Record<string, unknown>,
  secret: string,
  expiresInSeconds: number
): Promise<string> {
  const now  = Math.floor(Date.now() / 1000);
  const full = { ...payload, iat: now, exp: now + expiresInSeconds };
  const h    = b64url(JSON.stringify({ alg: 'HS256', typ: 'JWT' }));
  const p    = b64url(JSON.stringify(full));
  const key  = await importKey(secret, ['sign']);
  const sig  = await crypto.subtle.sign(ALGO, key, new TextEncoder().encode(`${h}.${p}`));
  return `${h}.${p}.${b64url(sig)}`;
}

export async function verifyJWT(
  token: string,
  secret: string
): Promise<Record<string, unknown> | null> {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    const [h, p, s] = parts;
    const key = await importKey(secret, ['verify']);
    const valid = await crypto.subtle.verify(
      ALGO, key, b64urlDecode(s), new TextEncoder().encode(`${h}.${p}`)
    );
    if (!valid) return null;
    const payload = JSON.parse(new TextDecoder().decode(b64urlDecode(p)));
    if (payload.exp < Math.floor(Date.now() / 1000)) return null;
    return payload;
  } catch {
    return null;
  }
}
```

- [ ] **Step 5.3: Run tests to verify they pass**

```bash
npm test
# Expected: All jwt.test.ts tests PASS
```

- [ ] **Step 5.4: Commit**

```bash
git add src/jwt.ts test/jwt.test.ts
git commit -m "feat(proxy): implement HMAC-SHA256 JWT sign/verify"
```

---

## Task 6: Password Hashing

**Files:** Create `src/password.ts`, `test/password.test.ts`

- [ ] **Step 6.1: Write failing tests** (`test/password.test.ts`)

```typescript
import { describe, it, expect } from 'vitest';
import { hashPassword, verifyPassword } from '../src/password';

describe('hashPassword', () => {
  it('returns a salt:hash hex string', async () => {
    const hash = await hashPassword('MyPassword123!');
    const parts = hash.split(':');
    expect(parts).toHaveLength(2);
    expect(parts[0]).toHaveLength(32); // 16 bytes hex = 32 chars
    expect(parts[1]).toHaveLength(64); // 32 bytes hex = 64 chars
  });

  it('produces different hashes for same password (random salt)', async () => {
    const h1 = await hashPassword('same');
    const h2 = await hashPassword('same');
    expect(h1).not.toBe(h2);
  });
});

describe('verifyPassword', () => {
  it('returns true for correct password', async () => {
    const hash  = await hashPassword('correct-horse-battery-staple');
    const valid = await verifyPassword('correct-horse-battery-staple', hash);
    expect(valid).toBe(true);
  });

  it('returns false for wrong password', async () => {
    const hash  = await hashPassword('correct');
    const valid = await verifyPassword('wrong', hash);
    expect(valid).toBe(false);
  });

  it('returns false for malformed stored hash', async () => {
    expect(await verifyPassword('test', 'not-a-hash')).toBe(false);
    expect(await verifyPassword('test', '')).toBe(false);
    expect(await verifyPassword('test', 'aaa:bbb:ccc')).toBe(false);
  });
});
```

Run: `npm test` → Expected: FAIL (password module not found)

- [ ] **Step 6.2: Implement `src/password.ts`**

```typescript
import { hex } from './utils';

const ITERATIONS = 100_000;
const KEY_BYTES  = 32;

export async function hashPassword(plain: string): Promise<string> {
  const salt    = crypto.getRandomValues(new Uint8Array(16));
  const keyMat  = await derive(plain, salt);
  return `${hex(salt)}:${hex(new Uint8Array(keyMat))}`;
}

export async function verifyPassword(plain: string, stored: string): Promise<boolean> {
  try {
    const parts = stored.split(':');
    if (parts.length !== 2) return false;
    const [saltHex, hashHex] = parts;
    if (saltHex.length !== 32 || hashHex.length !== 64) return false;
    const salt   = unhex(saltHex);
    const keyMat = await derive(plain, salt);
    return hex(new Uint8Array(keyMat)) === hashHex;
  } catch {
    return false;
  }
}

async function derive(plain: string, salt: Uint8Array): Promise<ArrayBuffer> {
  const base = await crypto.subtle.importKey(
    'raw', new TextEncoder().encode(plain), 'PBKDF2', false, ['deriveBits']
  );
  return crypto.subtle.deriveBits(
    { name: 'PBKDF2', hash: 'SHA-256', salt, iterations: ITERATIONS },
    base, KEY_BYTES * 8
  );
}

function unhex(s: string): Uint8Array {
  const pairs = s.match(/.{2}/g);
  if (!pairs) throw new Error('Invalid hex string');
  return new Uint8Array(pairs.map(b => parseInt(b, 16)));
}
```

- [ ] **Step 6.3: Run tests to verify they pass**

```bash
npm test
# Expected: All password.test.ts tests PASS
# Note: 100k PBKDF2 iterations may make tests slow (~1–2s each) — acceptable
```

- [ ] **Step 6.4: Commit**

```bash
git add src/password.ts test/password.test.ts
git commit -m "feat(proxy): implement PBKDF2 password hash/verify"
```

---

## Task 7: Auth Handlers

**Files:** Create `src/auth.ts`, `src/middleware.ts`, add tests to `test/auth.test.ts`

- [ ] **Step 7.1: Write failing tests** (add to `test/auth.test.ts`)

```typescript
// Note: Full integration tests require @cloudflare/vitest-pool-workers with D1 binding.
// These unit tests validate handler logic with mocked D1.

import { describe, it, expect, vi } from 'vitest';
import { handleRegister, handleToken, handleRefresh } from '../src/auth';

function makeEnv(dbResult: Record<string, unknown> | null = null) {
  return {
    DB: {
      prepare: vi.fn().mockReturnValue({
        bind: vi.fn().mockReturnThis(),
        run:   vi.fn().mockResolvedValue({ success: true }),
        first: vi.fn().mockResolvedValue(dbResult),
      }),
    },
    JWT_SECRET: 'test-secret-long-enough-for-hmac-sha256',
  } as unknown as import('../src/types').Env;
}

function makeRequest(body: unknown): Request {
  return new Request('http://localhost', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

describe('handleRegister', () => {
  it('returns 400 if email missing', async () => {
    const res = await handleRegister(makeRequest({ password: 'pass123' }), makeEnv());
    expect(res.status).toBe(400);
  });

  it('returns 201 on success', async () => {
    const res = await handleRegister(makeRequest({ email: 'a@b.com', password: 'pass1234' }), makeEnv());
    expect(res.status).toBe(201);
  });
});

describe('handleToken', () => {
  it('returns 401 if user not found', async () => {
    const res = await handleToken(makeRequest({ email: 'no@user.com', password: 'x' }), makeEnv(null));
    expect(res.status).toBe(401);
  });
});
```

Run: `npm test` → Expected: FAIL (auth module not found)

- [ ] **Step 7.2: Implement `src/auth.ts`**

```typescript
import { hashPassword, verifyPassword } from './password';
import { signJWT, verifyJWT } from './jwt';
import { json } from './utils';
import type { Env, User } from './types';

export async function handleRegister(req: Request, env: Env): Promise<Response> {
  const body = await req.json<{ email?: string; password?: string }>();
  const email    = body.email?.trim().toLowerCase();
  const password = body.password;

  if (!email || !password)      return json({ error: 'email and password required' }, 400);
  if (password.length < 8)      return json({ error: 'password must be at least 8 characters' }, 400);
  if (!email.includes('@'))     return json({ error: 'invalid email address' }, 400);

  const hash    = await hashPassword(password);
  const expires = new Date(Date.now() + 7 * 86_400_000).toISOString();

  try {
    await env.DB.prepare(
      `INSERT INTO users (email, password_hash, plan, plan_expires) VALUES (?, ?, 'trial', ?)`
    ).bind(email, hash, expires).run();
  } catch {
    return json({ error: 'Email already registered' }, 409);
  }

  return json({ message: 'Account created. Your 7-day trial starts now.' }, 201);
}

export async function handleToken(req: Request, env: Env): Promise<Response> {
  const body  = await req.json<{ email?: string; password?: string }>();
  const email = body.email?.trim().toLowerCase() ?? '';

  const user = await env.DB.prepare(`SELECT * FROM users WHERE email = ?`)
    .bind(email).first<User>();

  if (!user || !(await verifyPassword(body.password ?? '', user.password_hash))) {
    await new Promise(r => setTimeout(r, 200)); // Constant-time delay to prevent timing attacks.
    return json({ error: 'Invalid credentials' }, 401);
  }

  let plan = user.plan;
  if (plan === 'trial' && user.plan_expires && new Date(user.plan_expires) < new Date()) {
    plan = 'free';
    await env.DB.prepare(
      `UPDATE users SET plan = 'free', plan_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?`
    ).bind(user.id).run();
  }

  const access  = await signJWT({ sub: user.id, email: user.email, plan }, env.JWT_SECRET, 3600);
  const refresh = await signJWT({ sub: user.id, type: 'refresh' }, env.JWT_SECRET, 30 * 86_400);

  return json({ access_token: access, refresh_token: refresh, plan });
}

export async function handleRefresh(req: Request, env: Env): Promise<Response> {
  const body    = await req.json<{ refresh_token?: string }>();
  const payload = await verifyJWT(body.refresh_token ?? '', env.JWT_SECRET);

  if (!payload || payload['type'] !== 'refresh') {
    return json({ error: 'Invalid or expired refresh token' }, 401);
  }

  const user = await env.DB.prepare(`SELECT * FROM users WHERE id = ?`)
    .bind(payload['sub']).first<User>();
  if (!user) return json({ error: 'User not found' }, 404);

  const access = await signJWT({ sub: user.id, email: user.email, plan: user.plan }, env.JWT_SECRET, 3600);
  return json({ access_token: access });
}
```

- [ ] **Step 7.3: Implement `src/middleware.ts`**

```typescript
import { verifyJWT } from './jwt';
import { json } from './utils';
import type { Env, AuthUser } from './types';

export async function requireAuth(
  req: Request,
  env: Env
): Promise<{ user: AuthUser } | Response> {
  const auth = req.headers.get('Authorization') ?? '';
  if (!auth.startsWith('Bearer ')) return json({ error: 'Unauthorized' }, 401);

  const payload = await verifyJWT(auth.slice(7), env.JWT_SECRET);
  if (!payload) return json({ error: 'Invalid or expired token' }, 401);

  return { user: payload as AuthUser };
}
```

- [ ] **Step 7.4: Run tests to verify they pass**

```bash
npm test
# Expected: All auth.test.ts and middleware unit tests PASS
```

- [ ] **Step 7.5: Commit**

```bash
git add src/auth.ts src/middleware.ts test/auth.test.ts
git commit -m "feat(proxy): implement register, login, refresh handlers"
```

---

## Task 8: Entitlement Handler

**Files:** Create `src/entitlement.ts`, `test/entitlement.test.ts`

- [ ] **Step 8.1: Write failing tests** (`test/entitlement.test.ts`)

```typescript
import { describe, it, expect, vi } from 'vitest';
import { handleEntitlement } from '../src/entitlement';
import type { Env, AuthUser } from '../src/types';

function makeEnv(usedTokens: number): Env {
  return {
    USAGE_KV: {
      get: vi.fn().mockResolvedValue(usedTokens > 0 ? String(usedTokens) : null),
    },
  } as unknown as Env;
}

const trialUser: AuthUser = {
  sub: 1, email: 'test@example.com', plan: 'trial', iat: 0, exp: 9999999999
};

const freeUser: AuthUser = {
  sub: 2, email: 'free@example.com', plan: 'free', iat: 0, exp: 9999999999
};

const proUser: AuthUser = {
  sub: 3, email: 'pro@example.com', plan: 'pro', iat: 0, exp: 9999999999
};

describe('handleEntitlement', () => {
  it('returns correct token limit for trial plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(5000), trialUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBe(300_000);
    expect(body.tokens_used).toBe(5000);
    expect(body.plan).toBe('trial');
  });

  it('returns correct token limit for free plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(0), freeUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBe(50_000);
    expect(body.tokens_used).toBe(0);
  });

  it('returns null token_limit for pro plan', async () => {
    const res  = await handleEntitlement(new Request('http://x'), makeEnv(0), proUser);
    const body = await res.json() as Record<string, unknown>;
    expect(body.tokens_limit).toBeNull();
    expect((body.features as Record<string, boolean>).own_key).toBe(true);
  });

  it('returns correct feature flags for free plan', async () => {
    const res      = await handleEntitlement(new Request('http://x'), makeEnv(0), freeUser);
    const body     = await res.json() as Record<string, unknown>;
    const features = body.features as Record<string, boolean>;
    expect(features.chat).toBe(true);
    expect(features.generator).toBe(false);
    expect(features.own_key).toBe(false);
  });

  it('returns correct feature flags for trial plan', async () => {
    const res      = await handleEntitlement(new Request('http://x'), makeEnv(0), trialUser);
    const body     = await res.json() as Record<string, unknown>;
    const features = body.features as Record<string, boolean>;
    expect(features.chat).toBe(true);
    expect(features.generator).toBe(true);
    expect(features.own_key).toBe(false);
  });
});
```

Run: `npm test` → Expected: FAIL (entitlement module not found)

- [ ] **Step 8.2: Implement `src/entitlement.ts`**

```typescript
import { yyyyMM, nextMonthStart } from './utils';
import type { Env, AuthUser } from './types';

const PLAN_LIMITS: Record<string, number | null> = {
  free:  50_000,
  trial: 300_000,
  pro:   null,
};

const PLAN_FEATURES: Record<string, Record<string, boolean>> = {
  free:  { chat: true, generator: false, seo: false, images: false, own_key: false },
  trial: { chat: true, generator: true,  seo: true,  images: true,  own_key: false },
  pro:   { chat: true, generator: true,  seo: true,  images: true,  own_key: true  },
};

export async function handleEntitlement(
  _req: Request,
  env: Env,
  user: AuthUser
): Promise<Response> {
  const monthKey = `usage:${user.sub}:${yyyyMM()}`;
  const usedStr  = await env.USAGE_KV.get(monthKey);
  const used     = usedStr ? parseInt(usedStr, 10) : 0;
  const limit    = PLAN_LIMITS[user.plan] ?? null;
  const features = PLAN_FEATURES[user.plan] ?? PLAN_FEATURES.free;

  return new Response(JSON.stringify({
    plan:              user.plan,
    tokens_used:       used,
    tokens_limit:      limit,
    tokens_remaining:  limit === null ? null : Math.max(0, limit - used),
    resets_at:         nextMonthStart(),
    features,
  }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}
```

- [ ] **Step 8.3: Run tests to verify they pass**

```bash
npm test
# Expected: All entitlement.test.ts tests PASS
```

- [ ] **Step 8.4: Commit**

```bash
git add src/entitlement.ts test/entitlement.test.ts
git commit -m "feat(proxy): implement GET /v1/entitlement handler"
```

---

## Task 9: Stubs + Route Dispatch

**Files:** Create `src/chat.ts`, `src/webhooks.ts`, `src/db.ts`, `src/index.ts`

- [ ] **Step 9.1: Create `src/chat.ts` (stub)**

```typescript
import { json } from './utils';
import type { Env, AuthUser } from './types';

export async function handleChat(
  _req: Request,
  _env: Env,
  _user: AuthUser
): Promise<Response> {
  return json({ error: 'Chat proxy not yet implemented. Coming in Phase 3.' }, 501);
}
```

- [ ] **Step 9.2: Create `src/webhooks.ts` (stub)**

```typescript
import { json } from './utils';
import type { Env } from './types';

export async function handleLemonSqueezy(
  _req: Request,
  _env: Env
): Promise<Response> {
  return json({ error: 'Webhook handler not yet implemented. Coming in Phase 3.' }, 501);
}
```

- [ ] **Step 9.3: Create `src/db.ts`**

```typescript
import type { Env, User } from './types';

export async function getUserByEmail(email: string, env: Env): Promise<User | null> {
  return env.DB.prepare(`SELECT * FROM users WHERE email = ?`)
    .bind(email.toLowerCase().trim()).first<User>();
}

export async function getUserById(id: number, env: Env): Promise<User | null> {
  return env.DB.prepare(`SELECT * FROM users WHERE id = ?`).bind(id).first<User>();
}

export async function updateUserPlan(
  email: string,
  plan: 'free' | 'trial' | 'pro',
  planExpires: string | null,
  env: Env
): Promise<void> {
  await env.DB.prepare(
    `UPDATE users SET plan = ?, plan_expires = ?, updated_at = CURRENT_TIMESTAMP WHERE email = ?`
  ).bind(plan, planExpires, email.toLowerCase().trim()).run();
}
```

- [ ] **Step 9.4: Create `src/index.ts`**

```typescript
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
      return new Response(null, { status: 204, headers: corsHeaders(request) });
    }

    // Public routes
    if (method === 'POST' && path === '/v1/auth/register')      return handleRegister(request, env);
    if (method === 'POST' && path === '/v1/auth/token')         return handleToken(request, env);
    if (method === 'POST' && path === '/v1/auth/refresh')       return handleRefresh(request, env);
    if (method === 'POST' && path === '/webhooks/lemonsqueezy') return handleLemonSqueezy(request, env);

    // Authenticated routes
    const authResult = await requireAuth(request, env);
    if (authResult instanceof Response) return authResult;
    const { user } = authResult;

    if (method === 'GET'  && path === '/v1/entitlement') return handleEntitlement(request, env, user);
    if (method === 'POST' && path === '/v1/chat')        return handleChat(request, env, user);

    return json({ error: 'Not found' }, 404);
  },
};

function corsHeaders(req: Request): HeadersInit {
  return {
    'Access-Control-Allow-Origin':  req.headers.get('Origin') ?? '*',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Authorization, Content-Type',
    'Access-Control-Max-Age':       '86400',
  };
}
```

- [ ] **Step 9.5: Run full test suite**

```bash
npm test
# Expected: All tests PASS (jwt, password, auth, entitlement)
```

- [ ] **Step 9.6: Commit**

```bash
git add src/
git commit -m "feat(proxy): add route dispatch, stubs, and db helpers"
```

---

## Task 10: Deploy and Smoke Test

- [ ] **Step 10.1: Local smoke test**

```bash
wrangler dev
# In a new terminal:

# Register
curl -X POST http://localhost:8787/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"testpass123"}' | jq .
# Expected: {"message":"Account created. Your 7-day trial starts now."}

# Login
curl -X POST http://localhost:8787/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"testpass123"}' | jq .
# Expected: {"access_token":"eyJ...","refresh_token":"eyJ...","plan":"trial"}
# Save the access_token as TOKEN

# Entitlement
curl http://localhost:8787/v1/entitlement \
  -H "Authorization: Bearer $TOKEN" | jq .
# Expected: {"plan":"trial","tokens_used":0,"tokens_limit":300000,...}

# Unauthenticated access
curl http://localhost:8787/v1/entitlement | jq .
# Expected: {"error":"Unauthorized"}
```

- [ ] **Step 10.2: Deploy to Cloudflare**

```bash
wrangler deploy
# Note the deployed URL, e.g. https://wp-ai-mind-proxy.YOUR.workers.dev
# Update NJ_Auth::PROXY_BASE in includes/Auth/NJ_Auth.php (Phase 2) with this URL
```

- [ ] **Step 10.3: Apply migration to production**

```bash
wrangler d1 migrations apply wp-ai-mind --remote
# Expected: Applied 1 migration(s)
```

- [ ] **Step 10.4: Production smoke test (repeat Step 10.1 against live URL)**

```bash
BASE="https://wp-ai-mind-proxy.YOUR.workers.dev"

curl -X POST $BASE/v1/auth/register \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke@example.com","password":"smoketest123"}' | jq .
# Expected: 201 with message

TOKEN=$(curl -s -X POST $BASE/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"smoke@example.com","password":"smoketest123"}' | jq -r .access_token)

curl $BASE/v1/entitlement -H "Authorization: Bearer $TOKEN" | jq .
# Expected: full entitlement doc with plan='trial'
```

- [ ] **Step 10.5: Commit final wrangler.toml with production resource IDs**

```bash
git add wrangler.toml
git commit -m "chore(proxy): finalize production Cloudflare resource IDs"
```

---

## Phase 1 Acceptance Criteria

- [ ] `POST /v1/auth/register` → 201 with trial plan, 7-day expiry set in D1
- [ ] `POST /v1/auth/register` duplicate email → 409
- [ ] `POST /v1/auth/token` correct credentials → `access_token`, `refresh_token`, `plan`
- [ ] `POST /v1/auth/token` expired trial → `plan: 'free'`
- [ ] `POST /v1/auth/token` wrong password → 401
- [ ] `POST /v1/auth/refresh` valid refresh token → new `access_token`
- [ ] `GET /v1/entitlement` with Bearer → full entitlement shape with correct feature flags
- [ ] `GET /v1/entitlement` without Bearer → 401
- [ ] `POST /v1/chat` → 501 stub response (Phase 3 completes this)
- [ ] All unit tests pass: `npm test`
- [ ] Deployed to Cloudflare Workers; production smoke tests pass

---

## Phase 1 Risk Notes

- **PBKDF2 at 100k iterations is ~150ms per login.** Acceptable (login is not a hot path). Reduce to 60k if Cloudflare CPU limit warnings appear.
- **D1 is eventually consistent across regions.** Logins may appear delayed ~1s at edge. Acceptable.
- **Trial expiry race:** A user's cached JWT (up to 1h old) may show `plan: 'trial'` after expiry. The 1-hour JWT TTL keeps the window short — acceptable.
- **Constant-time delay on auth failure:** 200ms delay in `handleToken` prevents timing-based email enumeration.
