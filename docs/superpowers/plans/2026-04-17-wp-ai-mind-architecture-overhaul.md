# WP AI Mind — Architecture Overhaul: Master Tracking Document

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Freemius-gated, user-API-key model with a thin-client JWT/proxy architecture across **three tiers**: (1) Free users route through a Cloudflare Worker proxy with monthly token limits; (2) Pro Managed users route through the same Worker but at higher limits with model selection; (3) Pro BYOK users bypass the Worker entirely and use their own API keys. All enforcement lives server-side.

**Architecture:** The WordPress plugin becomes a UI-only thin client storing a JWT and rendering entitlement-aware UI. A Cloudflare Worker handles authentication (email+password with PBKDF2, JWT issuance), tier enforcement (rate limits and model allowlists via a single `src/tier-config.ts` config module), and proxies requests to AI providers. User accounts live in Cloudflare D1 (SQLite). Payments handled via LemonSqueezy webhooks updating D1 directly. Provider routing is abstracted via a `ProviderAdapter` interface so adding/switching providers requires no changes to routing logic.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare D1 (SQLite), Cloudflare KV, Cloudflare Durable Objects, Wrangler CLI, Web Crypto API (HMAC-SHA256 JWTs, PBKDF2 passwords), LemonSqueezy, PHP 8.1+ (WordPress plugin), React/JSX (@wordpress/element), @wordpress/api-fetch.

---

## Architecture Principles

These principles govern every implementation decision across all phases. An agentic worker **must** apply them before writing any new code.

| Principle | Rule |
|-----------|------|
| **Reuse first** | Before writing any new code, audit existing files for reusable logic. Never recreate something that already exists — extend or adapt it. |
| **KISS** | The simplest solution that satisfies the acceptance criteria is the right one. No speculative abstractions, no future-proofing beyond the stated requirements. |
| **SRP** | Each module/class/function has exactly one reason to change. Auth logic lives in `NJ_Auth`. Tier config lives in `tier-config.ts`. Routing logic lives in `nj_resolve_provider()`. |
| **Module-based** | Each concern is a discrete, independently testable module. No cross-cutting logic scattered across files. |
| **Provider-agnostic** | AI provider specifics are encapsulated in `ProviderAdapter` implementations (`anthropic.ts`, `openai.ts`, `gemini.ts`). No provider-specific code outside the adapter files. |
| **Config-driven tiers** | Features, token limits, and allowed models per tier are defined in **one place**: `src/tier-config.ts` (Worker) and a PHP equivalent. Adding/removing a feature = edit one file only. |

---

## Phase Status Tracker

| Phase | Name | Phase Document | Depends on | Status |
|-------|------|---------------|-----------|--------|
| **1** | CF Worker: Auth + D1 Foundation | [phase-1-cf-worker-foundation.md](phases/phase-1-cf-worker-foundation.md) | — | ⬜ Not started |
| **2** | PHP: Auth + Entitlement Layer | [phase-2-php-auth-entitlement.md](phases/phase-2-php-auth-entitlement.md) | Phase 1 deployed | ⬜ Not started |
| **3** | CF Worker: Chat + LemonSqueezy Webhooks | [phase-3-cf-worker-chat-webhooks.md](phases/phase-3-cf-worker-chat-webhooks.md) | Phase 1 deployed | ⬜ Not started |
| **4** | PHP: ProxyProvider + ProviderFactory Routing | [phase-4-php-proxy-routing.md](phases/phase-4-php-proxy-routing.md) | Phase 2 + 3 complete | ⬜ Not started |
| **5** | PHP: Freemius + ProGate + UsageLogger Removal | [phase-5-php-legacy-removal.md](phases/phase-5-php-legacy-removal.md) | Phase 4 in production | ⬜ Not started |
| **6** | React: Auth UI + Usage Components | [phase-6-react-auth-ui.md](phases/phase-6-react-auth-ui.md) | Phase 2 + 5 complete | ⬜ Not started |
| **7** | CF Worker: Hardening (streaming, multi-provider, rate limits) | [phase-7-cf-worker-hardening.md](phases/phase-7-cf-worker-hardening.md) | Phase 3 complete | ⬜ Not started |

