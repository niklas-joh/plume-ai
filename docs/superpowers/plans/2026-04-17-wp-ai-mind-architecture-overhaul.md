# WP AI Mind — Architecture Overhaul: Master Tracking Document

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Freemius-gated, user-API-key model with a WordPress-native four-tier architecture: (1) Free users (50k tokens), (2) Trial users (300k tokens, 7-day window), (3) Pro Managed users (2M tokens + model selection), (4) Pro BYOK users (unlimited, own API keys). Free/Trial/Pro Managed route through minimal Cloudflare proxy; Pro BYOK bypasses proxy entirely. All user management and business logic stays in WordPress.

**Architecture:** The WordPress plugin handles all user management via WordPress users + custom meta, payment processing via LemonSqueezy webhooks to WordPress endpoints, and rate limiting via WordPress transients. A minimal Cloudflare Worker (~200 lines) serves only to protect API keys for Free/Trial/Pro Managed users. Pro BYOK users store encrypted API keys in WordPress and make direct provider calls. No external auth system, no complex microservices.

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
| **1** | WordPress Three-Tier Foundation | [phase-1-wordpress-foundation.md](phases/phase-1-wordpress-foundation.md) | — | ⬜ Not started |
| **2** | Minimal Cloudflare Proxy | [phase-2-minimal-cloudflare-proxy.md](phases/phase-2-minimal-cloudflare-proxy.md) | Phase 1 complete | ⬜ Not started |
| **3** | Integration & Cleanup | [phase-3-integration-cleanup.md](phases/phase-3-integration-cleanup.md) | Phase 1 + 2 complete | ⬜ Not started |

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
| **Trial** | `'trial'` | 300,000 | Claude Haiku only | → Cloudflare proxy | None (7-day window) |
| **Pro Managed** | `'pro_managed'` | 2,000,000 | Haiku + Sonnet + Opus | → Cloudflare proxy | LemonSqueezy |
| **Pro BYOK** | `'pro_byok'` | Unlimited (own cost) | Any model | → Direct provider calls | LemonSqueezy |

> **WordPress-native tier management.** Limits, allowed models, and feature flags are defined in `NJ_Tier_Config` (single source of truth). `NJ_Tier_Manager` handles CRUD and trial logic. No global helper functions — call class methods directly.

---

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| User account storage | WordPress users + custom meta | WordPress-native; no external database needed |
| Tier management | WordPress user meta | Simple, reliable, follows WordPress patterns |
| Rate limiting enforcement | Cloudflare KV (proxy-routed tiers) | Single enforcement point; WP meta serves display only |
| Payment processing | LemonSqueezy webhooks to WordPress | Direct integration; no external auth system |
| API key protection | Minimal Cloudflare proxy (200 lines) | Protects keys without complex microservices |
| Pro BYOK routing | Direct to provider, bypasses proxy | No proxy costs for power users |
| Request signing | HMAC-SHA256 from WordPress | WordPress signs, proxy validates |
| Usage tracking | WordPress user meta + Cloudflare KV | WordPress for display, KV for enforcement |
| Legacy code removal | Complete Freemius + ProGate removal | Clean slate, WordPress-native patterns |
| Provider routing | Single decision point in WordPress | Centralized logic, clear separation |

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
**Duration estimate:** 1 week

Minimal proxy (~200 lines) for API key protection only. Single endpoint `/v1/chat` with HMAC verification. WordPress signs requests, proxy validates and forwards to providers.

**Key deliverables:**
- `src/index.ts` — minimal proxy with signed request verification
- `NJ_Proxy_Client` class — WordPress proxy client with HMAC signing
- Cloudflare KV usage tracking for Free/Pro Managed tiers
- Deploy proxy to Cloudflare Workers

**Acceptance criteria:** See [phase-2-minimal-cloudflare-proxy.md](phases/phase-2-minimal-cloudflare-proxy.md)

---

### Phase 3: Integration & Cleanup
**Duration estimate:** 1 week

Final integration, provider routing logic, admin UI polish, and cleanup of any remaining legacy code.

**Key deliverables:**
- Provider routing logic (Free/Pro Managed → proxy, Pro BYOK → direct)
- Admin UI for tier management and usage display
- Final cleanup and testing
- Documentation updates

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
- [ ] LemonSqueezy webhook → WordPress tier update
- [ ] WordPress transients rate limiting
- [ ] HMAC request signing verification

---

## Cloudflare Worker Endpoints

| Method | Path | Auth | Phase |
|--------|------|------|-------|
| POST | `/v1/chat` | HMAC signed requests from WordPress | 2 |

## WordPress REST Endpoints

| Method | Path | Auth | Phase |
|--------|------|------|-------|
| POST | `/wp-json/wp-ai-mind/v1/webhook` | LemonSqueezy HMAC | 1 |

---

## Plan History

| Date | Author | Change |
|------|--------|--------|
| 2026-04-17 | Niklas Johansson | Initial plan created |
| 2026-04-17 | Claude | Added `pro_managed` tier; tier-config module as single source of truth; architecture principles (KISS, SRP, module-based, provider-agnostic, reuse-first); code reuse policy; updated phase summaries |
