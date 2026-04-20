# WP AI Mind — Architecture Overhaul: Master Tracking Document

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Freemius-gated, user-API-key model with a WordPress-native four-tier architecture: (1) Free users (50k tokens), (2) Trial users (300k tokens, 30-day window), (3) Pro Managed users (2M tokens + model selection), (4) Pro BYOK users (unlimited, own API keys). Free/Trial/Pro Managed route through minimal Cloudflare proxy; Pro BYOK bypasses proxy entirely. All user management and business logic stays in WordPress.

**Architecture:** The WordPress plugin handles all user management via WordPress users + custom meta. Payment processing is handled by LemonSqueezy webhooks firing directly to the Cloudflare Worker (not WordPress). A Cloudflare Worker (~300 lines across 5 files) issues per-site Bearer tokens, handles LemonSqueezy webhook tier upgrades/downgrades, and proxies Anthropic API calls for Free/Trial/Pro Managed users. Pro BYOK users store encrypted API keys in WordPress and make direct provider calls. No shared secrets distributed in plugin code.

**Tech Stack:** PHP 8.1+ (WordPress plugin), WordPress users + meta (user management), LemonSqueezy webhooks (payments), WordPress transients (rate limiting), minimal Cloudflare Workers (TypeScript, ~200 lines), Cloudflare KV (usage tracking), existing provider architecture.

---

## Architecture Principles

These principles govern every implementation decision across all phases. An agentic worker **must** apply them before writing any new code.

| Principle | Rule |
|-----------|------|
| **Reuse first** | Before writing any new code, audit existing files for reusable logic. Never recreate something that already exists — extend or adapt it. |
| **KISS** | The simplest solution that satisfies the acceptance criteria is the right one. No speculative abstractions, no future-proofing beyond the stated requirements. |
| **SRP** | Each module/class/function has exactly one reason to change. Tier management lives in `NJ_Tier_Manager`. Usage tracking lives in `NJ_Usage_Tracker`. Routing logic lives in `nj_resolve_provider()`. |
| **Module-based** | Each concern is a discrete, independently testable module. No cross-cutting logic scattered across files. |
| **WordPress-native** | Uses existing WordPress provider architecture with minimal external dependencies. |
| **Config-driven tiers** | Features, token limits, and allowed models per tier are defined in WordPress PHP classes. Adding/removing a feature = edit the tier configuration in one place. |

---

## Phase Status Tracker (Simplified Hybrid Approach)

| Phase | Name | Phase Document | Depends on | Status |
|-------|------|---------------|-----------|--------|
| **1** | WordPress Three-Tier Foundation | [phase-1-wordpress-foundation.md](phases/phase-1-wordpress-foundation.md) | — | ✅ Complete |
| **2** | Minimal Cloudflare Proxy | [phase-2-minimal-cloudflare-proxy.md](phases/phase-2-minimal-cloudflare-proxy.md) | Phase 1 | ✅ Complete (superseded by 2.1) |
| **2.1** | Licence Auth & Zero-Friction Activation | [phase-2.1-licence-auth-overhaul.md](phases/phase-2.1-licence-auth-overhaul.md) | Phase 2 | 🔵 In progress — PR #208 open |
| **3** | Integration & Cleanup | [phase-3-integration-cleanup.md](phases/phase-3-integration-cleanup.md) | Phase 2.1 merged | ⬜ Not started |

**Original 7-phase plan eliminated** - Replaced with 3-phase hybrid approach for 70% complexity reduction.

**Update this table as phases progress.** Use: ⬜ Not started · 🔵 In progress · ✅ Complete · ❌ Blocked

---

## Dependency Graph

```
Phase 1: WordPress Three-Tier Foundation
        │
        ▼
Phase 2: Minimal Cloudflare Proxy
        │
        ▼
Phase 3: Integration & Cleanup
```

### Execution Timeline (Simplified Hybrid)

```
Week 1–2   ████████ Phase 1: WordPress Foundation
Week 2–3            ████ Phase 2: Minimal Proxy
Week 3–4                 ████ Phase 3: Integration & Cleanup

Total: ~4 weeks (vs original 7 weeks = 45% faster)
```

---

## Tier Model

| Tier | WordPress user meta | Monthly token limit | Allowed models | Routing | Payment |
|------|---------------------|--------------------|--------------------|---------|---------|
| **Free** | `'free'` | 50,000 | Claude Haiku only | → Cloudflare proxy | None |
| **Trial** | `'trial'` | 300,000 | Claude Haiku only | → Cloudflare proxy | None (30-day window) |
| **Pro Managed** | `'pro_managed'` | 2,000,000 | Haiku + Sonnet + Opus | → Cloudflare proxy | LemonSqueezy (variant 1550505 monthly / 1550477 annual) |
| **Pro BYOK** | `'pro_byok'` | Unlimited (own cost) | Any model | → Direct provider calls | LemonSqueezy (variant 1550517, one-time) |