**Update this table as phases progress.** Use: ⬜ Not started · 🔵 In progress · ✅ Complete · ❌ Blocked

---

## Dependency Graph

```
Phase 1 (CF Worker: Auth + D1)
        │
        ├──────────────────────────────────┐
        ▼                                  ▼
Phase 2 (PHP Auth + Entitlement)    Phase 3 (CF Worker: Chat + Webhooks)
        │                                  │
        └──────────────────────┬───────────┘
                               ▼
                      Phase 4 (PHP ProxyProvider)
                               │
                               ▼
                      Phase 5 (Freemius Removal)
                               │
                               ▼
                      Phase 6 (React Auth UI)

Phase 7 (Worker Hardening) ← can start any time after Phase 3
```

### Parallel Execution Windows

```
Week 1–2   ████ Phase 1: CF Worker Auth + D1
Week 2–3        ████ Phase 2: PHP Auth      ████ Phase 3: CF Chat + Webhooks
Week 3–4                  ████ Phase 4: PHP ProxyProvider
Week 4–5                            ████ Phase 5: Freemius Removal
Week 5–7                                      ████ Phase 6: React UI
                                              ████ Phase 7: Worker Hardening (starts ~Week 5)
```

---

## Tier Model

| Tier | Plan value in D1 | Monthly token limit | Allowed models | Routing | Payment |
|------|-----------------|--------------------|--------------------|---------|---------|
| **Free** | `free` | 50,000 | Claude Haiku only | → Worker (platform key) | None |
| **Trial** | `trial` | 300,000 | Claude Haiku only | → Worker (platform key) | None (7-day window) |
| **Pro Managed** | `pro_managed` | 2,000,000 | Haiku + Sonnet + Opus (configurable) | → Worker (platform key) | LemonSqueezy |
| **Pro BYOK** | `pro` | Unlimited (own cost) | Any model | → Direct provider | LemonSqueezy |

> **Tier config is the single source of truth.** Limits, allowed models, and feature flags are defined in `src/tier-config.ts` (Worker) and the PHP equivalent. To change what a tier can do, edit only that file.

---

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| User account storage | Cloudflare D1 (SQLite) | Co-located with Worker; zero extra infra; free at low volume |
| Password hashing | PBKDF2-SHA256, 100k iterations | bcrypt unavailable in Web Crypto API; PBKDF2 is the standard alternative |
| JWT implementation | HMAC-SHA256 via Web Crypto API | No external library deps; native in CF Workers |
| Token storage (WP) | `wp_options` for both tokens | Consistent with existing ProviderSettings pattern. **Design constraint: one NJ account per WordPress site** — all WP users share the same token; `POST /nj/logout` ends the session site-wide. Intentional for single-owner installs; see phase-2 Risk Notes for multi-user migration path. |
| Entitlement caching | 1-hour WP transient | Matches JWT expiry window; balances freshness vs HTTP overhead |
| Proxy routing | `ProxyProvider` implements `ProviderInterface` | Plugs into existing provider pattern; zero changes to ChatRestController |
| `nj_resolve_provider()` | Global function loaded eagerly | Same pattern as `wp_ai_mind_is_pro()` was; available to all code |
| Trial → Free demotion | Happens at token-request time in Worker | No cron needed; transparently enforced on next login |
| Free/trial model | Claude Haiku via shared proxy key | Cheapest capable model; qualitative incentive to upgrade |
| Pro Managed model selection | Allowed models list from `tier-config.ts` | Worker enforces the allowlist; PHP UI shows available models from entitlement payload |
| Pro BYOK routing | Direct to provider (own key), bypasses Worker | Worker pays nothing for Pro BYOK usage |
| LemonSqueezy integration | Webhooks update D1 plan directly | No Freemius dependency; EU VAT handled; clean webhooks |
| UsageLogger removal | Delete entirely; KV is the source of truth | Logging in plugin can be bypassed; Worker KV is authoritative |
| `ProGate` replacement | `nj_feature( $key )` helper + `NJ_Entitlement::feature()` | Drop-in replacement at all 13 call sites |
| Freemius removal trigger | After Phase 4 is in production | Pro users must have migrated before old gate is removed |
| Feature/tier flexibility | All per-tier capabilities in `tier-config.ts` | To add a feature flag or change a limit, edit one file — no scattered `if plan === 'pro'` checks |

