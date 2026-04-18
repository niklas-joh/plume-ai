# Phase 1: WordPress Three-Tier Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A complete three-tier system implemented natively in WordPress with user management, payment integration, and rate limiting. Pro BYOK users can use their own API keys immediately. Free/Pro Managed users are blocked until Phase 2 proxy is deployed.

**Architecture:** WordPress-native implementation using WordPress users + custom meta for tier management. LemonSqueezy webhooks to WordPress endpoints for payment processing. ProGate replacement with tier-based logic. All user management and admin UI in WordPress.

**Tech Stack:** PHP 8.1+, WordPress users + meta, LemonSqueezy webhooks, WordPress REST API, WordPress transients, PHPUnit + Brain Monkey

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| User Management | WordPress users + custom meta | WordPress-native, no external auth system |
| Tier Storage | User meta: `wp_ai_mind_tier` | Simple, immediate, cacheable |
| Usage Tracking | User meta: `wp_ai_mind_usage_YYYY_MM` | Monthly reset pattern, WordPress-native |
| Payment Integration | LemonSqueezy webhooks → WordPress REST | Direct integration, no external dependency |
| API Key Protection | Store encrypted in user meta (Pro BYOK only) | Free/Pro Managed use proxy (Phase 2) |
| ProGate Replacement | `NJ_Tier_Manager::user_can()` class methods directly | No global helpers; single entry point per concern |

---

## Four-Tier System

### Tier 1: Free (50k tokens/month)
- Monthly limit: 50,000 tokens (after trial expires)
- Model: Claude Haiku only
- API routing: **Blocked until Phase 2** (proxy required)
- User setup: None required

### Tier 2: Trial (300k tokens, 7-day window)
- Monthly limit: 300,000 tokens within 7 days of `start_trial()`
- Model: Claude Haiku only
- API routing: **Blocked until Phase 2** (proxy required)
- User setup: None; auto-granted on first activation
- Expiry: Daily cron (`wp_ai_mind_trial_check`) demotes expired trials to Free

### Tier 3: Pro Managed (2M tokens/month)
- Monthly limit: 2,000,000 tokens
- Models: Haiku, Sonnet, Opus (user choice)
- API routing: **Blocked until Phase 2** (proxy required)
- User setup: Payment via LemonSqueezy

### Tier 4: Pro BYOK (unlimited)
- Monthly limit: None
- Models: Any model their key supports
- API routing: **Direct to provider** (works in Phase 1)
- User setup: Payment + per-provider encrypted API keys in user meta

---

## Task 0: Pre-implementation Audit

> **Mandatory.** Complete before writing any new PHP classes.

- [ ] **Step 0.1: Audit existing ProGate usage**
```bash
grep -rn "ProGate\|wp_ai_mind_is_pro" includes/ --include="*.php"
# Map all call sites - these will be replaced with nj_can_user() calls
```

- [ ] **Step 0.2: Audit existing UsageLogger**
```bash
find includes/ -name "*Usage*" -o -name "*Logger*" | head -10
# Understand current logging before replacing with WordPress-native approach
```

- [ ] **Step 0.3: Check wp_options usage patterns**
```bash
grep -rn "get_user_meta\|update_user_meta" includes/ --include="*.php" | head -10
# Understand existing patterns before adding tier/usage meta
```

---

## Task 1: Tier Management System

**Files:** Create `includes/Tiers/NJ_Tier_Manager.php`, `tests/Unit/Tiers/NJTierManagerTest.php`

- [ ] **Step 1.1: Write failing tests**

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class NJTierManagerTest extends TestCase {

    public function test_get_user_tier_defaults_to_free() {
        Functions\expect('get_current_user_id')->once()->andReturn(1);
        Functions\expect('get_user_meta')->once()->with(1, 'wp_ai_mind_tier', true)->andReturn('');

        $tier = NJ_Tier_Manager::get_user_tier();
        $this->assertEquals('free', $tier);
    }

    public function test_user_can_chat_free_tier() {
        Functions\expect('get_current_user_id')->once()->andReturn(1);
        Functions\expect('get_user_meta')->once()->andReturn('free');

        $this->assertTrue(NJ_Tier_Manager::user_can('chat'));
        $this->assertFalse(NJ_Tier_Manager::user_can('model_selection'));
    }

    public function test_user_can_all_features_pro_byok() {
        Functions\expect('get_current_user_id')->once()->andReturn(1);
        Functions\expect('get_user_meta')->once()->andReturn('pro_byok');

        $this->assertTrue(NJ_Tier_Manager::user_can('chat'));
        $this->assertTrue(NJ_Tier_Manager::user_can('model_selection'));
        $this->assertTrue(NJ_Tier_Manager::user_can('unlimited'));
    }
}
```

- [ ] **Step 1.2: Implement NJ_Tier_Manager**

```php
<?php
namespace WP_AI_Mind\Tiers;

