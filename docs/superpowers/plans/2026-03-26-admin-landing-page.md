# Admin Landing Page & Onboarding Modal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Dashboard landing page as the plugin's new top-level entry point, with a first-run onboarding modal that guides users through API connection and model provider selection.

**Architecture:** A new `DashboardPage` PHP class follows the existing `ChatPage` pattern (inline render + enqueue). The dashboard React app mounts to `#stilus-dashboard` and is added to the existing `src/admin/index.js` multi-mount pattern. A new `OnboardingRestController` provides one endpoint (`POST /stilus/v1/onboarding`) for saving onboarding state and resetting it.

**Tech Stack:** PHP 8.1, WordPress hooks/options API, React (via `@wordpress/element`), existing CSS custom properties in `src/styles/tokens.css`, Lucide React icons (already a dependency).

**Spec:** `docs/superpowers/specs/2026-03-26-admin-landing-page-design.md`

---

## File Map

| Action | Path | Responsibility |
|---|---|---|
| Modify | `includes/Admin/AdminMenu.php` | Swap top-level callback to Dashboard; add Chat as `stilus-chat` sub-page |
| Create | `includes/Admin/DashboardPage.php` | Render mount div; enqueue admin bundle; localize dashboard data |
| Modify | `includes/Core/Plugin.php` | Register OnboardingRestController on `wp_ai_mind_register_rest_routes` |
| Create | `includes/Admin/OnboardingRestController.php` | `POST /stilus/v1/onboarding` — save/reset onboarding state + provider key |
| Modify | `src/admin/index.js` | Add `#stilus-dashboard` mount point |
| Create | `src/admin/dashboard/DashboardApp.jsx` | Root dashboard component; reads `window.wpAiMindDashboard`; manages modal state |
| Create | `src/admin/dashboard/StatusBanner.jsx` | Conditional amber/red banner based on `bannerState` prop |
| Create | `src/admin/dashboard/StartTiles.jsx` | Four action tiles linking to plugin features |
| Create | `src/admin/dashboard/ResourceList.jsx` | Four external resource links |
| Create | `src/admin/dashboard/PageFooter.jsx` | Footer strip: Settings, Run setup again, Docs, Support |
| Create | `src/admin/dashboard/OnboardingModal.jsx` | Multi-step modal: Step1 (connection) → Step2 (provider) → Done |
| Create | `src/admin/dashboard/dashboard.css` | Dashboard-specific styles using existing `--color-*` tokens |

---

## Task 1: Restructure admin navigation

**Files:**
- Modify: `includes/Admin/AdminMenu.php`

The top-level menu now renders the Dashboard. Chat moves to its own sub-slug `stilus-chat`. All other sub-slugs are unchanged.

- [ ] **Step 1: Update AdminMenu.php**

Replace the full contents of `includes/Admin/AdminMenu.php`:

```php
<?php
declare( strict_types=1 );
namespace Stilus\Admin;

class AdminMenu {

	public static function register(): void {
		add_menu_page(
			__( 'Stilus', 'stilus' ),
			__( 'AI Mind', 'stilus' ),
			'edit_posts',
			'stilus',
			[ DashboardPage::class, 'render' ],
			self::get_menu_icon(),
			30
		);

		// First submenu entry must share parent slug — WordPress uses it to rename
		// the parent item in the submenu list. Label it "Dashboard".
		add_submenu_page( 'stilus', __( 'Dashboard', 'stilus' ), __( 'Dashboard', 'stilus' ), 'edit_posts', 'stilus', [ DashboardPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Chat', 'stilus' ), __( 'Chat', 'stilus' ), 'edit_posts', 'stilus-chat', [ ChatPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Generator', 'stilus' ), __( 'Generator', 'stilus' ), 'edit_posts', 'stilus-generator', [ GeneratorPage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'SEO', 'stilus' ), __( 'SEO', 'stilus' ), 'edit_posts', 'stilus-seo', '__return_false' );
		add_submenu_page( 'stilus', __( 'Images', 'stilus' ), __( 'Images', 'stilus' ), 'edit_posts', 'stilus-images', '__return_false' );
		add_submenu_page( 'stilus', __( 'Usage', 'stilus' ), __( 'Usage &amp; Cost', 'stilus' ), 'manage_options', 'stilus-usage', [ UsagePage::class, 'render' ] );
		add_submenu_page( 'stilus', __( 'Settings', 'stilus' ), __( 'Settings', 'stilus' ), 'manage_options', 'stilus-settings', [ SettingsPage::class, 'render' ] );
	}

	/** Inline SVG — Lucide `sparkles` icon, zinc-400 (#a1a1aa). */
	private static function get_menu_icon(): string {
		return 'data:image/svg+xml;base64,' . base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a1a1aa" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>'
		);
	}
}
```

- [ ] **Step 2: Verify in browser**

Navigate to WP Admin → AI Mind. Confirm:
- The top-level item now shows "Dashboard" as the first sub-item in the dropdown.
- "Chat" appears as a separate sub-item.
- Clicking "Chat" navigates to `admin.php?page=stilus-chat` (blank page or old chat page — either is fine at this stage).
- All other sub-items remain.

- [ ] **Step 3: Commit**

```bash
git add includes/Admin/AdminMenu.php
git commit -m "feat(dashboard): restructure admin menu — Dashboard as top-level entry point"
```

---

## Task 2: Create DashboardPage PHP class

**Files:**
- Create: `includes/Admin/DashboardPage.php`

Follows the exact pattern of `ChatPage.php`. Enqueues the same `stilus-admin` bundle, adds a second `wp_localize_script` call with a `wpAiMindDashboard` variable.

- [ ] **Step 1: Create the file**

```php
<?php
declare( strict_types=1 );
namespace Stilus\Admin;

use Stilus\Settings\ProviderSettings;

class DashboardPage {

	public static function render(): void {
		// Handle "Run setup again" — nonce-protected GET action.
		if (
			isset( $_GET['run_setup'], $_GET['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpaim_run_setup' ) &&
			current_user_can( 'edit_posts' )
		) {
			delete_option( 'wp_ai_mind_onboarding_seen' );
		}

		self::enqueue_assets();
		echo '<div id="stilus-dashboard" class="stilus-page"></div>';
	}

	private static function enqueue_assets(): void {
		$asset_file = WP_AI_MIND_DIR . 'assets/admin/index.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [
				'dependencies' => [],
				'version'      => WP_AI_MIND_VERSION,
			];

		wp_enqueue_script(
			'stilus-admin',
			WP_AI_MIND_URL . 'assets/admin/index.js',
			array_merge( $asset['dependencies'], [ 'wp-element', 'wp-i18n', 'wp-api-fetch' ] ),
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'stilus-admin',
			WP_AI_MIND_URL . 'assets/admin/index.css',
			[],
			$asset['version']
		);

		wp_localize_script(
			'stilus-admin',
			'wpAiMindDashboard',
			self::get_dashboard_data()
		);
	}

	private static function get_dashboard_data(): array {
		$provider_settings = new ProviderSettings();
		$provider          = (string) get_option( 'wp_ai_mind_default_provider', '' );
		$has_own_key       = $provider && $provider_settings->has_key( $provider );
		$is_pro            = \wp_ai_mind_is_pro();

		if ( $is_pro || $has_own_key ) {
			$banner_state = 'none';
		} else {
			$banner_state = 'free_tier';
		}

		return [
			'bannerState'    => $banner_state,
			'onboardingSeen' => (bool) get_option( 'wp_ai_mind_onboarding_seen', false ),
			'isPro'          => $is_pro,
			'version'        => WP_AI_MIND_VERSION,
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'restUrl'        => esc_url_raw( rest_url( 'stilus/v1' ) ),
			'runSetupUrl'    => wp_nonce_url(
				admin_url( 'admin.php?page=stilus&run_setup=1' ),
				'wpaim_run_setup'
			),
			'urls'           => [
				'chat'      => admin_url( 'admin.php?page=stilus-chat' ),
				'generator' => admin_url( 'admin.php?page=stilus-generator' ),
				'images'    => admin_url( 'admin.php?page=stilus-images' ),
				'seo'       => admin_url( 'admin.php?page=stilus-seo' ),
				'usage'     => admin_url( 'admin.php?page=stilus-usage' ),
				'settings'  => admin_url( 'admin.php?page=stilus-settings' ),
				'posts'     => admin_url( 'edit.php' ),
				'upgrade'   => 'https://[TODO-stilus-domain]/pricing',
			],
			'resourceUrls'   => [
				'gettingStarted' => 'https://[TODO-stilus-domain]/docs/getting-started',
				'promptTips'     => 'https://[TODO-stilus-domain]/docs/prompt-tips',
				'apiKeySetup'    => 'https://[TODO-stilus-domain]/docs/api-key-setup',
				'changelog'      => 'https://[TODO-stilus-domain]/changelog',
			],
		];
	}
}
```