---

## Critical Files to Understand Before Modifying

| File | Phase | Why It Matters |
|------|-------|----------------|
| `wp-ai-mind.php:25-101` | 5 | Freemius bootstrap + `wam_fs()` — entire block removed in Phase 5 |
| `includes/Core/ProGate.php` | 5 | 13 call sites; global `wp_ai_mind_is_pro()` — all replaced in Phase 5 |
| `includes/DB/UsageLogger.php` | 5 | Removed in Phase 5; `maybe_log()` in AbstractProvider removed in Phase 4 |
| `includes/Providers/AbstractProvider.php` | 4 | `maybe_log()` call removed in Phase 4; do not change `with_retry()` |
| `includes/Providers/ProviderFactory.php` | 4 | `make_default()` and `make()` both need `nj_resolve_provider()` integration |
| `includes/Modules/Seo/SeoModule.php:63-90` | 5 | Representative of all 4 gated `permission_callback` lambdas to update |
| `includes/DB/Schema.php:31-50` | 5 | `wpaim_usage_log` table — removed in Phase 5; add `maybe_migrate()` |
| `src/admin/index.js` | 6 | Entry point for auth gate wrapping in Phase 6 |
| `src/admin/settings/ProvidersTab.jsx` | 6 | API key inputs conditionally hidden in Phase 6 |
| `composer.json` | 5 | Freemius SDK removed in Phase 5 |

---

## Phase Summaries

### Phase 1 — CF Worker: Auth + D1 Foundation
**Duration estimate:** 1–2 weeks · **Scope:** Greenfield `wp-ai-mind-proxy/` TypeScript project.

Creates the Cloudflare Worker project from scratch. Implements user registration, login, token refresh, and entitlement endpoint. Introduces `src/tier-config.ts` as the single source of truth for per-tier features, limits, and allowed models. Nothing in the WordPress plugin changes. Phases 2 and 3 are **blocked** until this is deployed to Cloudflare.

**Key deliverables:**
- `wp-ai-mind-proxy/` project with Wrangler config
- `src/tier-config.ts` — single source of truth for all four tiers (`free`, `trial`, `pro_managed`, `pro`)
- D1 `users` table migration (plan column supports all four tier values)
- `POST /v1/auth/register` — creates trial account (7-day `plan_expires`)
- `POST /v1/auth/token` — verifies password, returns JWT pair, demotes expired trials
- `POST /v1/auth/refresh` — returns new access token
- `GET /v1/entitlement` — returns plan, features, token usage, allowed models
- All unit tests passing (Vitest)

**Acceptance criteria:** See [phase-1-cf-worker-foundation.md](phases/phase-1-cf-worker-foundation.md)

---

### Phase 2 — PHP: Auth + Entitlement Layer
**Duration estimate:** 1 week · **Scope:** New PHP classes only; ProGate remains unchanged.

Adds `NJ_Auth` and `NJ_Entitlement` PHP classes and REST endpoints so WordPress can authenticate against the Worker. ProGate and Freemius continue to function — both systems coexist during this phase.