> **WordPress-native tier management.** Limits, allowed models, and feature flags are defined in `NJ_Tier_Config` (single source of truth). `NJ_Tier_Manager` handles CRUD and trial logic. No global helper functions — call class methods directly.

---

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| User account storage | WordPress users + custom meta | WordPress-native; no external database needed |
| Tier management | WordPress user meta (mirrors KV) | Simple, reliable; KV is authoritative for enforcement |
| Rate limiting enforcement | Cloudflare KV per site token per month | WP meta is fail-fast display only; KV cannot be bypassed |
| Payment processing | LemonSqueezy webhooks → Cloudflare Worker | Worker upgrades/downgrades site token tier in KV automatically |
| API key protection | Cloudflare Worker with Bearer site tokens | No shared secret in plugin code; token issued on registration |
| Site registration | Plugin POSTs to `/register` on `init`; token stored in `wp_options` | Zero user action; idempotent |
| Checkout URL | `NJ_Site_Registration::checkout_url()` embeds site token | LS passes it back in webhook; Worker associates purchase automatically |
| Pro BYOK routing | `ClaudeProvider::do_complete()` bypasses proxy for `pro_byok` | No proxy cost; user's own API key |
| Provider routing | `ClaudeProvider::do_complete()` checks tier and routes | No separate ProxyProvider class needed |
| Usage tracking | Cloudflare KV authoritative; WordPress user meta for dashboard display | KV enforces; WP mirrors locally |
| Legacy code removal | Complete Freemius + ProGate removal (Phase 1) | Clean slate, WordPress-native patterns |

---

## Critical Files to Understand Before Modifying

| File | Phase | Why It Matters |
|------|-------|----------------|
| `wp-ai-mind.php:25-101` | 1 | Freemius bootstrap + `wam_fs()` — entire block removed in Phase 1 |
| `includes/Core/ProGate.php` | 1 | 13 call sites; global `wp_ai_mind_is_pro()` — all replaced in Phase 1 |
| `includes/DB/UsageLogger.php` | 1 | Removed in Phase 1; replaced with WordPress-native tracking |
| `includes/Providers/AbstractProvider.php` | 2 | Integration point for proxy routing |
| `includes/Providers/ProviderFactory.php` | 3 | Provider routing logic for Free/Pro Managed → proxy, Pro BYOK → direct |
| `includes/Modules/Seo/SeoModule.php:63-90` | 1 | Representative of all gated `permission_callback` lambdas to update |
| `includes/DB/Schema.php:31-50` | 1 | `wpaim_usage_log` table — removed; add cleanup migration |
| `src/admin/settings/ProvidersTab.jsx` | 3 | API key inputs conditionally hidden for Free/Pro Managed users |
| `composer.json` | 1 | Freemius SDK removed in Phase 1 |

---

## Phase Summaries

### Phase 1: WordPress Three-Tier Foundation
**Duration estimate:** 2 weeks

WordPress-native three-tier system using user meta, LemonSqueezy webhooks, and WordPress transients for rate limiting. Removes Freemius entirely. Pro BYOK users can store encrypted API keys and bypass proxy.

**Key deliverables:**
- `NJ_Tier_Manager` class — WordPress user meta tier management
- `NJ_Usage_Tracker` class — WordPress-native rate limiting
- `NJ_LemonSqueezy_Webhook` class — payment processing
- Remove Freemius SDK and `ProGate` entirely
- Support for encrypted API key storage (Pro BYOK)

**Acceptance criteria:** See [phase-1-wordpress-foundation.md](phases/phase-1-wordpress-foundation.md)

---

### Phase 2: Minimal Cloudflare Proxy
**Status: ✅ Complete (superseded by Phase 2.1)**

Original HMAC-based design. Scaffold and KV namespaces were created; full implementation replaced by Phase 2.1 when the distributed-plugin auth problem was identified.

**Acceptance criteria:** See [phase-2-minimal-cloudflare-proxy.md](phases/phase-2-minimal-cloudflare-proxy.md)

---

### Phase 2.1: Licence Auth & Zero-Friction Activation
**Status: 🔵 In progress — PR #208 open (`feat/phase-2.1-licence-auth` → `feat/api-overhaul`)**

Replaces HMAC shared-secret with per-site Bearer token auth. Plugin auto-registers on `init`. LemonSqueezy webhook fires to Worker (not WordPress), automatically upgrading site tier in KV on purchase.