- [ ] **Step 2: Verify page loads**

Navigate to WP Admin → AI Mind. The page should render without PHP errors (blank React mount div at this point — JS not wired yet).

Check browser console — `window.wpAiMindDashboard` should exist with the expected keys.

- [ ] **Step 3: Commit**

```bash
git add includes/Admin/DashboardPage.php
git commit -m "feat(dashboard): add DashboardPage PHP class with localized data"
```

---

## Task 3: Create OnboardingRestController

**Files:**
- Create: `includes/Admin/OnboardingRestController.php`
- Modify: `includes/Core/Plugin.php`

One endpoint: `POST /stilus/v1/onboarding`. Handles both saving (completes onboarding) and resetting (run setup again).

- [ ] **Step 1: Create the controller**

```php
<?php
declare( strict_types=1 );
namespace Stilus\Admin;

use Stilus\Settings\ProviderSettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class OnboardingRestController {

	public static function register_routes(): void {
		register_rest_route(
			'stilus/v1',
			'/onboarding',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ self::class, 'save' ],
				'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
				'args'                => [
					'seen'           => [
						'type'     => 'boolean',
						'required' => false,
					],
					'provider'       => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'openai', 'claude', 'gemini' ],
					],
					'api_key'        => [
						'type'     => 'string',
						'required' => false,
					],
					'image_provider' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'enum'              => [ 'openai', 'gemini' ],
					],
				],
			]
		);
	}

	public static function save( WP_REST_Request $request ): WP_REST_Response {
		$seen = $request->get_param( 'seen' );

		if ( true === $seen ) {
			update_option( 'wp_ai_mind_onboarding_seen', true );
		} elseif ( false === $seen ) {
			delete_option( 'wp_ai_mind_onboarding_seen' );
		}

		$provider = $request->get_param( 'provider' );
		if ( $provider ) {
			update_option( 'wp_ai_mind_default_provider', $provider );

			$api_key = $request->get_param( 'api_key' );
			if ( $api_key ) {
				$provider_settings = new ProviderSettings();
				$provider_settings->set_api_key( $provider, $api_key );
			}
		}

		$image_provider = $request->get_param( 'image_provider' );
		if ( $image_provider ) {
			update_option( 'wp_ai_mind_image_provider', $image_provider );
		}

		return new WP_REST_Response( [ 'success' => true ], 200 );
	}
}
```

- [ ] **Step 2: Register the route in Plugin.php**

In `includes/Core/Plugin.php`, add one line to `init_hooks()` after the existing `add_action( 'rest_api_init', ... )` line:

```php
add_action( 'wp_ai_mind_register_rest_routes', [ \Stilus\Admin\OnboardingRestController::class, 'register_routes' ] );
```

The full `init_hooks()` method after the change:

```php
private function init_hooks(): void {
    add_action( 'init', [ $this, 'load_textdomain' ] );
    add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
    add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    add_action( 'wp_ai_mind_register_menu', [ \Stilus\Admin\AdminMenu::class, 'register' ] );
    add_action( 'wp_ai_mind_register_rest_routes', [ \Stilus\Admin\OnboardingRestController::class, 'register_routes' ] );
    \Stilus\Admin\ActivationNotice::register();
    if ( $this->modules->is_enabled( 'chat' ) ) {
        add_action( 'plugins_loaded', [ \Stilus\Modules\Chat\ChatModule::class, 'register' ], 20 );
        \Stilus\Modules\Editor\EditorModule::register();
    }
    if ( $this->modules->is_enabled( 'generator' ) ) {
        \Stilus\Modules\Generator\GeneratorModule::register();
    }
    if ( $this->modules->is_enabled( 'frontend_widget' ) ) {
        \Stilus\Modules\Frontend\FrontendWidgetModule::register();
    }
    if ( $this->modules->is_enabled( 'usage' ) ) {
        \Stilus\Modules\Usage\UsageModule::register();
    }
}
```

- [ ] **Step 3: Verify endpoint exists**

```bash
curl -s -X POST \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: $(wp eval 'echo wp_create_nonce("wp_rest");' --allow-root 2>/dev/null || echo 'get-from-browser')" \
  "http://localhost:8080/wp-json/stilus/v1/onboarding" \
  -d '{"seen": false}'
```

Expected response: `{"success":true}`

Alternatively: in WP Admin, open browser DevTools Console and run:
```javascript
fetch('/wp-json/stilus/v1/onboarding', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': wpAiMindDashboard.nonce },
  body: JSON.stringify({ seen: false })
}).then(r => r.json()).then(console.log)
```

Expected: `{success: true}`

- [ ] **Step 4: Commit**

```bash
git add includes/Admin/OnboardingRestController.php includes/Core/Plugin.php
git commit -m "feat(dashboard): add OnboardingRestController — POST /stilus/v1/onboarding"
```

---

## Task 4: Wire React mount point

**Files:**
- Modify: `src/admin/index.js`

- [ ] **Step 1: Add dashboard mount to index.js**

```javascript
import { render } from '@wordpress/element';
import ChatApp from './components/Chat/ChatApp';
import SettingsApp from './settings/SettingsApp';
import DashboardApp from './dashboard/DashboardApp';
import '../styles/tokens.css';
import './admin.css';

const chatRoot = document.getElementById( 'stilus-chat' );
if ( chatRoot ) {
    render( <ChatApp />, chatRoot );
}

const settingsRoot = document.getElementById( 'stilus-settings' );
if ( settingsRoot ) {
    render( <SettingsApp />, settingsRoot );
}

const dashboardRoot = document.getElementById( 'stilus-dashboard' );
if ( dashboardRoot ) {
    render( <DashboardApp />, dashboardRoot );
}
```

- [ ] **Step 2: Create stub DashboardApp so the build doesn't fail**

Create `src/admin/dashboard/DashboardApp.jsx`:

```jsx
export default function DashboardApp() {
    return <div className="wpaim-dashboard">Loading…</div>;
}
```

- [ ] **Step 3: Build and verify**

```bash
npm run build
```

Expected: build succeeds with no errors.

Navigate to WP Admin → AI Mind. The page should render "Loading…" text inside the dashboard div.

- [ ] **Step 4: Commit**