**Key deliverables:**
- `includes/Auth/NJ_Auth.php` — JWT storage, proactive refresh
- `includes/Entitlement/NJ_Entitlement.php` — 1h transient cache, null-object fallback
- `includes/Admin/NJAuthRestController.php` — `/nj/login`, `/nj/register`, `/nj/logout`, `/nj/me`
- `nj_feature()` global helper in `wp-ai-mind.php`
- Localized `isAuthenticated` + `entitlement` data on all admin pages

**Acceptance criteria:** See [phase-2-php-auth-entitlement.md](phases/phase-2-php-auth-entitlement.md)

---

### Phase 3 — CF Worker: Chat + LemonSqueezy Webhooks
**Duration estimate:** 1 week · **Scope:** Worker only; no PHP changes. Runs in parallel with Phase 2.

Replaces the 501 stub `handleChat` and `handleLemonSqueezy` functions from Phase 1 with real implementations. Free/trial users are limited by KV token counters (limits from `tier-config.ts`). `pro_managed` users are limited at higher thresholds and may select from approved models. LemonSqueezy webhook sets `plan='pro_managed'` or `plan='pro'` in D1.

**Key deliverables:**
- `POST /v1/chat` — reads limits from `tier-config.ts`, enforces token limits, validates requested model against tier allowlist, proxies to Anthropic (default), updates KV usage
- `POST /webhooks/lemonsqueezy` — HMAC verification, D1 plan updates (supports `pro_managed` and `pro`)
- Vitest tests for both handlers

**Acceptance criteria:** See [phase-3-cf-worker-chat-webhooks.md](phases/phase-3-cf-worker-chat-webhooks.md)

---

### Phase 4 — PHP: ProxyProvider + ProviderFactory Routing
**Duration estimate:** 1 week · **Scope:** New PHP classes + targeted modifications to 3 existing files.

Free/trial/pro_managed users transparently route through `ProxyProvider` → Worker. Pro BYOK users continue using direct providers unchanged. `nj_resolve_provider()` is the single routing decision point. `AbstractProvider::maybe_log()` removed (KV is the source of truth now).

Routing rules in `nj_resolve_provider()`:
- `own_key` feature enabled → direct provider with user's own API key
- `pro_managed` / `trial` / `free` → `ProxyProvider` (Worker enforces limits and model allowlist)

**Key deliverables:**
- `includes/Proxy/NJ_Proxy_Client.php` — wraps Worker `/v1/chat` call; passes requested model and provider
- `includes/Providers/ProxyProvider.php` — implements `ProviderInterface`; respects `model_selection` feature flag; for `pro_managed` passes the user-requested model through; for free/trial forces Haiku
- `nj_resolve_provider()` global function
- `ProviderFactory::make_default()` delegates to `nj_resolve_provider()`
- `AbstractProvider` — `maybe_log()` removed

**Acceptance criteria:** See [phase-4-php-proxy-routing.md](phases/phase-4-php-proxy-routing.md)

---

### Phase 5 — PHP: Freemius Removal + Full Cleanup
**Duration estimate:** 1 week · **Scope:** Delete dead code; replace all 13 ProGate call sites.

⚠️ **Deploy Phase 4 to production before starting this phase.** Existing Pro users must have migrated to NJ accounts before the old gate is removed. Migration notice is shown to unauthenticated users.

**Key deliverables:**
- `ProGate.php` deleted; `wp_ai_mind_is_pro()` replaced at 13 call sites with `nj_feature()`
- `UsageLogger.php` deleted
- Freemius SDK removed from `composer.json` + `vendor/`
- `wam_fs()` block removed from `wp-ai-mind.php`
- `Schema::maybe_migrate()` drops `wpaim_usage_log` table
- All 5 grep-clean checks must pass

**Acceptance criteria:** See [phase-5-php-legacy-removal.md](phases/phase-5-php-legacy-removal.md)

---

### Phase 6 — React: Auth UI + Usage Components
**Duration estimate:** 1–2 weeks · **Scope:** New React components; modifications to existing React apps.

