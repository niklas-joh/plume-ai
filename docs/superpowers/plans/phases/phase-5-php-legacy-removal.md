# Phase 5: PHP — Freemius + ProGate + UsageLogger Removal

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Freemius SDK, ProGate class, and UsageLogger class are completely removed. All 13 `wp_ai_mind_is_pro()` call sites are replaced with `nj_feature()`. The `wpaim_usage_log` DB table is dropped. The plugin bootstrap is simplified from ~110 lines to ~30.

**Architecture:** Pure cleanup phase. No new functionality added. All feature gating now flows through `NJ_Entitlement::feature()` and `nj_feature()`. This phase must run AFTER Phase 4 is deployed to production and all existing Pro users have migrated to NJ accounts.

**Tech Stack:** PHP 8.1+, Composer, PHPUnit

**Depends on:** Phase 4 in production. Migration notice shown to existing users.

**Critical Warning:** Existing Pro users who purchased via Freemius will lose their Pro status until they create an NJ account and upgrade via LemonSqueezy. Plan a migration period and show the admin notice from Task 1 for at least 2 weeks before removing Freemius.

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Freemius removal | Delete `vendor/freemius/` + `composer.json` entry | Cannot keep SDK that phones home; clean break |
| ProGate removal | Delete file + replace all call sites | No backward compat needed — `nj_feature('own_key')` is the direct replacement |
| `isPro` localized key | Keep as derived alias: `nj_feature('own_key')` | Third-party code or themes may read `wpAiMindData.isPro`; keep the shape |
| UsageLogger DB table | Drop with `maybe_migrate()` on update | Cannot leave orphan tables; migration tracks version with `wpaim_db_version` option |
| UsagePage | Redirect to Worker usage data via `GET /v1/usage` | Preserves the admin UI; data source changes from local DB to Worker KV |

---

## File Map

**Files deleted:**
- `includes/Core/ProGate.php`
- `includes/DB/UsageLogger.php`
- `tests/Unit/Core/ProGateTest.php`

**Files modified:**
- `wp-ai-mind.php` — remove entire Freemius bootstrap block (~53–101), ProGate eager-load
- `composer.json` — remove `freemius/wordpress-sdk` dependency
- `includes/DB/Schema.php` — remove `wpaim_usage_log` table creation, add `maybe_migrate()`
- `includes/Core/Plugin.php` — call `Schema::maybe_migrate()` from `activate()`
- `includes/Admin/SettingsPage.php` + 8 other admin/module files — final `isPro` localize update (already done in Phase 2, but ensure ProGate import is removed)
- `includes/Modules/Generator/GeneratorModule.php` — replace ProGate permission callback
- `includes/Modules/Images/ImagesModule.php` — replace ProGate permission callback
- `includes/Modules/Seo/SeoModule.php` — replace 2× ProGate permission callbacks

---

## Task 1: Migration Notice