class NJ_Tier_Manager {

    public static function get_user_tier( $user_id = null ): string {
        $user_id = $user_id ?: get_current_user_id();
        return get_user_meta( $user_id, 'wp_ai_mind_tier', true ) ?: 'free';
    }

    public static function set_user_tier( string $tier, $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $valid_tiers = [ 'free', 'pro_managed', 'pro_byok' ];

        if ( ! in_array( $tier, $valid_tiers, true ) ) {
            return false;
        }

        return update_user_meta( $user_id, 'wp_ai_mind_tier', $tier );
    }

    public static function user_can( string $feature, $user_id = null ): bool {
        $tier = self::get_user_tier( $user_id );

        return match( [$tier, $feature] ) {
            ['free', 'chat'] => true,
            ['free', 'model_selection'] => false,
            ['pro_managed', 'chat'] => true,
            ['pro_managed', 'model_selection'] => true,
            ['pro_byok', 'chat'] => true,
            ['pro_byok', 'model_selection'] => true,
            ['pro_byok', 'unlimited'] => true,
            default => false,
        };
    }

    public static function get_monthly_limits(): array {
        return [
            'free' => 50000,
            'pro_managed' => 2000000,
            'pro_byok' => null, // unlimited
        ];
    }
}
```

- [ ] **Step 1.3: Run tests**
```bash
vendor/bin/phpunit tests/Unit/Tiers/NJTierManagerTest.php --colors=always
```

- [ ] **Step 1.4: Commit**
```bash
git add includes/Tiers/ tests/Unit/Tiers/
git commit -m "feat: add tier management system with WordPress user meta"
```

---

## Task 2: Usage Tracking System

**Files:** Create `includes/Tiers/NJ_Usage_Tracker.php`, `tests/Unit/Tiers/NJUsageTrackerTest.php`

- [ ] **Step 2.1: Write failing tests**

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

class NJUsageTrackerTest extends TestCase {

    public function test_get_current_usage_free_tier() {
        Functions\expect('get_current_user_id')->once()->andReturn(1);
        Functions\expect('get_user_meta')->with(1, 'wp_ai_mind_tier', true)->once()->andReturn('free');
        Functions\expect('get_user_meta')->with(1, \Mockery::pattern('/wp_ai_mind_usage_\d{4}_\d{2}/'), true)->once()->andReturn('25000');

        $usage = NJ_Usage_Tracker::get_current_usage();

        $this->assertEquals('free', $usage['tier']);
        $this->assertEquals(25000, $usage['used']);
        $this->assertEquals(50000, $usage['limit']);
        $this->assertEquals(25000, $usage['remaining']);
        $this->assertTrue($usage['can_use']);
    }

    public function test_check_rate_limit_exceeded() {
        Functions\expect('get_current_user_id')->once()->andReturn(1);
        Functions\expect('get_user_meta')->with(1, 'wp_ai_mind_tier', true)->once()->andReturn('free');
        Functions\expect('get_user_meta')->with(1, \Mockery::pattern('/wp_ai_mind_usage_\d{4}_\d{2}/'), true)->once()->andReturn('55000');

        $this->assertFalse(NJ_Usage_Tracker::check_rate_limit());
    }
}
```

- [ ] **Step 2.2: Implement NJ_Usage_Tracker**

```php
<?php
namespace WP_AI_Mind\Tiers;

class NJ_Usage_Tracker {

    public static function get_current_usage( $user_id = null ): array {
        $user_id = $user_id ?: get_current_user_id();
        $tier = NJ_Tier_Manager::get_user_tier( $user_id );
        $limits = NJ_Tier_Manager::get_monthly_limits();

        $usage_key = 'wp_ai_mind_usage_' . date('Y_m');
        $current_usage = (int) get_user_meta( $user_id, $usage_key, true );
        $monthly_limit = $limits[$tier];

        return [
            'tier' => $tier,
            'used' => $current_usage,
            'limit' => $monthly_limit,
            'remaining' => $monthly_limit ? max(0, $monthly_limit - $current_usage) : null,
            'can_use' => $monthly_limit ? $current_usage < $monthly_limit : true,
        ];
    }

    public static function log_usage( int $tokens, $user_id = null ): void {
        $user_id = $user_id ?: get_current_user_id();
        $usage_key = 'wp_ai_mind_usage_' . date('Y_m');

        $current = (int) get_user_meta( $user_id, $usage_key, true );
        update_user_meta( $user_id, $usage_key, $current + $tokens );
    }

    public static function check_rate_limit( $user_id = null ): bool {
        $usage = self::get_current_usage( $user_id );
        return $usage['can_use'];
    }
}
```