All React admin apps are wrapped in `AuthProvider`. Unauthenticated users see a login/signup form instead of the AI interface. `UsageMeter` and `UsageWarning` appear on all AI pages (hidden for `pro` BYOK). `UpgradeModal` shown on any 429 response. `ProvidersTab` hides API key inputs for non-Pro-BYOK users. `ModelSelector` shown for `pro_managed` users and hidden for free/trial.

**Key deliverables:**
- `src/admin/auth/` — `AuthContext.jsx`, `AuthApp.jsx`, `LoginForm.jsx`, `SignupForm.jsx`
- `src/admin/shared/` — `UsageMeter.jsx`, `UpgradeModal.jsx`, `UsageWarning.jsx`, `ModelSelector.jsx`, `constants.js`
- `src/admin/index.js` — auth gate wrapping all roots
- `ProvidersTab.jsx` — conditional API key section (Pro BYOK only)
- All UI feature decisions driven by `entitlement.features.*` flags — no hardcoded plan-name checks in JSX

**Acceptance criteria:** See [phase-6-react-auth-ui.md](phases/phase-6-react-auth-ui.md)

---

### Phase 7 — CF Worker: Hardening
**Duration estimate:** 1–2 weeks · **Scope:** Worker only; can start any time after Phase 3.

Adds brute-force rate limiting on auth endpoints, real SSE streaming via `TransformStream`, multi-provider routing (OpenAI + Gemini) via the `ProviderAdapter` interface, structured JSON error logging, and a staging environment in `wrangler.toml`. All provider-specific logic lives exclusively in the adapter files (`src/providers/`).

**Key deliverables:**
- `src/ratelimit.ts` + `src/rate-limiter-do.ts` — Durable Object-backed rate limiter (strongly consistent)
- `src/providers/types.ts` — `ProviderAdapter` interface (provider-agnostic contract)
- `src/providers/anthropic.ts`, `openai.ts`, `gemini.ts` — provider-specific adapters only
- `chat.ts` — routing via adapters + `TransformStream` passthrough for `stream:true`; limits from `tier-config.ts`
- `wrangler.toml` — `[env.staging]` block
- Vitest tests passing; smoke test confirming rate limit at attempt 11

**Acceptance criteria:** See [phase-7-cf-worker-hardening.md](phases/phase-7-cf-worker-hardening.md)

---

## Code Reuse Policy

This applies to **every phase**. Agentic workers must follow this policy before and after implementation.

### Pre-implementation (start of each phase)
1. Run `grep -r "class\|function\|interface" --include="*.php" includes/` and review existing PHP abstractions that may apply.
2. For Worker phases: read `src/` files in `wp-ai-mind-proxy/` and list any existing utilities (`utils.ts`, `db.ts`, `middleware.ts`) that can be called instead of reimplemented.
3. Check `ProviderInterface` / `AbstractProvider` before creating any new PHP class that touches AI providers.
4. Check `ProviderAdapter` interface (Phase 7 onward) before adding any provider-specific Worker code.
5. Document which existing files/functions you are reusing in the phase commit message.

### Post-implementation (end of each phase)
1. `grep -r "PLAN_LIMITS\|PLAN_FEATURES\|plan === 'pro'\|plan === 'free'" src/` → must return 0 matches (all logic must be in `tier-config.ts`).
2. Confirm no class/function from an earlier phase has been reimplemented inline.
3. Confirm all new modules have a single responsibility and are independently testable.
4. Confirm provider-specific logic lives only in `src/providers/*.ts` (Worker) or `includes/Providers/*Provider.php` (PHP).

---

## Testing Strategy Per Phase