**Files:** Modify `includes/Admin/ActivationNotice.php` (or create if it doesn't exist with that hook)

- [ ] **Step 1.1: Add migration notice to the admin**

In `includes/Core/Plugin.php` or `includes/Admin/ActivationNotice.php`, add:

```php
add_action( 'admin_notices', function() {
    // Show notice only on WP AI Mind admin pages.
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'wp-ai-mind' ) === false ) return;

    if ( ! \WP_AI_Mind\Auth\NJ_Auth::is_authenticated() ) {
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e( 'WP AI Mind — Account Required', 'wp-ai-mind' ); ?></strong>
            </p>
            <p>
                <?php esc_html_e( 'WP AI Mind now uses account-based authentication. Please log in or create a free account to continue using AI features.', 'wp-ai-mind' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ai-mind' ) ); ?>">
                    <?php esc_html_e( 'Set up your account →', 'wp-ai-mind' ); ?>
                </a>
            </p>
        </div>
        <?php
    }
} );
```

- [ ] **Step 1.2: Commit**

```bash
git add includes/Core/Plugin.php
git commit -m "feat(admin): add migration notice prompting users to create NJ account"
```

---

## Task 2: Remove Freemius SDK

**Files:** Modify `composer.json`, `wp-ai-mind.php`

- [ ] **Step 2.1: Remove Freemius from `composer.json`**

Open `composer.json`. Remove the entry `"freemius/wordpress-sdk": "^2.13"` (or similar) from `require`.

Then run:
```bash
composer update
# The vendor/freemius/ directory is now gone.
# Expected: Generating autoload files — no Freemius mention
```

Verify: `ls vendor/freemius 2>/dev/null || echo "freemius removed"`

- [ ] **Step 2.2: Remove the `wam_fs()` function block from `wp-ai-mind.php`**

Delete the entire block from approximately line 25 to line 101 that includes:
- The `if ( ! function_exists( 'wam_fs' ) ) { ... }` block containing `fs_dynamic_init()`
- The `wam_fs()` function definition
- The `do_action( 'wam_fs_loaded' )` call
- The `wam_fs()->set_basename(...)` call

The file should reduce to: constants, autoloader, eager requires, activation/deactivation hooks, and `add_action('plugins_loaded', ...)`.

- [ ] **Step 2.3: Verify plugin still loads**

```bash
# On local Docker environment
docker restart blognjohanssoneu-wordpress-1
# Then check the WP admin — plugin should activate without errors
# Check PHP error log: docker exec blognjohanssoneu-wordpress-1 tail -50 /var/log/apache2/error.log
```

- [ ] **Step 2.4: Commit**

```bash
git add composer.json wp-ai-mind.php composer.lock
git commit -m "feat(cleanup): remove Freemius SDK from composer and plugin bootstrap"
```

---

## Task 3: Delete ProGate + Replace All Call Sites

**Files:** Delete `includes/Core/ProGate.php`, modify 9 files

- [ ] **Step 3.1: Replace ProGate call sites**

Replace every occurrence of `\wp_ai_mind_is_pro()` or `wp_ai_mind_is_pro()` with the appropriate `nj_feature()` call. There are 13 call sites across these files:

**`includes/Admin/SettingsPage.php`, `ChatPage.php`, `DashboardPage.php`, `GeneratorPage.php`**
and
**`includes/Modules/Editor/EditorModule.php`, `FrontendWidgetModule.php`, `GeneratorModule.php`, `ImagesModule.php`, `SeoModule.php`, `UsageModule.php`**

For localized script data (all pages):
```php
// Before:
'isPro' => \wp_ai_mind_is_pro(),

// After (already done in Phase 2 — confirm it's in place):
'isPro'       => \nj_feature( 'own_key' ),
'entitlement' => \WP_AI_Mind\Entitlement\NJ_Entitlement::get(),
```

For permission callbacks in REST route registrations:

**`includes/Modules/Generator/GeneratorModule.php:55`:**
```php
// Before:
'permission_callback' => fn() => \wp_ai_mind_is_pro() && \current_user_can( 'edit_posts' ),

// After:
'permission_callback' => fn() => \nj_feature( 'generator' ) && \current_user_can( 'edit_posts' ),
```

**`includes/Modules/Images/ImagesModule.php:65`:**
```php
// Before:
'permission_callback' => fn() => \wp_ai_mind_is_pro() && \current_user_can( 'edit_posts' ),

// After:
'permission_callback' => fn() => \nj_feature( 'images' ) && \current_user_can( 'edit_posts' ),
```

**`includes/Modules/Seo/SeoModule.php:69` and `:86`:**
```php
// Before:
'permission_callback' => fn() => \wp_ai_mind_is_pro() && \current_user_can( 'edit_posts' ),

// After:
'permission_callback' => fn() => \nj_feature( 'seo' ) && \current_user_can( 'edit_posts' ),
```

- [ ] **Step 3.2: Remove ProGate eager-load from `wp-ai-mind.php`**

Delete this line:
```php
require_once WP_AI_MIND_DIR . 'includes/Core/ProGate.php';
```

- [ ] **Step 3.3: Delete `includes/Core/ProGate.php`**

```bash
rm includes/Core/ProGate.php
```

- [ ] **Step 3.4: Delete `tests/Unit/Core/ProGateTest.php`**

```bash
rm tests/Unit/Core/ProGateTest.php
```

- [ ] **Step 3.5: Verify no remaining references**

```bash
grep -r "wp_ai_mind_is_pro\|ProGate\|wam_fs\|freemius" . \
  --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees
# Expected: NO output (zero matches)
```

- [ ] **Step 3.6: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: All tests PASS (ProGateTest is deleted, remaining 130+ tests pass)
```

- [ ] **Step 3.7: Commit**

```bash
git add -A
git commit -m "feat(cleanup): remove ProGate class and replace all call sites with nj_feature()"
```

---

## Task 4: Remove UsageLogger + Drop DB Table

**Files:** Delete `includes/DB/UsageLogger.php`, modify `includes/DB/Schema.php`, `includes/Core/Plugin.php`

- [ ] **Step 4.1: Delete `includes/DB/UsageLogger.php`**

```bash
rm includes/DB/UsageLogger.php
```

- [ ] **Step 4.2: Update `includes/DB/Schema.php` — remove table creation, add migration**

Remove the entire `$usage_log` dbDelta block from the `create_tables()` method. Then add:

```php
/**
 * Runs on plugin update. Drops legacy tables that have been superseded.
 * Uses a version flag in wp_options to run each migration only once.
 */
public static function maybe_migrate(): void {
    $current = (int) get_option( 'wpaim_db_version', 1 );

    if ( $current < 2 ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
        $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wpaim_usage_log' );
        update_option( 'wpaim_db_version', 2 );
    }
}
```

- [ ] **Step 4.3: Call `maybe_migrate()` from `includes/Core/Plugin.php`**

In `Plugin::activate()`, add:
```php
\WP_AI_Mind\DB\Schema::maybe_migrate();
```

Also add a `plugins_loaded` hook in the Plugin constructor for running on updates:
```php
add_action( 'plugins_loaded', [ 'WP_AI_Mind\DB\Schema', 'maybe_migrate' ] );
```

- [ ] **Step 4.4: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: All remaining tests PASS
```

- [ ] **Step 4.5: Run phpcs**

```bash
./vendor/bin/phpcs --standard=phpcs.xml.dist
# Expected: 0 errors, 0 warnings (or only known pre-existing warnings)
```

- [ ] **Step 4.6: Commit**

```bash
git add includes/DB/UsageLogger.php includes/DB/Schema.php includes/Core/Plugin.php
git commit -m "feat(cleanup): remove UsageLogger, drop wpaim_usage_log table via migration"
```

---

## Task 5: Final Verification

- [ ] **Step 5.1: Verify complete cleanup**

```bash
# All of these must return ZERO matches:
grep -r "wp_ai_mind_is_pro" . --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees
grep -r "ProGate" .           --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees
grep -r "UsageLogger" .       --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees
grep -r "wam_fs" .            --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees
grep -r "freemius" .          --include="*.php" --exclude-dir=vendor --exclude-dir=.worktrees

# All expected to output: (no results)
```

- [ ] **Step 5.2: Verify DB migration works on local**

```bash
# Connect to local DB and verify table does not exist after migration:
docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress \
  -e "SHOW TABLES LIKE 'wpaim_usage_log';"
# Expected: Empty result set
```

- [ ] **Step 5.3: Run npm build to verify JS still compiles**

```bash
npm run build
# Expected: No errors; assets/ directory updated
```

- [ ] **Step 5.4: Commit final state**

```bash
git add -A
git commit -m "chore(cleanup): Phase 5 complete — Freemius, ProGate, UsageLogger fully removed"
```

---

## Phase 5 Acceptance Criteria

- [ ] `vendor/freemius/` directory does not exist
- [ ] `includes/Core/ProGate.php` does not exist
- [ ] `includes/DB/UsageLogger.php` does not exist
- [ ] `grep -r "wp_ai_mind_is_pro" . --include="*.php" --exclude-dir=vendor` → zero matches
- [ ] `grep -r "wam_fs" . --include="*.php" --exclude-dir=vendor` → zero matches
- [ ] `wpaim_usage_log` table absent on fresh install and dropped via `maybe_migrate()` on update
- [ ] All REST permission callbacks use `nj_feature()` checks
- [ ] `./vendor/bin/phpunit tests/Unit/ --colors=always` → all pass
- [ ] `./vendor/bin/phpcs --standard=phpcs.xml.dist` → no errors
- [ ] `npm run build` → no errors

---

## Phase 5 Risk Notes

- **Existing Pro users lose access immediately on deploy.** Plan a migration period (minimum 2 weeks with the admin notice from Task 1 visible). Consider sending an email to all Freemius customers with a link to create an NJ account.
- **`composer update` after removing Freemius may change other transitive dependencies.** Run `composer install --no-dev` in CI to catch unexpected changes before merging.
- **`phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange`** is required on the DROP TABLE statement. This is intentional and safe — the comment is the documented exception.
