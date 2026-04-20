# Phase 3: Integration & Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the user-facing product: admin UI with upgrade flows, Pro BYOK API key entry, usage meters, and an end-to-end smoke test. All back-end routing and auth was completed in Phase 2.1.

**Architecture:** `ClaudeProvider` already routes by tier (Phase 2.1). This phase adds the UI layer that connects users to the tier system — upgrade buttons via `NJ_Site_Registration::checkout_url()`, Pro BYOK key management, and usage visualisation. No new routing logic needed.

**Tech Stack:** PHP 8.1+, WordPress plugin patterns, existing admin class structure

**Depends on:** Phase 2.1 merged (`feat/api-overhaul` → `main`)

---

## What Phase 2.1 Already Delivered (Do Not Re-implement)

| Concern | Where It Lives |
|---------|---------------|
| Tier-based routing (proxy vs direct) | `ClaudeProvider::do_complete()` |
| Site registration + token storage | `NJ_Site_Registration::maybe_register()` on `init` |
| Bearer token auth | `NJ_Proxy_Client` → `Authorization: Bearer <token>` |
| LemonSqueezy webhook tier upgrades | Cloudflare Worker `/webhook` |
| Checkout URL with embedded site token | `NJ_Site_Registration::checkout_url(string $variant_id)` |
| Rate limiting (authoritative) | Cloudflare KV |
| Rate limiting (fail-fast) | `NJ_Usage_Tracker::check_limit()` in `NJ_Proxy_Client` |
| ProGate / Freemius removal | Phase 1 |

**Do NOT create a `ProxyProvider` class** — routing is already in `ClaudeProvider`. Do NOT add `nj_resolve_provider()` — the provider pattern is already wired.

---

## LemonSqueezy Variant IDs (for checkout URLs)

| Product | Variant | ID |
|---------|---------|-----|
| Pro Managed | Monthly | `1550505` |
| Pro Managed | Annual | `1550477` |
| Pro BYOK | One-time | `1550517` |

Use `NJ_Site_Registration::checkout_url('1550505')` to build upgrade links.

---

## Task 0: Pre-implementation Audit

- [ ] **Step 0.1: Check current admin file structure**

```bash
ls includes/Admin/
```

- [ ] **Step 0.2: Verify Phase 2.1 is merged**

```bash
git log --oneline main | head -5
# Confirm feat/phase-2.1-licence-auth commits are on main
```

- [ ] **Step 0.3: Check what Freemius/ProGate references remain (if any)**

```bash
grep -r "wp_ai_mind_is_pro\|wam_fs\|ProGate\|Freemius" includes/ --include="*.php" -l
# Should return nothing — Phase 1 removed these
```

---

## Task 1: End-to-End Smoke Test (Deferred from Phase 2.1 Task 13)

**Files:** None (Docker + browser test)

- [ ] **Step 1.1: Start local environment**

```bash
docker compose up -d
```

- [ ] **Step 1.2: Verify auto-registration fires**

Clear the stored token and reload an admin page:

```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "DELETE FROM wp_options WHERE option_name = 'wp_ai_mind_site_token';"
```

Visit any WP admin page (triggers `init` → `NJ_Site_Registration::maybe_register()`), then verify the token was stored:

```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "SELECT option_value FROM wp_options WHERE option_name = 'wp_ai_mind_site_token';"
```

Expected: a 64-character hex string.

- [ ] **Step 1.3: Verify free-tier request routes through proxy**

Tail the Worker logs in a separate terminal:

```bash
cd wp-ai-mind-proxy && wrangler tail
```

As a free-tier user, send a chat message. Confirm the Worker logs show a `POST /v1/chat` with `Authorization: Bearer <token>`.

- [ ] **Step 1.4: Verify pro_byok bypasses proxy**

Set a test user's tier to `pro_byok`:

```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "UPDATE wp_usermeta SET meta_value='pro_byok' WHERE meta_key='wp_ai_mind_tier' AND user_id=1;"
```

Send a chat request. The Worker tail should show **no** `/v1/chat` call.

- [ ] **Step 1.5: Verify stale token recovery**

Corrupt the token:

```bash
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "UPDATE wp_options SET option_value='invalid' WHERE option_name='wp_ai_mind_site_token';"
```

Send a chat request — expect an error. Reload an admin page (triggers `init` → re-registers). Send again — should succeed.

---

## Task 2: Admin Settings Page — Tier Status & Upgrade Flows

**Files:**
- Modify: `includes/Admin/NJ_Tier_Settings.php`

Update the settings page to show real upgrade buttons using `NJ_Site_Registration::checkout_url()` with the correct variant IDs.

- [ ] **Step 2.1: Update the plan comparison table**

Replace any placeholder checkout URLs in `NJ_Tier_Settings.php`. The upgrade buttons should call `NJ_Site_Registration::checkout_url()`:

```php
// Pro Managed upgrade button
$monthly_url = \WP_AI_Mind\Proxy\NJ_Site_Registration::checkout_url( '1550505' );
$annual_url  = \WP_AI_Mind\Proxy\NJ_Site_Registration::checkout_url( '1550477' );

// Pro BYOK upgrade button
$byok_url = \WP_AI_Mind\Proxy\NJ_Site_Registration::checkout_url( '1550517' );
```

- [ ] **Step 2.2: Show current registration status**

Add a "Connection" row to the admin page showing whether the site is registered with the proxy:

```php
$registered = \WP_AI_Mind\Proxy\NJ_Site_Registration::is_registered();
// Display: "Connected ✓" or "Not connected — will auto-connect on next page load"
```