---

## Task 3: LemonSqueezy Integration

**Files:** Create `includes/Payments/NJ_LemonSqueezy.php`, `tests/Unit/Payments/NJLemonSqueezyTest.php`

- [ ] **Step 3.1: Implement webhook handler**

```php
<?php
namespace WP_AI_Mind\Payments;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class NJ_LemonSqueezy {

    public static function register_routes(): void {
        register_rest_route( 'wp-ai-mind/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [ self::class, 'handle_webhook' ],
            'permission_callback' => '__return_true', // Signature verified in handler
        ] );
    }

    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $signature = $request->get_header( 'X-Signature' );
        $body = $request->get_body();

        if ( ! self::verify_signature( $body, $signature ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid signature', [ 'status' => 401 ] );
        }

        $event = json_decode( $body, true );
        $email = $event['data']['attributes']['user_email'] ?? '';

        if ( empty( $email ) ) {
            return new WP_REST_Response( [ 'error' => 'No email in webhook' ], 400 );
        }

        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return new WP_REST_Response( [ 'error' => 'User not found' ], 404 );
        }

        switch ( $event['meta']['event_name'] ) {
            case 'subscription_created':
            case 'subscription_resumed':
                NJ_Tier_Manager::set_user_tier( 'pro_managed', $user->ID );
                break;
            case 'subscription_cancelled':
            case 'subscription_expired':
                NJ_Tier_Manager::set_user_tier( 'free', $user->ID );
                break;
            case 'order_created':
                // Handle one-time BYOK purchase
                NJ_Tier_Manager::set_user_tier( 'pro_byok', $user->ID );
                break;
        }

        return new WP_REST_Response( [ 'received' => true ] );
    }

    private static function verify_signature( string $body, ?string $signature ): bool {
        if ( ! $signature ) return false;

        $secret = defined( 'WP_AI_MIND_LS_SECRET' ) ? WP_AI_MIND_LS_SECRET : '';
        if ( empty( $secret ) ) return false;

        $expected = hash_hmac( 'sha256', $body, $secret );
        return hash_equals( $expected, $signature );
    }
}
```

---

## Task 4: Global Helper Functions

**Files:** Modify `wp-ai-mind.php`

- [ ] **Step 4.1: Add global helpers to main plugin file**

```php
// Add to wp-ai-mind.php after existing code

/**
 * Global helper to get current user's tier
 */
function nj_get_user_tier( $user_id = null ): string {
    return \WP_AI_Mind\Tiers\NJ_Tier_Manager::get_user_tier( $user_id );
}

/**
 * Global helper to check user capabilities
 */
function nj_can_user( string $feature, $user_id = null ): bool {
    return \WP_AI_Mind\Tiers\NJ_Tier_Manager::user_can( $feature, $user_id );
}

/**
 * Global helper to check usage limits
 */
function nj_check_usage_limit( $user_id = null ): bool {
    return \WP_AI_Mind\Tiers\NJ_Usage_Tracker::check_rate_limit( $user_id );
}

/**
 * Global helper to log token usage
 */
function nj_log_usage( int $tokens, $user_id = null ): void {
    \WP_AI_Mind\Tiers\NJ_Usage_Tracker::log_usage( $tokens, $user_id );
}
```

---

## Task 5: Replace ProGate Usage

**Files:** All files containing ProGate references

- [ ] **Step 5.1: Find all ProGate call sites**
```bash
grep -rn "ProGate\|wp_ai_mind_is_pro" includes/ --include="*.php" > progate_sites.txt
```

- [ ] **Step 5.2: Replace ProGate calls systematically**

Replace patterns like:
```php
// OLD:
if ( ProGate::is_pro() ) {
    // feature code
}

// NEW:
if ( nj_can_user( 'specific_feature' ) ) {
    // feature code
}
```

Map ProGate features to tier features:
- `ProGate::is_pro()` → `nj_can_user('chat')` or `nj_can_user('model_selection')`
- Usage checks → `nj_check_usage_limit()`

---

## Task 6: Admin UI for Tier Management