```bash
git add src/admin/index.js src/admin/dashboard/DashboardApp.jsx
git commit -m "feat(dashboard): wire dashboard React mount point"
```

---

## Task 5: Dashboard CSS

**Files:**
- Create: `src/admin/dashboard/dashboard.css`

Uses existing `--color-*` and `--space-*` tokens. Import it in `DashboardApp.jsx`.

- [ ] **Step 1: Create dashboard.css**

```css
/* ── Dashboard shell ──────────────────────────────────────────────────────── */

.wpaim-dashboard {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    background: var(--color-bg);
    color: var(--color-text-primary);
    font-family: var(--font-sans, -apple-system, sans-serif);
    -webkit-font-smoothing: antialiased;
}

/* Top bar */
.wpaim-dash-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-4) var(--space-6) var(--space-3);
    border-bottom: 1px solid var(--color-border-subtle);
    flex-shrink: 0;
}

.wpaim-dash-title {
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--color-text-primary);
    letter-spacing: -0.025em;
}

.wpaim-dash-subtitle {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin-top: 2px;
}

.wpaim-dash-version {
    font-family: var(--font-mono, monospace);
    font-size: 0.625rem;
    padding: 3px 8px;
    border-radius: var(--radius-sm);
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    color: var(--color-text-muted);
}

/* ── Status banner ──────────────────────────────────────────────────────────── */

.wpaim-dash-banner {
    margin: var(--space-4) var(--space-6) 0;
    border-radius: var(--radius);
    padding: var(--space-2) var(--space-3);
    display: flex;
    align-items: center;
    gap: var(--space-3);
    font-size: 0.75rem;
}

.wpaim-dash-banner--warning {
    background: rgba(217, 119, 6, 0.07);
    border: 1px solid rgba(217, 119, 6, 0.2);
}

.wpaim-dash-banner--error {
    background: rgba(220, 38, 38, 0.07);
    border: 1px solid rgba(220, 38, 38, 0.2);
}

.wpaim-dash-banner__dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}

.wpaim-dash-banner--warning .wpaim-dash-banner__dot {
    background: var(--color-warning);
    box-shadow: 0 0 5px rgba(217, 119, 6, 0.5);
}

.wpaim-dash-banner--error .wpaim-dash-banner__dot {
    background: var(--color-error);
    box-shadow: 0 0 5px rgba(220, 38, 38, 0.5);
}

.wpaim-dash-banner__text strong {
    color: var(--color-text-primary);
    font-weight: 600;
}

.wpaim-dash-banner__text span {
    color: var(--color-text-secondary);
}

.wpaim-dash-banner__actions {
    margin-left: auto;
    display: flex;
    gap: var(--space-1);
    flex-shrink: 0;
}

/* ── Page body ──────────────────────────────────────────────────────────────── */

.wpaim-dash-body {
    flex: 1;
    padding: var(--space-5) var(--space-6) 0;
    display: flex;
    flex-direction: column;
    gap: var(--space-5);
}

.wpaim-dash-section-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    margin-bottom: var(--space-2);
}

.wpaim-dash-section-title {
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-family: var(--font-mono, monospace);
}

/* ── Start tiles ────────────────────────────────────────────────────────────── */

.wpaim-dash-tiles {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-2);
}

.wpaim-dash-tile {
    background: var(--color-surface);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius);
    padding: var(--space-3) var(--space-3) var(--space-7);
    cursor: pointer;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    display: block;
    transition: border-color 0.12s, background 0.12s, transform 0.1s;
}

.wpaim-dash-tile::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.05), transparent);
}

.wpaim-dash-tile:hover {
    border-color: var(--color-border);
    background: var(--color-surface-2);
    transform: translateY(-1px);
    text-decoration: none;
}

.wpaim-dash-tile:hover .wpaim-dash-tile__arrow {
    color: var(--color-accent);
    transform: translate(2px, -2px);
}

.wpaim-dash-tile--primary {
    border-color: var(--color-accent-border);
    background: var(--color-accent-subtle);
}

.wpaim-dash-tile--primary:hover {
    border-color: rgba(37, 99, 235, 0.45);
    background: rgba(37, 99, 235, 0.15);
}

.wpaim-dash-tile__verb {
    font-size: 0.8125rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: var(--space-1);
    letter-spacing: -0.01em;
}

.wpaim-dash-tile__desc {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.45;
    padding-right: var(--space-3);
}

.wpaim-dash-tile__arrow {
    position: absolute;
    bottom: var(--space-3);
    right: var(--space-3);
    font-size: 0.8125rem;
    color: var(--color-border);
    transition: color 0.12s, transform 0.12s;
}

/* ── Resource list ──────────────────────────────────────────────────────────── */

.wpaim-dash-resources {
    background: var(--color-surface);
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius);
    overflow: hidden;
}

.wpaim-dash-resource {
    display: flex;
    align-items: center;
    padding: var(--space-2) var(--space-3);
    border-bottom: 1px solid var(--color-border-subtle);
    text-decoration: none;
    transition: background 0.1s;
    gap: var(--space-3);
}

.wpaim-dash-resource:last-child {
    border-bottom: none;
}

.wpaim-dash-resource:hover {
    background: var(--color-surface-2);
    text-decoration: none;
}

.wpaim-dash-resource:hover .wpaim-dash-resource__arrow {
    color: var(--color-accent);
}

.wpaim-dash-resource__body {
    flex: 1;
}

.wpaim-dash-resource__title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1px;
}

.wpaim-dash-resource__desc {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.4;
}

.wpaim-dash-resource__arrow {
    font-size: 0.75rem;
    color: var(--color-text-muted);
    transition: color 0.12s;
    flex-shrink: 0;
}

/* ── Page footer ────────────────────────────────────────────────────────────── */

.wpaim-dash-footer {
    padding: var(--space-3) var(--space-6) var(--space-4);
    margin-top: auto;
    display: flex;
    align-items: center;
    gap: var(--space-3);
    border-top: 1px solid var(--color-border-subtle);
}

.wpaim-dash-footer__link {
    font-size: 0.6875rem;
    color: var(--color-text-muted);
    text-decoration: none;
    transition: color 0.1s;
    cursor: pointer;
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
}

.wpaim-dash-footer__link:hover {
    color: var(--color-text-secondary);
    text-decoration: none;
}

.wpaim-dash-footer__sep {
    width: 1px;
    height: 11px;
    background: var(--color-border-subtle);
    flex-shrink: 0;
}

/* ── Buttons (shared small) ─────────────────────────────────────────────────── */

.wpaim-dash-btn {
    font-size: 0.6875rem;
    font-weight: 600;
    padding: 5px 10px;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-family: inherit;
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    color: var(--color-text-secondary);
    text-decoration: none;
    white-space: nowrap;
    transition: border-color 0.12s, color 0.12s;
}

.wpaim-dash-btn--primary {
    background: var(--color-accent);
    border-color: var(--color-accent);
    color: #fff;
}

.wpaim-dash-btn--primary:hover {
    background: var(--color-accent-hover);
    border-color: var(--color-accent-hover);
    color: #fff;
    text-decoration: none;
}

/* ── Onboarding modal ───────────────────────────────────────────────────────── */

.wpaim-ob-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 100000;
    backdrop-filter: blur(2px);
}

.wpaim-ob-modal {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-lg, 12px);
    width: 480px;
    max-width: calc(100vw - var(--space-6));
    box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5);
    overflow: hidden;
}

.wpaim-ob-header {
    padding: var(--space-4) var(--space-5) var(--space-3);
    border-bottom: 1px solid var(--color-border-subtle);
}

.wpaim-ob-pips {
    display: flex;
    gap: var(--space-1);
    margin-bottom: var(--space-3);
}

.wpaim-ob-pip {
    height: 2px;
    flex: 1;
    border-radius: 2px;
    background: var(--color-border-subtle);
    transition: background 0.2s;
}

.wpaim-ob-pip--active { background: var(--color-accent); }
.wpaim-ob-pip--done   { background: var(--color-success); }

.wpaim-ob-title {
    font-size: 0.9375rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: 3px;
}

.wpaim-ob-sub {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
}

.wpaim-ob-body {
    padding: var(--space-3) var(--space-5);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.wpaim-ob-footer {
    padding: var(--space-2) var(--space-5) var(--space-3);
    border-top: 1px solid var(--color-border-subtle);
    display: flex;
    align-items: center;
    justify-content: space-between;
}

/* Choice radio cards */
.wpaim-ob-choice {
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius);
    padding: var(--space-2) var(--space-3);
    display: flex;
    align-items: flex-start;
    gap: var(--space-2);
    background: var(--color-bg);
    cursor: pointer;
    transition: border-color 0.12s, background 0.12s;
}

.wpaim-ob-choice:hover {
    border-color: var(--color-border);
    background: var(--color-surface-2);
}

.wpaim-ob-choice--selected {
    border-color: var(--color-accent-border);
    background: var(--color-accent-subtle);
}

.wpaim-ob-radio {
    width: 15px;
    height: 15px;
    border-radius: 50%;
    border: 1.5px solid var(--color-border);
    margin-top: 1px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color 0.12s, background 0.12s;
}

.wpaim-ob-choice--selected .wpaim-ob-radio {
    border-color: var(--color-accent);
    background: var(--color-accent);
}

.wpaim-ob-radio__dot {
    display: none;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #fff;
}

.wpaim-ob-choice--selected .wpaim-ob-radio__dot {
    display: block;
}

.wpaim-ob-choice__title {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 2px;
}

.wpaim-ob-choice__desc {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.4;
}

/* Provider cards (Step 2) */
.wpaim-ob-section-label {
    font-size: 0.625rem;
    font-weight: 600;
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    margin-top: var(--space-1);
    margin-bottom: var(--space-1);
}

.wpaim-ob-providers {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: var(--space-1);
}

.wpaim-ob-provider {
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius);
    padding: var(--space-2) var(--space-2) var(--space-1);
    background: var(--color-bg);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    gap: 3px;
    position: relative;
    transition: border-color 0.12s, background 0.12s;
}

.wpaim-ob-provider:hover {
    border-color: var(--color-border);
}

.wpaim-ob-provider--selected {
    border-color: var(--color-accent-border);
    background: var(--color-accent-subtle);
}

.wpaim-ob-provider__check {
    display: none;
    position: absolute;
    top: 6px;
    right: 6px;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    background: var(--color-accent);
    align-items: center;
    justify-content: center;
    font-size: 8px;
    color: #fff;
}

.wpaim-ob-provider--selected .wpaim-ob-provider__check {
    display: flex;
}

.wpaim-ob-provider__name {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.wpaim-ob-provider__link {
    font-size: 0.625rem;
    color: var(--color-accent);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 2px;
}

.wpaim-ob-provider__link:hover {
    text-decoration: underline;
}

/* API key input */
.wpaim-ob-key-input {
    width: 100%;
    background: var(--color-bg);
    border: 1px solid var(--color-border);
    border-radius: var(--radius);
    padding: var(--space-2) var(--space-2);
    font-size: 0.6875rem;
    color: var(--color-text-primary);
    font-family: var(--font-mono, monospace);
    transition: border-color 0.12s;
}

.wpaim-ob-key-input:focus {
    outline: none;
    border-color: var(--color-accent-border);
}

.wpaim-ob-key-input::placeholder {
    color: var(--color-text-muted);
}

/* Optional collapsible */
.wpaim-ob-optional {
    border: 1px solid var(--color-border-subtle);
    border-radius: var(--radius);
    background: var(--color-bg);
    overflow: hidden;
}

.wpaim-ob-optional__toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-2) var(--space-3);
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    gap: var(--space-2);
}

.wpaim-ob-optional__label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-secondary);
}

.wpaim-ob-optional__tag {
    font-size: 0.5625rem;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 3px;
    background: var(--color-border-subtle);
    color: var(--color-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.wpaim-ob-optional__chevron {
    color: var(--color-text-muted);
    font-size: 0.6875rem;
    margin-left: auto;
    transition: transform 0.15s;
}

.wpaim-ob-optional__chevron--open {
    transform: rotate(180deg);
}

.wpaim-ob-optional__body {
    padding: var(--space-2) var(--space-3) var(--space-3);
    border-top: 1px solid var(--color-border-subtle);
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
}

.wpaim-ob-optional__desc {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.4;
}

/* Dev note */
.wpaim-ob-devnote {
    display: flex;
    gap: var(--space-2);
    align-items: flex-start;
    background: rgba(234, 179, 8, 0.06);
    border: 1px solid rgba(234, 179, 8, 0.2);
    border-radius: var(--radius);
    padding: var(--space-2) var(--space-2);
    font-size: 0.625rem;
    color: #ca8a04;
    line-height: 1.5;
    margin-top: var(--space-1);
}

.wpaim-ob-devnote code {
    background: rgba(0, 0, 0, 0.3);
    padding: 1px 4px;
    border-radius: 3px;
    font-size: 0.5625rem;
}

/* Success / Done */
.wpaim-ob-success {
    text-align: center;
    padding: var(--space-3) 0 var(--space-1);
}

.wpaim-ob-success__title {
    font-size: 0.875rem;
    font-weight: 700;
    color: var(--color-text-primary);
    margin-bottom: var(--space-1);
}

.wpaim-ob-success__sub {
    font-size: 0.6875rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: var(--space-3);
}

.wpaim-ob-success__cta {
    display: block;
    width: 100%;
    padding: var(--space-2) 0;
    background: var(--color-success);
    border: none;
    border-radius: var(--radius);
    font-size: 0.75rem;
    font-weight: 600;
    color: #fff;
    cursor: pointer;
    text-align: center;
    text-decoration: none;
    font-family: inherit;
    transition: background 0.12s;
}

.wpaim-ob-success__cta:hover {
    background: #15803d;
    color: #fff;
    text-decoration: none;
}

/* Skip / back button */
.wpaim-ob-skip {
    font-size: 0.6875rem;
    color: var(--color-text-muted);
    background: none;
    border: none;
    cursor: pointer;
    font-family: inherit;
    padding: 0;
    transition: color 0.1s;
    text-decoration: none;
}

.wpaim-ob-skip:hover {
    color: var(--color-text-secondary);
    text-decoration: none;
}
```