- [ ] **Step 2.3: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
```

- [ ] **Step 2.4: Lint**

```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/Admin/NJ_Tier_Settings.php
```

- [ ] **Step 2.5: Commit**

```bash
git add includes/Admin/NJ_Tier_Settings.php
git commit -m "feat(admin): wire real LemonSqueezy checkout URLs via NJ_Site_Registration::checkout_url()"
```

---

## Task 3: Dashboard Widget — Usage Meter

**Files:**
- Modify or create: `includes/Admin/NJ_Usage_Widget.php`

- [ ] **Step 3.1: Check if widget class already exists**

```bash
ls includes/Admin/
```

If `NJ_Usage_Widget.php` exists, audit it before editing. If not, create it.

- [ ] **Step 3.2: Implement usage widget**

```php
<?php
declare( strict_types=1 );
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Usage_Tracker;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Usage_Widget {

    public static function register_hooks(): void {
        add_action( 'wp_dashboard_setup', [ self::class, 'add_dashboard_widget' ] );
    }

    public static function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wp_ai_mind_usage',
            __( 'AI Mind Usage', 'wp-ai-mind' ),
            [ self::class, 'render' ]
        );
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        $usage   = NJ_Usage_Tracker::get_current_usage( $user_id );
        $tier    = NJ_Tier_Manager::get_user_tier( $user_id );

        echo '<div class="wp-ai-mind-usage-widget">';
        echo '<p><strong>' . esc_html( ucwords( str_replace( '_', ' ', $tier ) ) ) . ' Plan</strong></p>';

        if ( ! empty( $usage['limit'] ) ) {
            $pct   = min( 100, (int) round( ( $usage['used'] / $usage['limit'] ) * 100 ) );
            $color = $pct > 80 ? '#d63638' : ( $pct > 60 ? '#dba617' : '#00a32a' );
            printf(
                '<div style="background:#e0e0e0;height:10px;border-radius:5px;margin:8px 0"><div style="width:%d%%;background:%s;height:100%%;border-radius:5px"></div></div>',
                $pct,
                esc_attr( $color )
            );
            printf(
                '<p style="font-size:12px;color:#666">%s / %s tokens (%s remaining)</p>',
                esc_html( number_format( (int) $usage['used'] ) ),
                esc_html( number_format( (int) $usage['limit'] ) ),
                esc_html( number_format( (int) $usage['remaining'] ) )
            );
            if ( $pct > 80 ) {
                echo '<p class="notice notice-warning inline">' . esc_html__( 'Over 80% of monthly tokens used. Consider upgrading.', 'wp-ai-mind' ) . '</p>';
            }
        } else {
            echo '<p>' . esc_html__( 'Unlimited — using your own API key.', 'wp-ai-mind' ) . '</p>';
        }

        echo '</div>';
    }
}
```

- [ ] **Step 3.3: Hook it in Plugin.php**

In `includes/Core/Plugin.php`, add:

```php
use WP_AI_Mind\Admin\NJ_Usage_Widget;
// ...
NJ_Usage_Widget::register_hooks();
```

- [ ] **Step 3.4: Run tests + lint**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/Admin/NJ_Usage_Widget.php includes/Core/Plugin.php
```

- [ ] **Step 3.5: Commit**

```bash
git add includes/Admin/NJ_Usage_Widget.php includes/Core/Plugin.php
git commit -m "feat(admin): add dashboard usage widget with token meter"
```

---

## Task 4: Pro BYOK — API Key Entry

**Files:**
- Modify: `includes/Admin/NJ_Tier_Settings.php` (or the existing API key settings file)

Pro BYOK users need to enter their Anthropic API key after purchase. The key is stored per-user in `wp_usermeta` (already encrypted by the existing `NJ_Api_Key_Settings` class if it exists — audit before writing).

- [ ] **Step 4.1: Audit existing API key storage**

```bash
grep -rn "wp_ai_mind_api_key" includes/ --include="*.php" | head -20
# Find where keys are stored and how they are encrypted/retrieved
```

- [ ] **Step 4.2: Wire API key form for Pro BYOK users**

Show the API key input only when the current user's tier is `pro_byok`:

```php
$tier = \WP_AI_Mind\Tiers\NJ_Tier_Manager::get_user_tier( get_current_user_id() );
if ( 'pro_byok' === $tier ) {
    // Render API key input — reuse existing NJ_Api_Key_Settings pattern
}
```

- [ ] **Step 4.3: Run tests + lint**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
./vendor/bin/phpcs --standard=phpcs.xml.dist includes/Admin/
```

- [ ] **Step 4.4: Commit**

```bash
git add includes/Admin/
git commit -m "feat(admin): show API key entry for Pro BYOK users"
```

---

## Phase 3 Acceptance Criteria

- [ ] End-to-end smoke test passes in local Docker (Task 1)
- [ ] Upgrade buttons use real LemonSqueezy checkout URLs with embedded site token
- [ ] Admin page shows connection status (`NJ_Site_Registration::is_registered()`)
- [ ] Dashboard widget shows current usage, tier, and percentage bar
- [ ] Pro BYOK users see the API key entry field after purchase
- [ ] `./vendor/bin/phpunit tests/Unit/ --colors=always` — all pass
- [ ] `./vendor/bin/phpcs --standard=phpcs.xml.dist` — no violations
- [ ] `npm run build` — no errors
- [ ] No `wp_ai_mind_is_pro`, `wam_fs`, `ProGate`, or `Freemius` references remain

**After Phase 3: Complete product — zero-friction activation, automatic tier upgrades on purchase, admin UI for usage and upgrades, Pro BYOK key management.**