**Files:** Create `includes/Admin/NJ_Tier_Settings.php`

- [ ] **Step 6.1: Create tier management page**

```php
<?php
namespace WP_AI_Mind\Admin;

class NJ_Tier_Settings {

    public static function register_hooks(): void {
        add_action( 'admin_menu', [ self::class, 'add_menu_page' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
    }

    public static function add_menu_page(): void {
        add_options_page(
            __( 'WP AI Mind Tiers', 'wp-ai-mind' ),
            __( 'AI Mind Tiers', 'wp-ai-mind' ),
            'manage_options',
            'wp-ai-mind-tiers',
            [ self::class, 'settings_page' ]
        );
    }

    public static function settings_page(): void {
        $current_user_tier = nj_get_user_tier();
        $usage = \WP_AI_Mind\Tiers\NJ_Usage_Tracker::get_current_usage();

        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <div class="notice notice-info">
                <p><strong>Current Tier:</strong> <?php echo esc_html( ucfirst( $current_user_tier ) ); ?></p>
                <?php if ( $usage['limit'] ): ?>
                    <p><strong>Usage this month:</strong>
                        <?php echo number_format( $usage['used'] ); ?> / <?php echo number_format( $usage['limit'] ); ?> tokens
                        (<?php echo number_format( $usage['remaining'] ); ?> remaining)
                    </p>
                <?php else: ?>
                    <p><strong>Usage:</strong> Unlimited (Pro BYOK)</p>
                <?php endif; ?>
            </div>

            <?php if ( $current_user_tier === 'free' ): ?>
                <div class="card">
                    <h2>Upgrade to Pro</h2>
                    <p>Get higher limits and model selection with Pro Managed, or unlimited usage with Pro BYOK.</p>
                    <a href="https://wpaimind.lemonsqueezy.com/checkout" class="button button-primary">Upgrade Now</a>
                </div>
            <?php endif; ?>

            <?php if ( $current_user_tier === 'pro_byok' ): ?>
                <div class="card">
                    <h2>API Key Configuration</h2>
                    <form method="post" action="options.php">
                        <?php settings_fields( 'wp_ai_mind_api_keys' ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="anthropic_api_key">Anthropic API Key</label></th>
                                <td>
                                    <input type="password" id="anthropic_api_key" name="anthropic_api_key"
                                           value="<?php echo esc_attr( self::get_masked_api_key( 'anthropic' ) ); ?>"
                                           class="regular-text" />
                                    <p class="description">Your API key is encrypted and stored securely.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function get_masked_api_key( string $provider ): string {
        $key = get_user_meta( get_current_user_id(), "wp_ai_mind_api_key_{$provider}", true );
        return $key ? str_repeat( '*', 20 ) . substr( $key, -4 ) : '';
    }
}
```

---

## Phase 1 Acceptance Criteria

- [ ] Four tiers implemented: `free`, `trial`, `pro_managed`, `pro_byok`
- [ ] `NJ_Tier_Config` is the single source of truth for features and limits
- [ ] `NJ_Tier_Manager` handles CRUD + trial start/demote; delegates config to `NJ_Tier_Config`
- [ ] Trial expiry: daily cron `wp_ai_mind_trial_check` demotes expired trial users to `free`
- [ ] WordPress user meta stores tier: `wp_ai_mind_tier`
- [ ] Usage tracking via user meta: `wp_ai_mind_usage_YYYY_MM` (atomic SQL increment)
- [ ] Rate limiting works: `NJ_Usage_Tracker::check_limit()` returns false when exceeded
- [ ] LemonSqueezy webhook endpoint: `/wp-json/wp-ai-mind/v1/webhook` (HMAC verified by `NJ_Webhook_Verifier`)
- [ ] Pro BYOK users can store per-provider encrypted API keys (`wp_ai_mind_api_key_{provider}`)
- [ ] All ProGate calls and global `nj_*` helper calls replaced with direct class method calls
- [ ] `NJ_Tier_Status_Page`: read-only tier + usage display
- [ ] `NJ_Api_Key_Settings`: Pro BYOK key form saves via REST (not options.php)
- [ ] `UsageLogger` deleted; `AbstractProvider::maybe_log()` calls `NJ_Usage_Tracker::log_usage()`
- [ ] Free/Trial/Pro Managed users see "Coming soon" for chat (until Phase 2)
- [ ] Pro BYOK users can use chat with their own API keys immediately

**This phase creates a fully functional tier system. Pro BYOK works end-to-end. Free/Pro Managed wait for Phase 2 proxy.**