- [ ] **Step 2: Commit**

```bash
git add src/admin/dashboard/dashboard.css
git commit -m "feat(dashboard): add dashboard CSS using existing design tokens"
```

---

## Task 6: Build StatusBanner component

**Files:**
- Create: `src/admin/dashboard/StatusBanner.jsx`

`bannerState` comes from `window.wpAiMindDashboard.bannerState`. Values: `'free_tier'` (amber), `'invalid_key'` (red), `'none'` (hidden).

- [ ] **Step 1: Create StatusBanner.jsx**

```jsx
export default function StatusBanner( { bannerState, urls } ) {
    if ( bannerState === 'none' ) return null;

    const isError = bannerState === 'invalid_key';

    return (
        <div className={ `wpaim-dash-banner wpaim-dash-banner--${ isError ? 'error' : 'warning' }` }>
            <div className="wpaim-dash-banner__dot" />
            <div className="wpaim-dash-banner__text">
                { isError ? (
                    <>
                        <strong>Your API key appears to be invalid.</strong>
                        <span> Check your Settings.</span>
                    </>
                ) : (
                    <>
                        <strong>You are on the free Plugin API.</strong>
                        <span> Add your own key for unlimited access, or upgrade to Pro.</span>
                    </>
                ) }
            </div>
            { ! isError && (
                <div className="wpaim-dash-banner__actions">
                    <a href={ urls.settings } className="wpaim-dash-btn">
                        Add API key
                    </a>
                    <a
                        href={ urls.upgrade }
                        className="wpaim-dash-btn wpaim-dash-btn--primary"
                        target="_blank"
                        rel="nofollow noreferrer"
                    >
                        Upgrade to Pro
                    </a>
                </div>
            ) }
            { isError && (
                <div className="wpaim-dash-banner__actions">
                    <a href={ urls.settings } className="wpaim-dash-btn wpaim-dash-btn--primary">
                        Go to Settings
                    </a>
                </div>
            ) }
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/admin/dashboard/StatusBanner.jsx
git commit -m "feat(dashboard): add StatusBanner component"
```