**Key deliverables (all ✅ merged to branch):**
- `src/auth.ts` — Bearer token authentication
- `src/registration.ts` — `/register` endpoint (idempotent, IP rate-limited)
- `src/webhook.ts` — `/webhook` endpoint (LemonSqueezy HMAC, tier upgrade/downgrade)
- `src/index.ts` — updated routing; HMAC removed; `system` prompt forwarded
- `NJ_Site_Registration` — auto-registers on `init`; `checkout_url()` helper
- `NJ_Proxy_Client` — uses Bearer token; handles 401 stale-token recovery
- `ClaudeProvider::do_complete()` — routes by tier (proxy vs direct)
- `NJ_Tier_Config::TRIAL_DAYS = 30`, `PROXY_URL` constant
- Wrangler secrets: `LS_PRO_MONTHLY_VARIANT_ID` (1550505), `LS_PRO_ANNUAL_VARIANT_ID` (1550477)

**Acceptance criteria:** See [phase-2.1-licence-auth-overhaul.md](phases/phase-2.1-licence-auth-overhaul.md)

---

### Phase 3: Integration & Cleanup
**Duration estimate:** 1 week
**Depends on:** Phase 2.1 merged

Admin UI for tier management and upgrade flows, Pro BYOK API key entry, usage meters, end-to-end Docker smoke test.

**Key deliverables:**
- Admin settings page with LemonSqueezy checkout buttons (using `NJ_Site_Registration::checkout_url()`)
- Pro BYOK API key entry and storage
- Dashboard usage widget with token meter and upgrade prompt
- End-to-end Docker smoke test (deferred Task 13 from Phase 2.1)
- LemonSqueezy product button links updated from 'placeholder' to real URL

**Acceptance criteria:** See [phase-3-integration-cleanup.md](phases/phase-3-integration-cleanup.md)

---

---

## Code Reuse Policy

This applies to **every phase**. Agentic workers must follow this policy before and after implementation.

### Pre-implementation (start of each phase)
1. Run `grep -r "class\|function\|interface" --include="*.php" includes/` and review existing PHP abstractions that may apply.
2. For Worker phases: read `src/` files in `wp-ai-mind-proxy/` and list any existing utilities (`utils.ts`, `db.ts`, `middleware.ts`) that can be called instead of reimplemented.
3. Check `ProviderInterface` / `AbstractProvider` before creating any new PHP class that touches AI providers.
4. Use existing WordPress provider patterns for consistency.
5. Document which existing files/functions you are reusing in the phase commit message.

### Post-implementation (end of each phase)
1. Verify all tier logic is centralized in WordPress PHP tier management classes.
2. Confirm no class/function from an earlier phase has been reimplemented inline.
3. Confirm all new modules have a single responsibility and are independently testable.
4. Confirm provider-specific logic lives only in `includes/Providers/*Provider.php` (WordPress).

---

## Testing Strategy Per Phase

| Phase | Unit Tests | Integration / E2E |
|-------|-----------|-------------------|
| 1 | `tests/Unit/Tiers/`, `tests/Unit/Usage/` (PHPUnit) | WordPress user tier management, usage tracking |
| 2 | `wp-ai-mind-proxy/test/*.test.ts` | curl: signed request verification, rate limiting |
| 3 | `tests/Unit/Providers/` (PHPUnit) | Provider routing logic, proxy vs direct calls |

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
- [ ] Cloudflare Workers deployment succeeds

### E2E flow checks
- [ ] Free user → chat → usage tracking → hit limit → upgrade prompt
- [ ] Pro Managed → model selection → usage tracking works
- [ ] Pro BYOK → direct API calls (bypasses proxy) → encrypted API key storage
- [ ] LemonSqueezy webhook → Worker tier upgrade in KV → site reflects `pro_managed`
- [ ] WordPress usage meta rate-limiting fail-fast (pre-check before proxy call)
- [ ] Bearer token auth: valid token → 200, invalid token → 401, stale token clears and re-registers

---

## Cloudflare Worker Endpoints

| Method | Path | Auth | Phase | Status |
|--------|------|------|-------|--------|
| POST | `/register` | None (rate-limited by IP) | 2.1 | ✅ Live |
| POST | `/webhook` | LemonSqueezy HMAC (`X-Signature`) | 2.1 | ✅ Live |
| POST | `/v1/chat` | Bearer site token (`Authorization: Bearer <token>`) | 2.1 | ✅ Live |

## WordPress REST Endpoints

| Method | Path | Auth | Phase | Status |
|--------|------|------|-------|--------|
| POST | `/wp-json/wp-ai-mind/v1/webhook` | LemonSqueezy HMAC | 1 | ⛔ Disabled (Phase 2.1 — webhook moved to Worker) |

---

## Plan History

| Date | Author | Change |
|------|--------|--------|
| 2026-04-17 | Niklas Johansson | Initial plan created |
| 2026-04-17 | Claude | Added `pro_managed` tier; tier-config module as single source of truth; architecture principles (KISS, SRP, module-based, provider-agnostic, reuse-first); code reuse policy; updated phase summaries |
| 2026-04-20 | Claude | Added Phase 2.1; replaced HMAC auth with Bearer site-token model; moved LS webhook to Worker; updated tier model (trial 30d), design decisions, endpoints tables, phase summaries |