| Phase | Unit Tests | Integration / E2E |
|-------|-----------|-------------------|
| 1 | `wp-ai-mind-proxy/test/*.test.ts` (Vitest) | curl smoke tests against workers.dev |
| 2 | `tests/Unit/Auth/`, `tests/Unit/Entitlement/` | Playwright: login flow on localhost:8080 |
| 3 | `wp-ai-mind-proxy/test/chat.test.ts`, `webhooks.test.ts` | curl: `POST /v1/chat` with test JWT |
| 4 | `tests/Unit/Proxy/`, `tests/Unit/Providers/ProxyProviderTest.php` | Playwright: chat as free-tier user |
| 5 | All existing Unit tests must still pass; `ProGateTest.php` deleted | `phpcs` + `phpunit` full suite |
| 6 | Manual browser verification (no unit tests for React UI) | Visual + flow testing in browser |
| 7 | `wp-ai-mind-proxy/test/ratelimit.test.ts`, `streaming.test.ts` | curl rate limit + stream smoke tests |

---

## Final Verification Checklist

Run these checks before declaring the overhaul complete.

### Grep clean checks (must return zero matches)
- [ ] `grep -r "wp_ai_mind_is_pro" .` — no matches
- [ ] `grep -r "wam_fs" .` — no matches
- [ ] `grep -r "ProGate" .` — no matches
- [ ] `grep -r "UsageLogger" .` — no matches
- [ ] `grep -r "freemius" vendor/` — no matches (directory should not exist)

### Build checks
- [ ] `./vendor/bin/phpunit tests/Unit/ --colors=always` — all pass
- [ ] `./vendor/bin/phpcs --standard=phpcs.xml.dist` — no errors
- [ ] `npm run build` — no errors
- [ ] `npx vitest run` (in `wp-ai-mind-proxy/`) — all pass

### E2E flow checks
- [ ] Login (free) → chat → see UsageMeter → hit 429 → see UpgradeModal; `ModelSelector` absent
- [ ] Login (`pro_managed`) → chat → see `ModelSelector` with multiple options; select Sonnet → Sonnet used; UsageMeter visible
- [ ] Login (`pro_managed`) → request disallowed model (one not in `allowed_models`) → 403
- [ ] Login (Pro BYOK) → chat → routes direct (no Worker involvement, verify via network log); `ModelSelector` absent; UsageMeter absent
- [ ] Logout → auth card shown, not AI interface
- [ ] D1 `users` table exists with correct schema including `CHECK(plan IN ('free','trial','pro_managed','pro'))`
- [ ] LemonSqueezy test webhook delivery → D1 plan updates to `pro_managed` (or `pro` for BYOK product)
- [ ] JWT expiry: wait for access token to expire → refresh path exercises silently on next request
- [ ] Rate limiting: 11 rapid login attempts from same IP → 429 on attempt 11
- [ ] `grep -rn "PLAN_LIMITS\|50_000\|300_000" src/ --include="*.ts" | grep -v tier-config` → 0 matches

---

## Worker Endpoint Reference

| Method | Path | Auth | Phase |
|--------|------|------|-------|
| POST | `/v1/auth/register` | Public | 1 |
| POST | `/v1/auth/token` | Public | 1 |
| POST | `/v1/auth/refresh` | Public | 1 |
| GET | `/v1/entitlement` | Bearer JWT | 1 |
| POST | `/v1/chat` | Bearer JWT | 3 |
| POST | `/webhooks/lemonsqueezy` | HMAC sig | 3 |

## WordPress REST Endpoint Reference

| Method | Path | Auth | Phase |
|--------|------|------|-------|
| POST | `/wp-ai-mind/v1/nj/login` | Public | 2 |
| POST | `/wp-ai-mind/v1/nj/register` | Public | 2 |
| POST | `/wp-ai-mind/v1/nj/logout` | Public | 2 |
| GET | `/wp-ai-mind/v1/nj/me` | Public | 2 |

---

## Plan History

| Date | Author | Change |
|------|--------|--------|
| 2026-04-17 | Niklas Johansson | Initial plan created |
| 2026-04-17 | Claude | Added `pro_managed` tier; tier-config module as single source of truth; architecture principles (KISS, SRP, module-based, provider-agnostic, reuse-first); code reuse policy; updated phase summaries |