---

## Task 7: Build StartTiles component

**Files:**
- Create: `src/admin/dashboard/StartTiles.jsx`

- [ ] **Step 1: Create StartTiles.jsx**

```jsx
const TILES = [
    {
        verb: 'Write a new post',
        desc: 'Describe what you want — AI drafts it for you.',
        urlKey: 'generator',
        primary: true,
    },
    {
        verb: 'Edit with AI',
        desc: 'Open any post with the AI sidebar to rewrite or improve.',
        urlKey: 'posts',
        primary: false,
    },
    {
        verb: 'Generate an image',
        desc: 'Create a featured image or illustration from a prompt.',
        urlKey: 'images',
        primary: false,
    },
    {
        verb: 'Chat',
        desc: 'Brainstorm, research, or ask anything about your content.',
        urlKey: 'chat',
        primary: false,
    },
];

export default function StartTiles( { urls } ) {
    return (
        <div>
            <div className="wpaim-dash-section-head">
                <span className="wpaim-dash-section-title">Start</span>
            </div>
            <div className="wpaim-dash-tiles">
                { TILES.map( ( tile ) => (
                    <a
                        key={ tile.urlKey }
                        href={ urls[ tile.urlKey ] }
                        className={ `wpaim-dash-tile${ tile.primary ? ' wpaim-dash-tile--primary' : '' }` }
                    >
                        <div className="wpaim-dash-tile__verb">{ tile.verb }</div>
                        <div className="wpaim-dash-tile__desc">{ tile.desc }</div>
                        <span className="wpaim-dash-tile__arrow">&#x2197;</span>
                    </a>
                ) ) }
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/admin/dashboard/StartTiles.jsx
git commit -m "feat(dashboard): add StartTiles component"
```

---

## Task 8: Build ResourceList and PageFooter components

**Files:**
- Create: `src/admin/dashboard/ResourceList.jsx`
- Create: `src/admin/dashboard/PageFooter.jsx`

- [ ] **Step 1: Create ResourceList.jsx**

```jsx
const RESOURCES = [
    {
        title: 'Getting started guide',
        desc: 'Five minutes to your first AI-generated post.',
        urlKey: 'gettingStarted',
    },
    {
        title: 'Prompt writing tips',
        desc: 'How to write prompts that consistently produce better output.',
        urlKey: 'promptTips',
    },
    {
        title: 'API key setup',
        desc: 'Connect OpenAI, Claude, or Gemini to remove usage limits.',
        urlKey: 'apiKeySetup',
    },
    {
        title: 'Changelog',
        desc: null, // version string injected below
        urlKey: 'changelog',
    },
];

export default function ResourceList( { resourceUrls, version } ) {
    return (
        <div>
            <div className="wpaim-dash-section-head">
                <span className="wpaim-dash-section-title">Resources</span>
            </div>
            <div className="wpaim-dash-resources">
                { RESOURCES.map( ( item ) => (
                    <a
                        key={ item.urlKey }
                        href={ resourceUrls[ item.urlKey ] }
                        className="wpaim-dash-resource"
                        target="_blank"
                        rel="nofollow noreferrer"
                    >
                        <div className="wpaim-dash-resource__body">
                            <div className="wpaim-dash-resource__title">{ item.title }</div>
                            <div className="wpaim-dash-resource__desc">
                                { item.desc ?? `What's new in v${ version }.` }
                            </div>
                        </div>
                        <span className="wpaim-dash-resource__arrow">&#x2197;</span>
                    </a>
                ) ) }
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Create PageFooter.jsx**

```jsx
export default function PageFooter( { urls, runSetupUrl } ) {
    return (
        <div className="wpaim-dash-footer">
            <a href={ urls.settings } className="wpaim-dash-footer__link">
                Settings
            </a>
            <div className="wpaim-dash-footer__sep" />
            <a href={ runSetupUrl } className="wpaim-dash-footer__link">
                Run setup again
            </a>
            <div className="wpaim-dash-footer__sep" />
            <a
                href="https://[TODO-stilus-domain]/docs"
                className="wpaim-dash-footer__link"
                target="_blank"
                rel="nofollow noreferrer"
            >
                Documentation &#x2197;
            </a>
            <div className="wpaim-dash-footer__sep" />
            <a
                href="https://[TODO-stilus-domain]/support"
                className="wpaim-dash-footer__link"
                target="_blank"
                rel="nofollow noreferrer"
            >
                Support &#x2197;
            </a>
        </div>
    );
}
```

- [ ] **Step 3: Commit**

```bash
git add src/admin/dashboard/ResourceList.jsx src/admin/dashboard/PageFooter.jsx
git commit -m "feat(dashboard): add ResourceList and PageFooter components"
```

---

## Task 9: Build OnboardingModal component

**Files:**
- Create: `src/admin/dashboard/OnboardingModal.jsx`

Three steps: Step 1 (choose connection), Step 2 (provider + key, own-key path only), Done. `seen: false` POST resets the flag; `seen: true` saves it.

- [ ] **Step 1: Create OnboardingModal.jsx**

```jsx
import { useState, useCallback } from '@wordpress/element';

const PROVIDERS = [
    {
        id: 'openai',
        name: 'OpenAI',
        keyUrl: 'https://platform.openai.com/api-keys',
        keyLabel: 'Get API key',
        placeholder: 'sk-…  Paste your OpenAI API key',
    },
    {
        id: 'claude',
        name: 'Claude',
        keyUrl: 'https://console.anthropic.com/settings/keys',
        keyLabel: 'Get API key',
        placeholder: 'sk-ant-…  Paste your Anthropic API key',
    },
    {
        id: 'gemini',
        name: 'Gemini',
        keyUrl: 'https://aistudio.google.com/apikey',
        keyLabel: 'Get API key',
        placeholder: 'AI…  Paste your Gemini API key',
    },
];

const IMAGE_PROVIDERS = [
    {
        id: 'openai',
        name: 'OpenAI (DALL·E)',
        docsUrl: 'https://platform.openai.com/docs/guides/images',
        docsLabel: 'DALL·E docs',
    },
    {
        id: 'gemini',
        name: 'Gemini (Imagen 3)',
        docsUrl: 'https://ai.google.dev/gemini-api/docs/image-generation',
        docsLabel: 'Imagen docs',
        // DEV NOTE: Verify Imagen 3 endpoint before shipping.
        // Confirm if imagen-3.0-generate-* uses generativelanguage.googleapis.com
        // (same as text) or requires Vertex AI / a separate endpoint.
    },
];

// ── Step 1 ────────────────────────────────────────────────────────────────────

function Step1( { selection, onSelect, onContinue, onSkip, upgradeUrl } ) {
    const choices = [
        {
            id: 'plugin',
            title: 'Use Plugin API',
            badge: 'Free',
            desc: 'Start immediately. Built-in access with usage limits. No API key needed.',
        },
        {
            id: 'own_key',
            title: 'Use my own API key',
            badge: null,
            desc: 'Connect OpenAI, Claude, or Gemini directly. Unlimited usage.',
        },
        {
            id: 'pro',
            title: 'Upgrade to Pro',
            badge: 'Pro',
            desc: 'Full access, priority support, and advanced features.',
        },
    ];

    const isPro = selection === 'pro';

    return (
        <>
            <div className="wpaim-ob-header">
                <div className="wpaim-ob-pips">
                    <div className="wpaim-ob-pip wpaim-ob-pip--active" />
                    { /* Second pip only shown on own-key path — hidden here */ }
                </div>
                <div className="wpaim-ob-title">Welcome to Stilus</div>
                <div className="wpaim-ob-sub">
                    How would you like to connect? You can change this anytime in Settings.
                </div>
            </div>

            <div className="wpaim-ob-body">
                { choices.map( ( c ) => (
                    <div
                        key={ c.id }
                        className={ `wpaim-ob-choice${ selection === c.id ? ' wpaim-ob-choice--selected' : '' }` }
                        onClick={ () => onSelect( c.id ) }
                    >
                        <div className="wpaim-ob-radio">
                            <div className="wpaim-ob-radio__dot" />
                        </div>
                        <div>
                            <div className="wpaim-ob-choice__title">
                                { c.title }
                                { c.badge && (
                                    <span className="wpaim-pro-badge" style={ { marginLeft: 6 } }>
                                        { c.badge }
                                    </span>
                                ) }
                            </div>
                            <div className="wpaim-ob-choice__desc">{ c.desc }</div>
                        </div>
                    </div>
                ) ) }
            </div>

            <div className="wpaim-ob-footer">
                <button className="wpaim-ob-skip" onClick={ onSkip }>
                    Skip setup
                </button>
                { isPro ? (
                    <a
                        href={ upgradeUrl }
                        className="wpaim-dash-btn wpaim-dash-btn--primary"
                        target="_blank"
                        rel="nofollow noreferrer"
                        onClick={ onSkip }
                    >
                        Go to Upgrade &#x2197;
                    </a>
                ) : (
                    <button className="wpaim-dash-btn wpaim-dash-btn--primary" onClick={ onContinue }>
                        Get started &#x2192;
                    </button>
                ) }
            </div>
        </>
    );
}

// ── Step 2 ────────────────────────────────────────────────────────────────────

function Step2( { onBack, onFinish } ) {
    const [ selectedProvider, setSelectedProvider ] = useState( null );
    const [ apiKey, setApiKey ] = useState( '' );
    const [ imageProvider, setImageProvider ] = useState( null );
    const [ imageOpen, setImageOpen ] = useState( false );
    const [ saving, setSaving ] = useState( false );

    const handleFinish = useCallback( async () => {
        setSaving( true );
        await onFinish( { provider: selectedProvider, apiKey, imageProvider } );
        setSaving( false );
    }, [ onFinish, selectedProvider, apiKey, imageProvider ] );

    return (
        <>
            <div className="wpaim-ob-header">
                <div className="wpaim-ob-pips">
                    <div className="wpaim-ob-pip wpaim-ob-pip--done" />
                    <div className="wpaim-ob-pip wpaim-ob-pip--active" />
                </div>
                <div className="wpaim-ob-title">Choose your AI providers</div>
                <div className="wpaim-ob-sub">
                    Pick a text provider and paste your key. Image generation is optional.
                </div>
            </div>

            <div className="wpaim-ob-body">
                <div className="wpaim-ob-section-label">Text model</div>

                <div className="wpaim-ob-providers">
                    { PROVIDERS.map( ( p ) => (
                        <div
                            key={ p.id }
                            className={ `wpaim-ob-provider${ selectedProvider === p.id ? ' wpaim-ob-provider--selected' : '' }` }
                            onClick={ () => setSelectedProvider( p.id ) }
                        >
                            <div className="wpaim-ob-provider__check">&#x2713;</div>
                            <div className="wpaim-ob-provider__name">{ p.name }</div>
                            <a
                                href={ p.keyUrl }
                                className="wpaim-ob-provider__link"
                                target="_blank"
                                rel="nofollow noreferrer"
                                onClick={ ( e ) => e.stopPropagation() }
                            >
                                { p.keyLabel } &#x2197;
                            </a>
                        </div>
                    ) ) }
                </div>

                { selectedProvider && (
                    <input
                        className="wpaim-ob-key-input"
                        type="password"
                        value={ apiKey }
                        onChange={ ( e ) => setApiKey( e.target.value ) }
                        placeholder={
                            PROVIDERS.find( ( p ) => p.id === selectedProvider )?.placeholder ?? 'Paste your API key'
                        }
                        autoComplete="off"
                    />
                ) }

                { /* Image provider — optional, collapsible */ }
                <div className="wpaim-ob-optional">
                    <button
                        type="button"
                        className="wpaim-ob-optional__toggle"
                        onClick={ () => setImageOpen( ( o ) => ! o ) }
                    >
                        <span className="wpaim-ob-optional__label">Image model</span>
                        <span className="wpaim-ob-optional__tag">Optional</span>
                        <span className={ `wpaim-ob-optional__chevron${ imageOpen ? ' wpaim-ob-optional__chevron--open' : '' }` }>
                            &#x25be;
                        </span>
                    </button>

                    { imageOpen && (
                        <div className="wpaim-ob-optional__body">
                            <p className="wpaim-ob-optional__desc">
                                Add an image generation provider. Can be configured later in Settings.
                            </p>

                            <div className="wpaim-ob-providers">
                                { IMAGE_PROVIDERS.map( ( p ) => (
                                    <div
                                        key={ p.id }
                                        className={ `wpaim-ob-provider${ imageProvider === p.id ? ' wpaim-ob-provider--selected' : '' }` }
                                        onClick={ () => setImageProvider( ( prev ) => prev === p.id ? null : p.id ) }
                                    >
                                        <div className="wpaim-ob-provider__check">&#x2713;</div>
                                        <div className="wpaim-ob-provider__name">{ p.name }</div>
                                        <a
                                            href={ p.docsUrl }
                                            className="wpaim-ob-provider__link"
                                            target="_blank"
                                            rel="nofollow noreferrer"
                                            onClick={ ( e ) => e.stopPropagation() }
                                        >
                                            { p.docsLabel } &#x2197;
                                        </a>
                                    </div>
                                ) ) }
                                <div className="wpaim-ob-provider" style={ { opacity: 0.4, cursor: 'default', borderStyle: 'dashed' } }>
                                    <div className="wpaim-ob-provider__name" style={ { color: 'var(--color-text-muted)', fontSize: '0.625rem' } }>
                                        More coming
                                    </div>
                                </div>
                            </div>

                            <div className="wpaim-ob-devnote">
                                <strong style={ { display: 'block', marginBottom: 2 } }>Dev note — Gemini image endpoint</strong>
                                Gemini image generation uses Imagen 3 (<code>imagen-3.0-generate-*</code> models).
                                Verify whether this can share the <code>generativelanguage.googleapis.com</code> endpoint
                                used for text, or whether Vertex AI is required before shipping.
                            </div>
                        </div>
                    ) }
                </div>
            </div>

            <div className="wpaim-ob-footer">
                <button className="wpaim-ob-skip" onClick={ onBack }>
                    &#x2190; Back
                </button>
                <button
                    className="wpaim-dash-btn wpaim-dash-btn--primary"
                    onClick={ handleFinish }
                    disabled={ saving }
                >
                    { saving ? 'Saving…' : 'Finish setup \u2192' }
                </button>
            </div>
        </>
    );
}

// ── Done screen ───────────────────────────────────────────────────────────────

function DoneScreen( { apiTierLabel, urls } ) {
    return (
        <>
            <div className="wpaim-ob-header">
                <div className="wpaim-ob-pips">
                    <div className="wpaim-ob-pip wpaim-ob-pip--done" />
                    <div className="wpaim-ob-pip wpaim-ob-pip--done" />
                </div>
                <div className="wpaim-ob-title">You're all set</div>
                <div className="wpaim-ob-sub">Stilus is ready to use.</div>
            </div>

            <div className="wpaim-ob-body">
                <div className="wpaim-ob-success">
                    <div className="wpaim-ob-success__title">Setup complete</div>
                    <div className="wpaim-ob-success__sub">
                        { apiTierLabel }.<br />
                        Change this anytime in Settings.
                    </div>
                    <a href={ urls.chat } className="wpaim-ob-success__cta">
                        Open Chat &#x2192;
                    </a>
                </div>
            </div>

            <div className="wpaim-ob-footer" style={ { borderTop: 'none', paddingTop: 0, justifyContent: 'center' } }>
                <a href="#" className="wpaim-ob-skip" onClick={ ( e ) => { e.preventDefault(); window.location.reload(); } }>
                    Go to Dashboard instead
                </a>
            </div>
        </>
    );
}

// ── Root modal ────────────────────────────────────────────────────────────────

export default function OnboardingModal( { onDismiss, nonce, restUrl, urls } ) {
    const [ step, setStep ] = useState( 'step1' );        // 'step1' | 'step2' | 'done'
    const [ connection, setConnection ] = useState( 'plugin' ); // 'plugin' | 'own_key' | 'pro'
    const [ apiTierLabel, setApiTierLabel ] = useState( 'Using Plugin API (free tier)' );

    const postOnboarding = useCallback( async ( body ) => {
        await window.fetch( `${ restUrl }/onboarding`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': nonce,
            },
            body: JSON.stringify( body ),
        } );
    }, [ nonce, restUrl ] );

    const handleSkip = useCallback( async () => {
        await postOnboarding( { seen: true } );
        onDismiss();
    }, [ postOnboarding, onDismiss ] );

    const handleStep1Continue = useCallback( async () => {
        if ( connection === 'plugin' ) {
            await postOnboarding( { seen: true } );
            setApiTierLabel( 'Using Plugin API (free tier)' );
            setStep( 'done' );
        } else if ( connection === 'own_key' ) {
            setStep( 'step2' );
        }
        // 'pro' path handled by the upgrade link directly in Step1
    }, [ connection, postOnboarding ] );

    const handleStep2Finish = useCallback( async ( { provider, apiKey, imageProvider } ) => {
        await postOnboarding( { seen: true, provider, api_key: apiKey, image_provider: imageProvider } );
        setApiTierLabel( `Using your own ${ provider ? provider.charAt( 0 ).toUpperCase() + provider.slice( 1 ) : '' } API key` );
        setStep( 'done' );
    }, [ postOnboarding ] );

    return (
        <div className="wpaim-ob-overlay">
            <div className="wpaim-ob-modal">
                { step === 'step1' && (
                    <Step1
                        selection={ connection }
                        onSelect={ setConnection }
                        onContinue={ handleStep1Continue }
                        onSkip={ handleSkip }
                        upgradeUrl={ urls.upgrade }
                    />
                ) }
                { step === 'step2' && (
                    <Step2
                        onBack={ () => setStep( 'step1' ) }
                        onFinish={ handleStep2Finish }
                    />
                ) }
                { step === 'done' && (
                    <DoneScreen
                        apiTierLabel={ apiTierLabel }
                        urls={ urls }
                    />
                ) }
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add src/admin/dashboard/OnboardingModal.jsx
git commit -m "feat(dashboard): add OnboardingModal component — branching Step1 / Step2 / Done"
```

---

## Task 10: Assemble DashboardApp

**Files:**
- Modify: `src/admin/dashboard/DashboardApp.jsx`

Replace the stub with the full assembled component.

- [ ] **Step 1: Replace DashboardApp.jsx**

```jsx
import { useState } from '@wordpress/element';
import StatusBanner from './StatusBanner';
import StartTiles from './StartTiles';
import ResourceList from './ResourceList';
import PageFooter from './PageFooter';
import OnboardingModal from './OnboardingModal';
import './dashboard.css';

export default function DashboardApp() {
    const data = window.wpAiMindDashboard ?? {};
    const {
        bannerState    = 'none',
        onboardingSeen = true,
        version        = '',
        nonce          = '',
        restUrl        = '',
        runSetupUrl    = '#',
        urls           = {},
        resourceUrls   = {},
    } = data;

    const [ modalVisible, setModalVisible ] = useState( ! onboardingSeen );

    return (
        <div className="wpaim-dashboard">
            { /* Top bar */ }
            <div className="wpaim-dash-topbar">
                <div>
                    <div className="wpaim-dash-title">Stilus</div>
                    <div className="wpaim-dash-subtitle">AI-powered content creation for WordPress</div>
                </div>
                <span className="wpaim-dash-version">v{ version }</span>
            </div>

            <StatusBanner bannerState={ bannerState } urls={ urls } />

            <div className="wpaim-dash-body">
                <StartTiles urls={ urls } />
                <ResourceList resourceUrls={ resourceUrls } version={ version } />
            </div>

            <PageFooter urls={ urls } runSetupUrl={ runSetupUrl } />

            { modalVisible && (
                <OnboardingModal
                    onDismiss={ () => setModalVisible( false ) }
                    nonce={ nonce }
                    restUrl={ restUrl }
                    urls={ urls }
                />
            ) }
        </div>
    );
}
```

- [ ] **Step 2: Build**

```bash
npm run build
```

Expected: builds successfully with no errors.

- [ ] **Step 3: Verify in browser**

Navigate to WP Admin → AI Mind.

Confirm:
- Dashboard renders with top bar, Start tiles, Resources, footer.
- Status banner shows (amber) because no API key is configured on a fresh install.
- Banner is hidden after configuring a valid API key in Settings.

To test the onboarding modal, run in browser console:
```javascript
// Force modal to show by setting onboarding as unseen
fetch('/wp-json/stilus/v1/onboarding', {
  method: 'POST',
  headers: {'Content-Type': 'application/json', 'X-WP-Nonce': wpAiMindDashboard.nonce},
  body: JSON.stringify({ seen: false })
}).then(() => location.reload())
```

Confirm:
- Modal appears on reload.
- Selecting "Plugin API" → Continue → Done screen (1 step).
- Selecting "Own API key" → Continue → Step 2 with provider cards → Finish setup → Done.
- "Get API key" links open in new tab.
- "Skip setup" dismisses modal; reload does not show it again.
- "Go to Dashboard instead" reloads the page.

- [ ] **Step 4: Commit**

```bash
git add src/admin/dashboard/DashboardApp.jsx
git commit -m "feat(dashboard): assemble DashboardApp — all components wired"
```

---

## Task 11: Add "Run setup again" to Settings page

**Files:**
- Modify: `src/admin/settings/SettingsApp.jsx`

Add a "Run setup again" button that calls `POST /stilus/v1/onboarding` with `{ seen: false }` then navigates to the Dashboard.

- [ ] **Step 1: Read current SettingsApp.jsx to find the right place to add the button**

Open `src/admin/settings/SettingsApp.jsx`. Find the bottom of the last settings section or a "General" / "Account" section. If none exists, add it at the bottom of the rendered output before the closing element.

- [ ] **Step 2: Add the run-setup handler and button**

Add the following handler to `SettingsApp` (or the relevant tab component):

```jsx
const handleRunSetup = async () => {
    await window.fetch( `${ wpAiMindData.restUrl }/onboarding`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': wpAiMindData.nonce,
        },
        body: JSON.stringify( { seen: false } ),
    } );
    window.location.href = 'admin.php?page=stilus';
};
```

Add the following button in the JSX, in a logical "General" section or at the bottom of the page:

```jsx
<div className="wpaim-settings-section" style={ { borderTop: '1px solid var(--color-border-subtle)', paddingTop: 'var(--space-4)', marginTop: 'var(--space-4)' } }>
    <div className="wpaim-settings-label">Setup</div>
    <p className="wpaim-settings-description">
        Re-run the onboarding wizard to change your API connection or provider settings.
    </p>
    <button
        type="button"
        className="wpaim-btn wpaim-btn--secondary"
        onClick={ handleRunSetup }
    >
        Run setup again
    </button>
</div>
```

- [ ] **Step 3: Build and verify**

```bash
npm run build
```

Navigate to WP Admin → AI Mind → Settings. Confirm "Run setup again" button is visible.

Click it → page navigates to Dashboard → onboarding modal appears.

- [ ] **Step 4: Commit**

```bash
git add src/admin/settings/SettingsApp.jsx
git commit -m "feat(dashboard): add 'Run setup again' to Settings page"
```

---

## Task 12: E2E smoke tests

**Files:**
- Create: `tests/e2e/dashboard.spec.js`

- [ ] **Step 1: Create the test file**

```javascript
import { test, expect } from '@playwright/test';

// Assumes WordPress is running at baseURL defined in playwright.config.js
// and an admin user is configured in the test environment.

test.describe( 'Dashboard landing page', () => {
    test.beforeEach( async ( { page } ) => {
        // Log in as admin — adjust credentials for your local environment.
        await page.goto( '/wp-login.php' );
        await page.fill( '#user_login', 'admin' );
        await page.fill( '#user_pass', 'password' );
        await page.click( '#wp-submit' );
        await page.waitForURL( /wp-admin/ );
    } );

    test( 'dashboard page renders with title and Start section', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=stilus' );
        await expect( page.locator( '.wpaim-dash-title' ) ).toHaveText( 'Stilus' );
        await expect( page.locator( '.wpaim-dash-tiles' ) ).toBeVisible();
        await expect( page.locator( '.wpaim-dash-resources' ) ).toBeVisible();
        await expect( page.locator( '.wpaim-dash-footer' ) ).toBeVisible();
    } );

    test( 'Chat sub-menu navigates to Chat page', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=stilus' );
        await page.click( 'text=Chat' ); // sidebar nav
        await expect( page ).toHaveURL( /page=stilus-chat/ );
    } );

    test( 'Run setup again link navigates back to dashboard and shows modal', async ( { page } ) => {
        // Ensure onboarding seen = true so modal isn't shown on initial load.
        await page.goto( '/wp-admin/admin.php?page=stilus' );

        // Click Run setup again in footer.
        const runSetupLink = page.locator( '.wpaim-dash-footer__link', { hasText: 'Run setup again' } );
        await runSetupLink.click();

        // Should navigate back to dashboard.
        await expect( page ).toHaveURL( /page=stilus/ );

        // Onboarding modal should be visible.
        await expect( page.locator( '.wpaim-ob-overlay' ) ).toBeVisible();
    } );

    test( 'onboarding modal — Plugin API path completes in one step', async ( { page } ) => {
        // Reset onboarding seen flag.
        await page.goto( '/wp-admin/admin.php?page=stilus' );
        // Force modal visible by injecting state
        await page.evaluate( () => {
            window.wpAiMindDashboard.onboardingSeen = false;
        } );
        await page.reload();

        await expect( page.locator( '.wpaim-ob-overlay' ) ).toBeVisible();

        // Plugin API is selected by default — click Get started.
        await page.click( 'button:has-text("Get started")' );

        // Done screen should appear.
        await expect( page.locator( 'text=Setup complete' ) ).toBeVisible();
    } );

    test( 'all resource links have target=_blank and rel attributes', async ( { page } ) => {
        await page.goto( '/wp-admin/admin.php?page=stilus' );
        const links = page.locator( '.wpaim-dash-resource' );
        const count = await links.count();
        expect( count ).toBe( 4 );

        for ( let i = 0; i < count; i++ ) {
            await expect( links.nth( i ) ).toHaveAttribute( 'target', '_blank' );
            await expect( links.nth( i ) ).toHaveAttribute( 'rel', 'nofollow noreferrer' );
        }
    } );
} );
```

- [ ] **Step 2: Run the tests**

```bash
npm run test:e2e -- tests/e2e/dashboard.spec.js
```

Expected: all 5 tests pass. Investigate and fix any failures before continuing.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/dashboard.spec.js
git commit -m "test(dashboard): add E2E smoke tests for landing page and onboarding flow"
```

---

## Self-Review Against Spec

**Spec coverage check:**

| Spec requirement | Task |
|---|---|
| Stilus top-level → Dashboard | Task 1 |
| Chat, Generator etc. become sub-pages | Task 1 |
| Fully static page (no API calls) | Tasks 6–8 (no REST calls on page load) |
| Status banner — free tier / invalid key / none | Task 6 |
| Four Start tiles with verb labels | Task 7 |
| Resources section — 4 external links, nofollow noreferrer | Task 8 |
| Footer — Settings, Run setup again, Docs, Support | Task 8 |
| Onboarding modal — first-run trigger | Task 2 (option flag), Task 10 (React state) |
| Onboarding Step 1 — three connection options | Task 9 |
| Pro → upgrade page in new tab, modal closes | Task 9 (Step1 component) |
| Plugin API → 1-step to Done | Task 9 (handleStep1Continue) |
| Own API key → Step 2 → Done | Task 9 |
| Step 2 — OpenAI / Claude / Gemini provider cards | Task 9 |
| "Get API key" links — new tab, nofollow noreferrer | Task 9 |
| Step 2 — API key saved via ProviderSettings | Task 3 (OnboardingRestController) |
| Image model — optional, collapsible | Task 9 |
| DALL·E + Gemini Imagen 3 + "More coming" slot | Task 9 |
| Gemini endpoint dev note | Task 9 (inline comment) |
| Done screen — Open Chat CTA | Task 9 |
| Skip setup → sets seen flag, lands on Dashboard | Task 9 |
| `wpaim_onboarding_seen` option flag | Tasks 2, 3 |
| Settings → Run setup again | Task 11 |
| Pro gating (PRO badge pattern) | Task 9 uses existing `.wpaim-pro-badge` class |

All spec requirements are covered. ✓
