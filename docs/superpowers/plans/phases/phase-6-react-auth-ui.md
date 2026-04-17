# Phase 6: React — Auth UI + Usage Components

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add login/signup screen when unauthenticated; add UsageMeter to all pages; show UpgradeModal on 429; conditionally hide API key inputs for non-Pro users.

**Architecture:** WordPress plugin's React admin apps are wrapped in an AuthProvider. On load, `window.wpAiMindData.isAuthenticated` determines whether to show the AI interface or the auth forms. Entitlement data flows via React context to all components.

**Tech Stack:** React (@wordpress/element), @wordpress/api-fetch, @wordpress/i18n, existing JSX component structure.

**Depends on:** Phase 2 (REST auth endpoints live) + Phase 5 (entitlement in localized scripts).

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Auth gate location | `src/admin/index.js` wrapping each root | Single mount point — no duplication across apps |
| `GatedApp` placement | Inside `AuthProvider` | Must call `useAuth()` which requires context to be present |
| `AuthProvider` initial state | Read from `window.wpAiMindData` at mount | Avoids an extra REST round-trip on page load; PHP already localises auth state in Phase 5 |
| `refreshEntitlement()` | GET `/wp-ai-mind/v1/nj/me` | Allows React to re-hydrate entitlement after a login or upgrade without a full page reload |
| Shared constants | `src/admin/shared/constants.js` | One place to update URLs before launch; prevents magic strings scattered across components |
| UsageMeter visibility | Hidden when `plan === 'pro'` or `plan === 'none'` | Pro users have no limit; unregistered users have no account to show meters for |
| UpgradeModal trigger | `err?.status === 429 \|\| err?.data?.code === 'token_limit_exceeded'` | Worker returns 429 with that code; catch it uniformly in all AI feature components |
| Pro API key section | Gated by `entitlement?.features?.own_key` | Feature flag from Phase 2/5 entitlement payload; consistent with PHP gating |

---

## File Map

**New files:**
- `src/admin/auth/AuthContext.jsx`
- `src/admin/auth/AuthApp.jsx`
- `src/admin/auth/LoginForm.jsx`
- `src/admin/auth/SignupForm.jsx`
- `src/admin/shared/UsageMeter.jsx`
- `src/admin/shared/UpgradeModal.jsx`
- `src/admin/shared/UsageWarning.jsx`
- `src/admin/shared/constants.js`

**Modified files:**
- `src/admin/index.js` — wrap all React roots in `AuthProvider`; show `AuthApp` when unauthenticated
- `src/admin/settings/ProvidersTab.jsx` — conditional API key section (Pro only; non-Pro sees upgrade CTA)
- `src/admin/components/Chat/ChatApp.jsx` — 429 error → show `UpgradeModal`
- `src/admin/dashboard/DashboardApp.jsx` — show `UsageMeter` + `UsageWarning`

---

## Task 1: constants.js + AuthContext scaffold

**Files:** `src/admin/shared/constants.js`, `src/admin/auth/AuthContext.jsx`

- [ ] **Step 1.1: Create `src/admin/shared/constants.js`**

```js
// TODO: Replace placeholder URLs with real values before launch.

/**
 * LemonSqueezy checkout URL for WP AI Mind Pro.
 * Replace with the real product checkout URL before launch.
 */
export const LEMONSQUEEZY_CHECKOUT_URL = 'https://checkout.lemonsqueezy.com/buy/REPLACE_WITH_REAL_VARIANT_ID';

/**
 * NJ account dashboard URL.
 * Replace with the real account portal URL before launch.
 */
export const ACCOUNT_DASHBOARD_URL = 'https://account.njohansson.eu/REPLACE_WITH_REAL_PATH';
```

- [ ] **Step 1.2: Create `src/admin/auth/AuthContext.jsx`**

```jsx
import { createContext, useContext, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * AuthContext — provides authentication state and entitlement data to all
 * child components. Initial values are hydrated from `window.wpAiMindData`
 * which is localised by PHP in Phase 5.
 */
const AuthContext = createContext( null );

/**
 * AuthProvider wraps all admin roots so that any component can call useAuth().
 *
 * @param {Object} props
 * @param {import('@wordpress/element').ReactNode} props.children
 */
export function AuthProvider( { children } ) {
    const seed = window.wpAiMindData ?? {};

    const [ isAuthenticated, setIsAuthenticated ] = useState(
        Boolean( seed.isAuthenticated )
    );
    const [ user, setUser ] = useState( seed.user ?? null );
    const [ entitlement, setEntitlement ] = useState( seed.entitlement ?? null );
    const [ authError, setAuthError ] = useState( null );

    /**
     * Log in with email and password.
     * On success the context is updated with the user + entitlement returned
     * by the REST endpoint.
     *
     * @param {string} email
     * @param {string} password
     */
    async function login( email, password ) {
        setAuthError( null );
        const data = await apiFetch( {
            path: '/wp-ai-mind/v1/nj/login',
            method: 'POST',
            data: { email, password },
        } );
        setUser( data.user ?? null );
        setEntitlement( data.entitlement ?? null );
        setIsAuthenticated( true );
    }

    /**
     * Register a new account. The REST endpoint auto-logs the user in and
     * returns the same shape as /login.
     *
     * @param {string} email
     * @param {string} password
     */
    async function register( email, password ) {
        setAuthError( null );
        const data = await apiFetch( {
            path: '/wp-ai-mind/v1/nj/register',
            method: 'POST',
            data: { email, password },
        } );
        setUser( data.user ?? null );
        setEntitlement( data.entitlement ?? null );
        setIsAuthenticated( true );
    }

    /**
     * Log out the current user. Clears all auth state.
     */
    async function logout() {
        setAuthError( null );
        try {
            await apiFetch( {
                path: '/wp-ai-mind/v1/nj/logout',
                method: 'POST',
            } );
        } finally {
            // Always clear local state even if the server call fails.
            setIsAuthenticated( false );
            setUser( null );
            setEntitlement( null );
        }
    }

    /**
     * Re-fetch entitlement from the server. Call this after a plan upgrade
     * to get updated feature flags without a page reload.
     */
    async function refreshEntitlement() {
        const data = await apiFetch( {
            path: '/wp-ai-mind/v1/nj/me',
        } );
        setEntitlement( data.entitlement ?? null );
        if ( data.user ) {
            setUser( data.user );
        }
    }

    return (
        <AuthContext.Provider
            value={ {
                isAuthenticated,
                user,
                entitlement,
                authError,
                setAuthError,
                login,
                register,
                logout,
                refreshEntitlement,
            } }
        >
            { children }
        </AuthContext.Provider>
    );
}

/**
 * Hook to access the auth context from any component inside AuthProvider.
 *
 * @returns {{
 *   isAuthenticated: boolean,
 *   user: Object|null,
 *   entitlement: Object|null,
 *   authError: string|null,
 *   setAuthError: Function,
 *   login: Function,
 *   register: Function,
 *   logout: Function,
 *   refreshEntitlement: Function,
 * }}
 */
export function useAuth() {
    const ctx = useContext( AuthContext );
    if ( ! ctx ) {
        throw new Error( 'useAuth must be used inside <AuthProvider>' );
    }
    return ctx;
}
```

- [ ] **Step 1.3: Commit**

```bash
git add src/admin/shared/constants.js src/admin/auth/AuthContext.jsx
git commit -m "feat(auth): add AuthContext and shared constants scaffold"
```

---

## Task 2: LoginForm + SignupForm components

**Files:** `src/admin/auth/LoginForm.jsx`, `src/admin/auth/SignupForm.jsx`

- [ ] **Step 2.1: Create `src/admin/auth/LoginForm.jsx`**

```jsx
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useAuth } from './AuthContext';
import { ACCOUNT_DASHBOARD_URL } from '../shared/constants';

/**
 * LoginForm — email + password form that calls auth.login().
 *
 * @param {Object}   props
 * @param {Function} props.onSwitch - Callback to switch to the signup tab.
 */
export default function LoginForm( { onSwitch } ) {
    const auth = useAuth();

    const [ email, setEmail ] = useState( '' );
    const [ password, setPassword ] = useState( '' );
    const [ error, setError ] = useState( '' );
    const [ isLoading, setIsLoading ] = useState( false );

    async function handleSubmit( e ) {
        e.preventDefault();
        setError( '' );
        setIsLoading( true );
        try {
            await auth.login( email, password );
            // AuthContext sets isAuthenticated — GatedApp transitions automatically.
        } catch ( err ) {
            setError(
                err?.message ?? __( 'Login failed. Please try again.', 'wp-ai-mind' )
            );
        } finally {
            setIsLoading( false );
        }
    }

    return (
        <form
            className="wpaim-auth-form"
            onSubmit={ handleSubmit }
            noValidate
        >
            <h2 className="wpaim-auth-form__heading">
                { __( 'Log in to your account', 'wp-ai-mind' ) }
            </h2>

            { error && (
                <p className="wpaim-auth-form__error" role="alert">
                    { error }
                </p>
            ) }

            <label className="wpaim-auth-form__label">
                { __( 'Email address', 'wp-ai-mind' ) }
                <input
                    className="wpaim-auth-form__input"
                    type="email"
                    value={ email }
                    onChange={ ( e ) => setEmail( e.target.value ) }
                    required
                    autoComplete="email"
                    disabled={ isLoading }
                />
            </label>

            <label className="wpaim-auth-form__label">
                { __( 'Password', 'wp-ai-mind' ) }
                <input
                    className="wpaim-auth-form__input"
                    type="password"
                    value={ password }
                    onChange={ ( e ) => setPassword( e.target.value ) }
                    required
                    autoComplete="current-password"
                    disabled={ isLoading }
                />
            </label>

            <p className="wpaim-auth-form__hint">
                { __( 'Forgot your password? Reset it at', 'wp-ai-mind' ) }{ ' ' }
                <a
                    href={ ACCOUNT_DASHBOARD_URL }
                    target="_blank"
                    rel="noreferrer"
                    className="wpaim-auth-form__link"
                >
                    { __( 'your account dashboard', 'wp-ai-mind' ) }
                </a>
                .
            </p>

            <button
                type="submit"
                className="wpaim-btn wpaim-btn--primary wpaim-btn--full"
                disabled={ isLoading }
            >
                { isLoading
                    ? __( 'Logging in…', 'wp-ai-mind' )
                    : __( 'Log in', 'wp-ai-mind' ) }
            </button>

            <p className="wpaim-auth-form__switch">
                { __( 'No account?', 'wp-ai-mind' ) }{ ' ' }
                <button
                    type="button"
                    className="wpaim-auth-form__switch-btn"
                    onClick={ onSwitch }
                >
                    { __( 'Sign up →', 'wp-ai-mind' ) }
                </button>
            </p>
        </form>
    );
}
```

- [ ] **Step 2.2: Create `src/admin/auth/SignupForm.jsx`**

```jsx
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useAuth } from './AuthContext';

/**
 * SignupForm — email + password + confirm form that calls auth.register().
 * Client-side validation runs before the REST call.
 *
 * @param {Object}   props
 * @param {Function} props.onSwitch - Callback to switch to the login tab.
 */
export default function SignupForm( { onSwitch } ) {
    const auth = useAuth();

    const [ email, setEmail ] = useState( '' );
    const [ password, setPassword ] = useState( '' );
    const [ confirmPassword, setConfirmPassword ] = useState( '' );
    const [ error, setError ] = useState( '' );
    const [ isLoading, setIsLoading ] = useState( false );

    function validate() {
        if ( password.length < 8 ) {
            return __( 'Password must be at least 8 characters.', 'wp-ai-mind' );
        }
        if ( password !== confirmPassword ) {
            return __( 'Passwords do not match.', 'wp-ai-mind' );
        }
        return null;
    }

    async function handleSubmit( e ) {
        e.preventDefault();
        setError( '' );

        const validationError = validate();
        if ( validationError ) {
            setError( validationError );
            return;
        }

        setIsLoading( true );
        try {
            await auth.register( email, password );
            // AuthContext sets isAuthenticated — GatedApp transitions automatically.
        } catch ( err ) {
            setError(
                err?.message ?? __( 'Registration failed. Please try again.', 'wp-ai-mind' )
            );
        } finally {
            setIsLoading( false );
        }
    }

    return (
        <form
            className="wpaim-auth-form"
            onSubmit={ handleSubmit }
            noValidate
        >
            <h2 className="wpaim-auth-form__heading">
                { __( 'Create a free account', 'wp-ai-mind' ) }
            </h2>

            { error && (
                <p className="wpaim-auth-form__error" role="alert">
                    { error }
                </p>
            ) }

            <label className="wpaim-auth-form__label">
                { __( 'Email address', 'wp-ai-mind' ) }
                <input
                    className="wpaim-auth-form__input"
                    type="email"
                    value={ email }
                    onChange={ ( e ) => setEmail( e.target.value ) }
                    required
                    autoComplete="email"
                    disabled={ isLoading }
                />
            </label>

            <label className="wpaim-auth-form__label">
                { __( 'Password', 'wp-ai-mind' ) }
                <input
                    className="wpaim-auth-form__input"
                    type="password"
                    value={ password }
                    onChange={ ( e ) => setPassword( e.target.value ) }
                    required
                    autoComplete="new-password"
                    disabled={ isLoading }
                    minLength={ 8 }
                />
            </label>

            <label className="wpaim-auth-form__label">
                { __( 'Confirm password', 'wp-ai-mind' ) }
                <input
                    className="wpaim-auth-form__input"
                    type="password"
                    value={ confirmPassword }
                    onChange={ ( e ) => setConfirmPassword( e.target.value ) }
                    required
                    autoComplete="new-password"
                    disabled={ isLoading }
                />
            </label>

            <button
                type="submit"
                className="wpaim-btn wpaim-btn--primary wpaim-btn--full"
                disabled={ isLoading }
            >
                { isLoading
                    ? __( 'Creating account…', 'wp-ai-mind' )
                    : __( 'Create account', 'wp-ai-mind' ) }
            </button>

            <p className="wpaim-auth-form__switch">
                { __( 'Already have an account?', 'wp-ai-mind' ) }{ ' ' }
                <button
                    type="button"
                    className="wpaim-auth-form__switch-btn"
                    onClick={ onSwitch }
                >
                    { __( 'Log in →', 'wp-ai-mind' ) }
                </button>
            </p>
        </form>
    );
}
```

- [ ] **Step 2.3: Commit**

```bash
git add src/admin/auth/LoginForm.jsx src/admin/auth/SignupForm.jsx
git commit -m "feat(auth): add LoginForm and SignupForm components"
```

---

## Task 3: AuthApp tab switcher

**File:** `src/admin/auth/AuthApp.jsx`

- [ ] **Step 3.1: Create `src/admin/auth/AuthApp.jsx`**

```jsx
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import LoginForm from './LoginForm';
import SignupForm from './SignupForm';

/**
 * AuthApp — centered card shown to unauthenticated users.
 * Renders either LoginForm or SignupForm based on activeTab state.
 * Uses only BEM class names — no external CSS framework required.
 */
export default function AuthApp() {
    const [ activeTab, setActiveTab ] = useState( 'login' );

    return (
        <div className="wpaim-auth-overlay">
            <div className="wpaim-auth-card">
                <div className="wpaim-auth-card__brand">
                    <span className="wpaim-auth-card__logo" aria-hidden="true">
                        ✦
                    </span>
                    <h1 className="wpaim-auth-card__title">
                        { __( 'WP AI Mind', 'wp-ai-mind' ) }
                    </h1>
                    <p className="wpaim-auth-card__tagline">
                        { __( 'AI-powered content creation for WordPress', 'wp-ai-mind' ) }
                    </p>
                </div>

                <div className="wpaim-auth-card__body">
                    { activeTab === 'login' ? (
                        <LoginForm
                            onSwitch={ () => setActiveTab( 'signup' ) }
                        />
                    ) : (
                        <SignupForm
                            onSwitch={ () => setActiveTab( 'login' ) }
                        />
                    ) }
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 3.2: Commit**

```bash
git add src/admin/auth/AuthApp.jsx
git commit -m "feat(auth): add AuthApp tab switcher card"
```

---

## Task 4: src/admin/index.js — auth gate

**File:** `src/admin/index.js`

The existing pattern renders each app directly. Replace the whole file with the gated pattern below.

- [ ] **Step 4.1: Rewrite `src/admin/index.js`**

```jsx
import { render } from '@wordpress/element';
import { AuthProvider, useAuth } from './auth/AuthContext';
import AuthApp from './auth/AuthApp';
import ChatApp from './components/Chat/ChatApp';
import SettingsApp from './settings/SettingsApp';
import DashboardApp from './dashboard/DashboardApp';
import '../styles/tokens.css';
import './admin.css';

/**
 * GatedApp renders either the auth card or the real app depending on whether
 * the user is authenticated. It MUST be rendered inside <AuthProvider> so
 * that useAuth() has a context to read from.
 *
 * @param {Object}                                props
 * @param {import('@wordpress/element').FC} props.App - The real app component.
 */
function GatedApp( { App } ) {
    const { isAuthenticated } = useAuth();
    if ( ! isAuthenticated ) {
        return <AuthApp />;
    }
    return <App />;
}

/**
 * Mount a React root with AuthProvider + GatedApp wrapping.
 *
 * @param {string}                                rootId - DOM element ID.
 * @param {import('@wordpress/element').FC} App    - Component to render when authenticated.
 */
function mount( rootId, App ) {
    const el = document.getElementById( rootId );
    if ( ! el ) {
        return;
    }
    render(
        <AuthProvider>
            <GatedApp App={ App } />
        </AuthProvider>,
        el
    );
}

mount( 'wp-ai-mind-chat', ChatApp );
mount( 'wp-ai-mind-settings', SettingsApp );
mount( 'wp-ai-mind-dashboard', DashboardApp );
```

**Important:** `GatedApp` calls `useAuth()` which requires `AuthProvider` to be an ancestor. The structure `<AuthProvider><GatedApp /></AuthProvider>` satisfies this. Do not hoist `GatedApp` outside of the `mount()` call.

- [ ] **Step 4.2: Verify the build compiles cleanly**

```bash
npm run build
# Expected: no errors; compiled assets/ updated
```

- [ ] **Step 4.3: Commit**

```bash
git add src/admin/index.js
git commit -m "feat(auth): gate all React roots behind AuthProvider"
```

---

## Task 5: UsageMeter component

**File:** `src/admin/shared/UsageMeter.jsx`

- [ ] **Step 5.1: Create `src/admin/shared/UsageMeter.jsx`**

```jsx
import { __ } from '@wordpress/i18n';
import { useAuth } from '../auth/AuthContext';
import { LEMONSQUEEZY_CHECKOUT_URL } from './constants';

/**
 * Format a raw token count as a human-readable string.
 * Counts >= 1000 are shown as e.g. "50k".
 *
 * @param {number} n
 * @returns {string}
 */
function formatNumber( n ) {
    if ( n >= 1000 ) {
        return Math.round( n / 1000 ) + 'k';
    }
    return String( n );
}

/**
 * UsageMeter — shows a coloured progress bar with token usage.
 *
 * Hidden when:
 * - plan === 'pro'       (unlimited; no meter needed)
 * - plan === 'none'      (no account; nothing to show)
 * - tokens_limit is null (plan doesn't have a hard cap)
 *
 * At 80%+ usage: adds CSS modifier class `wpaim-usage-meter--warn` (yellow).
 * At 100%:       adds `wpaim-usage-meter--hit` (red) + shows "Limit reached" copy.
 *
 * @returns {import('@wordpress/element').ReactNode|null}
 */
export default function UsageMeter() {
    const { entitlement } = useAuth();

    if ( ! entitlement ) {
        return null;
    }

    const {
        plan,
        tokens_used = 0,
        tokens_limit = null,
        resets_at = null,
    } = entitlement;

    // Hidden conditions.
    if ( plan === 'pro' || plan === 'none' || tokens_limit === null ) {
        return null;
    }

    const pct = Math.min( ( tokens_used / tokens_limit ) * 100, 100 );
    const isWarn = pct >= 80 && pct < 100;
    const isHit = pct >= 100;

    const meterClass = [
        'wpaim-usage-meter',
        isWarn ? 'wpaim-usage-meter--warn' : '',
        isHit ? 'wpaim-usage-meter--hit' : '',
    ]
        .filter( Boolean )
        .join( ' ' );

    const resetsDate = resets_at
        ? new Date( resets_at ).toLocaleDateString( undefined, {
              month: 'short',
              day: 'numeric',
          } )
        : null;

    return (
        <div className={ meterClass } role="status" aria-label={ __( 'Token usage', 'wp-ai-mind' ) }>
            <div className="wpaim-usage-meter__bar-track">
                <div
                    className="wpaim-usage-meter__bar-fill"
                    style={ { width: `${ pct }%` } }
                    aria-valuenow={ Math.round( pct ) }
                    aria-valuemin={ 0 }
                    aria-valuemax={ 100 }
                    role="progressbar"
                />
            </div>

            <div className="wpaim-usage-meter__labels">
                { isHit ? (
                    <span className="wpaim-usage-meter__count wpaim-usage-meter__count--hit">
                        { __( 'Limit reached', 'wp-ai-mind' ) }
                    </span>
                ) : (
                    <span className="wpaim-usage-meter__count">
                        { formatNumber( tokens_used ) }
                        { ' / ' }
                        { formatNumber( tokens_limit ) }
                        { ' ' }
                        { __( 'tokens', 'wp-ai-mind' ) }
                    </span>
                ) }

                { resetsDate && (
                    <span className="wpaim-usage-meter__resets">
                        { __( 'Resets', 'wp-ai-mind' ) }
                        { ' ' }
                        { resetsDate }
                    </span>
                ) }
            </div>

            <a
                href={ LEMONSQUEEZY_CHECKOUT_URL }
                className="wpaim-usage-meter__upgrade"
                target="_blank"
                rel="noreferrer"
            >
                { __( 'Upgrade to Pro →', 'wp-ai-mind' ) }
            </a>
        </div>
    );
}
```

- [ ] **Step 5.2: Commit**

```bash
git add src/admin/shared/UsageMeter.jsx
git commit -m "feat(usage): add UsageMeter component"
```

---

## Task 6: UsageWarning + UpgradeModal components

**Files:** `src/admin/shared/UsageWarning.jsx`, `src/admin/shared/UpgradeModal.jsx`

- [ ] **Step 6.1: Create `src/admin/shared/UsageWarning.jsx`**

```jsx
import { __ } from '@wordpress/i18n';
import { useAuth } from '../auth/AuthContext';
import { LEMONSQUEEZY_CHECKOUT_URL } from './constants';

/**
 * UsageWarning — inline banner shown when token usage is >= 80%.
 *
 * Hidden when:
 * - pct < 80
 * - plan === 'pro'
 * - plan === 'none'
 * - tokens_limit is null
 *
 * At 80–99%: yellow warning banner.
 * At 100%:   red "limit reached" banner with resets_at date.
 *
 * @returns {import('@wordpress/element').ReactNode|null}
 */
export default function UsageWarning() {
    const { entitlement } = useAuth();

    if ( ! entitlement ) {
        return null;
    }

    const {
        plan,
        tokens_used = 0,
        tokens_limit = null,
        resets_at = null,
    } = entitlement;

    if ( plan === 'pro' || plan === 'none' || tokens_limit === null ) {
        return null;
    }

    const pct = Math.min( ( tokens_used / tokens_limit ) * 100, 100 );

    if ( pct < 80 ) {
        return null;
    }

    const isHit = pct >= 100;

    const resetsDate = resets_at
        ? new Date( resets_at ).toLocaleDateString( undefined, {
              month: 'long',
              day: 'numeric',
          } )
        : null;

    const bannerClass = isHit
        ? 'wpaim-usage-warning wpaim-usage-warning--hit'
        : 'wpaim-usage-warning wpaim-usage-warning--warn';

    return (
        <div className={ bannerClass } role="alert">
            <p className="wpaim-usage-warning__text">
                { isHit ? (
                    <>
                        { __( 'Monthly limit reached. Chats unavailable until', 'wp-ai-mind' ) }
                        { resetsDate ? ` ${ resetsDate }.` : '.' }
                    </>
                ) : (
                    <>
                        { __( "You've used", 'wp-ai-mind' ) }
                        { ` ${ Math.round( pct ) }% ` }
                        { __( 'of your monthly credits.', 'wp-ai-mind' ) }
                    </>
                ) }
            </p>
            <a
                href={ LEMONSQUEEZY_CHECKOUT_URL }
                className="wpaim-usage-warning__upgrade"
                target="_blank"
                rel="noreferrer"
            >
                { __( 'Upgrade to Pro →', 'wp-ai-mind' ) }
            </a>
        </div>
    );
}
```

- [ ] **Step 6.2: Create `src/admin/shared/UpgradeModal.jsx`**

```jsx
import { __ } from '@wordpress/i18n';
import { useAuth } from '../auth/AuthContext';
import { LEMONSQUEEZY_CHECKOUT_URL } from './constants';

/**
 * UpgradeModal — overlay modal shown when a 429 / token_limit_exceeded error
 * is received from any AI feature.
 *
 * @param {Object}   props
 * @param {boolean}  props.isOpen  - Whether the modal is visible.
 * @param {Function} props.onClose - Callback to close the modal.
 * @returns {import('@wordpress/element').ReactNode|null}
 */
export default function UpgradeModal( { isOpen, onClose } ) {
    const { entitlement } = useAuth();

    if ( ! isOpen ) {
        return null;
    }

    const resets_at = entitlement?.resets_at ?? null;
    const resetsDate = resets_at
        ? new Date( resets_at ).toLocaleDateString( undefined, {
              month: 'long',
              day: 'numeric',
          } )
        : null;

    function handleOverlayClick() {
        onClose();
    }

    function handleCardClick( e ) {
        // Prevent clicks inside the card from bubbling to the overlay.
        e.stopPropagation();
    }

    return (
        /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */
        <div className="wpaim-modal-overlay" onClick={ handleOverlayClick }>
            { /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions */ }
            <div
                className="wpaim-modal-card"
                onClick={ handleCardClick }
                role="dialog"
                aria-modal="true"
                aria-labelledby="wpaim-upgrade-modal-title"
            >
                <h2
                    id="wpaim-upgrade-modal-title"
                    className="wpaim-modal-card__title"
                >
                    { __( 'Monthly limit reached', 'wp-ai-mind' ) }
                </h2>

                <p className="wpaim-modal-card__body">
                    { __( "You've used all of your free monthly tokens. Upgrade to Pro for unlimited usage, or wait until your allowance resets.", 'wp-ai-mind' ) }
                </p>

                { resetsDate && (
                    <p className="wpaim-modal-card__resets">
                        { __( 'Your free allowance resets on', 'wp-ai-mind' ) }
                        { ` ${ resetsDate }.` }
                    </p>
                ) }

                <div className="wpaim-modal-card__actions">
                    <a
                        href={ LEMONSQUEEZY_CHECKOUT_URL }
                        className="wpaim-btn wpaim-btn--primary"
                        target="_blank"
                        rel="noreferrer"
                    >
                        { __( 'Upgrade to Pro', 'wp-ai-mind' ) }
                    </a>

                    <button
                        type="button"
                        className="wpaim-btn wpaim-btn--ghost"
                        onClick={ onClose }
                    >
                        { resetsDate
                            ? `${ __( 'Wait until', 'wp-ai-mind' ) } ${ resetsDate }`
                            : __( 'Wait for reset', 'wp-ai-mind' ) }
                    </button>
                </div>
            </div>
        </div>
    );
}
```

- [ ] **Step 6.3: Commit**

```bash
git add src/admin/shared/UsageWarning.jsx src/admin/shared/UpgradeModal.jsx
git commit -m "feat(usage): add UsageWarning and UpgradeModal components"
```

---

## Task 7: ProvidersTab.jsx — conditional API key section

**File:** `src/admin/settings/ProvidersTab.jsx`

The existing file has two sections: "Default Providers" and "API Keys" (lines 84–119). Wrap the API Keys section with a Pro gate.

- [ ] **Step 7.1: Add imports at the top of `ProvidersTab.jsx`**

Add these two imports immediately after the existing `import { useState } from '@wordpress/element';` line:

```jsx
import { __ } from '@wordpress/i18n';
import { useAuth } from '../auth/AuthContext';
import { LEMONSQUEEZY_CHECKOUT_URL } from '../shared/constants';
```

- [ ] **Step 7.2: Read the feature flag inside the component**

Add the following two lines at the top of the `ProvidersTab` function body, immediately after the `const apiKeys = settings?.api_keys ?? {};` line:

```jsx
const { entitlement } = useAuth();
const isPro = Boolean( entitlement?.features?.own_key );
```

- [ ] **Step 7.3: Replace the API Keys section**

Locate the existing `{ /* API key inputs */ }` section (starts at `<section className="wpaim-settings-section">` after the Default Providers section). Replace it with the gated version:

```jsx
{ /* API key inputs — Pro only */ }
{ isPro ? (
    <section className="wpaim-settings-section">
        <h3 className="wpaim-settings-section-title">
            { __( 'API Keys', 'wp-ai-mind' ) }
        </h3>

        { API_KEY_PROVIDERS.map( ( { id, label } ) => (
            <div
                key={ id }
                className="wpaim-field-row wpaim-field-row--key"
            >
                <div className="wpaim-field-input-group">
                    <TextControl
                        label={ label }
                        type="password"
                        value={ dirty[ id ] ?? '' }
                        placeholder={
                            apiKeys[ id ]
                                ? '••••••••••••'
                                : __( 'Enter API key…', 'wp-ai-mind' )
                        }
                        onChange={ ( val ) =>
                            handleKeyChange( id, val )
                        }
                        autoComplete="new-password"
                        __nextHasNoMarginBottom
                    />
                    <Button
                        variant="primary"
                        disabled={
                            isSaving || dirty[ id ] === undefined
                        }
                        onClick={ () => handleSaveKey( id ) }
                    >
                        { isSaving
                            ? __( 'Saving…', 'wp-ai-mind' )
                            : __( 'Save', 'wp-ai-mind' ) }
                    </Button>
                </div>
            </div>
        ) ) }
    </section>
) : (
    <section className="wpaim-settings-section wpaim-settings-section--locked">
        <h3 className="wpaim-settings-section-title">
            { __( 'API Keys', 'wp-ai-mind' ) }
        </h3>
        <p className="wpaim-settings-section__description">
            { __( 'Your plan uses shared infrastructure. Upgrade to Pro to connect your own API keys.', 'wp-ai-mind' ) }
        </p>
        <a
            href={ LEMONSQUEEZY_CHECKOUT_URL }
            className="wpaim-btn wpaim-btn--primary"
            target="_blank"
            rel="noreferrer"
        >
            { __( 'Upgrade to Pro', 'wp-ai-mind' ) }
        </a>
    </section>
) }
```

Note: The `label` prop on `TextControl` inside the Pro gate now uses `{ label }` from the `API_KEY_PROVIDERS` array (unchanged from the original). The `isSaving` and `dirty` variables are already in scope from the existing component props and state.

- [ ] **Step 7.4: Commit**

```bash
git add src/admin/settings/ProvidersTab.jsx
git commit -m "feat(settings): gate API key inputs behind Pro entitlement"
```

---

## Task 8: 429 error handling in ChatApp + other AI feature components

**File:** `src/admin/components/Chat/ChatApp.jsx` (and pattern for other AI components)

- [ ] **Step 8.1: Add UpgradeModal import to `ChatApp.jsx`**

Add this import at the top of `ChatApp.jsx`, after the existing `apiFetch` import:

```jsx
import { useState } from '@wordpress/element'; // already present — ensure it's there
import { __ } from '@wordpress/i18n';
import UpgradeModal from '../../shared/UpgradeModal';
```

Note: `useState` is already imported. Only add `__` and `UpgradeModal` imports.

- [ ] **Step 8.2: Add `showUpgrade` state and `handleApiError` helper to `ChatApp`**

Inside the `ChatApp` function body, add the following after the existing `useState` declarations:

```jsx
const [ showUpgrade, setShowUpgrade ] = useState( false );

function handleApiError( err ) {
    if ( err?.status === 429 || err?.data?.code === 'token_limit_exceeded' ) {
        setShowUpgrade( true );
        return;
    }
    const errorText =
        err?.message ?? __( 'Something went wrong. Please try again.', 'wp-ai-mind' );
    setMessages( ( prev ) => [
        ...prev,
        { role: 'assistant', content: errorText, isError: true },
    ] );
}
```

- [ ] **Step 8.3: Replace the catch block in `sendMessage()`**

Locate the existing `catch ( err )` block in `sendMessage()`:

```jsx
// BEFORE:
} catch ( err ) {
    const errorText =
        err?.message ?? 'Something went wrong. Please try again.';
    setMessages( ( prev ) => [
        ...prev,
        { role: 'assistant', content: errorText, isError: true },
    ] );
}
```

Replace it with:

```jsx
// AFTER:
} catch ( err ) {
    handleApiError( err );
}
```

- [ ] **Step 8.4: Add `<UpgradeModal>` to the JSX return**

Inside the `return (...)` of `ChatApp`, add the modal immediately before the closing `</div>` of `wpaim-shell`:

```jsx
<UpgradeModal
    isOpen={ showUpgrade }
    onClose={ () => setShowUpgrade( false ) }
/>
```

The full `return` structure becomes:

```jsx
return (
    <div className="wpaim-shell">
        <aside className="wpaim-sidebar">
            { /* ... sidebar content unchanged ... */ }
        </aside>

        <main className="wpaim-main">
            { /* ... main content unchanged ... */ }
        </main>

        <aside className="wpaim-right-panel">
            { /* ... right panel content unchanged ... */ }
        </aside>

        <UpgradeModal
            isOpen={ showUpgrade }
            onClose={ () => setShowUpgrade( false ) }
        />
    </div>
);
```

- [ ] **Step 8.5: Apply the same pattern to other AI feature components**

The identical pattern (import `UpgradeModal`, add `showUpgrade` state, add `handleApiError`, replace catch block, add `<UpgradeModal>` in JSX) must be applied to:

- `src/admin/components/Generator/GeneratorWizard.jsx` — replace the `catch` in the generate API call
- `src/admin/components/Seo/SeoWorkArea.jsx` — replace the `catch` in the SEO analysis API call
- `src/admin/components/Images/ImagesWorkArea.jsx` — replace the `catch` in the image generation API call

The only difference in each file is the path to `UpgradeModal`:

```jsx
// From GeneratorWizard (adjust depth as needed):
import UpgradeModal from '../../shared/UpgradeModal';
```

Verify the relative path depth matches the file location before committing.

- [ ] **Step 8.6: Commit**

```bash
git add src/admin/components/Chat/ChatApp.jsx
# Add any other AI feature files edited in step 8.5:
# git add src/admin/components/Generator/GeneratorWizard.jsx
# git add src/admin/components/Seo/SeoWorkArea.jsx
# git add src/admin/components/Images/ImagesWorkArea.jsx
git commit -m "feat(chat): show UpgradeModal on 429 token limit error"
```

---

## Task 9: DashboardApp — add UsageMeter + UsageWarning

**File:** `src/admin/dashboard/DashboardApp.jsx`

- [ ] **Step 9.1: Add imports**

Add these two imports at the top of `DashboardApp.jsx`, after the existing component imports:

```jsx
import UsageMeter from '../shared/UsageMeter';
import UsageWarning from '../shared/UsageWarning';
```

- [ ] **Step 9.2: Insert components into the JSX**

Locate the existing JSX inside `DashboardApp`. The current structure (simplified) is:

```jsx
return (
    <div className="wpaim-dashboard">
        { /* Top bar */ }
        <div className="wpaim-dash-topbar">...</div>
        <StatusBanner bannerState={ bannerState } urls={ urls } />
        <div className="wpaim-dash-body">
            <StartTiles urls={ urls } />
            <ResourceList ... />
        </div>
        <PageFooter ... />
    </div>
);
```

Replace with:

```jsx
return (
    <div className="wpaim-dashboard">
        { /* Top bar */ }
        <div className="wpaim-dash-topbar">
            <div>
                <div className="wpaim-dash-title">WP AI Mind</div>
                <div className="wpaim-dash-subtitle">
                    AI-powered content creation for WordPress
                </div>
            </div>
            <span className="wpaim-dash-version">v{ version }</span>
        </div>

        { /* Usage warning — shown at top when >= 80% used */ }
        <UsageWarning />

        <StatusBanner bannerState={ bannerState } urls={ urls } />

        { /* Usage meter — shown below status banner */ }
        <UsageMeter />

        <div className="wpaim-dash-body">
            <StartTiles urls={ urls } />
            <ResourceList
                resourceUrls={ resourceUrls }
                version={ version }
            />
        </div>

        <PageFooter urls={ urls } runSetupUrl={ runSetupUrl } />
    </div>
);
```

`UsageWarning` goes at the very top so it's impossible to miss at 80%+. `UsageMeter` sits between `StatusBanner` and the main body tiles where it reads naturally.

- [ ] **Step 9.3: Commit**

```bash
git add src/admin/dashboard/DashboardApp.jsx
git commit -m "feat(dashboard): add UsageMeter and UsageWarning to dashboard"
```

---

## Task 10: Manual integration verification

No unit tests are written for React components in this plugin. Verify the full flow manually in the browser after `npm run build`.

- [ ] **Step 10.1: Build assets**

```bash
npm run build
# Expected: exits 0, no TypeScript/JSX errors, assets/ directory updated
```

- [ ] **Step 10.2: Restart Docker to clear OPcache**

```bash
docker restart blognjohanssoneu-wordpress-1
```

- [ ] **Step 10.3: Auth gate — unauthenticated**

1. Log out of the NJ account from the plugin (or manually set `window.wpAiMindData.isAuthenticated = false` in DevTools for a quick smoke test).
2. Navigate to each admin page: Chat, Dashboard, Settings.
3. Expected: every page shows the `wpaim-auth-card` with the "WP AI Mind" heading — NOT the AI interface.
4. Confirm the tab switcher works: click "Sign up →" → SignupForm appears; click "Log in →" → LoginForm appears.

- [ ] **Step 10.4: Auth gate — login flow**

1. Enter valid credentials in the LoginForm.
2. Expected: on successful POST to `/wp-ai-mind/v1/nj/login`, the auth card disappears and the full UI renders.
3. Reload the page — expected: stays authenticated (PHP still serves `isAuthenticated: true` in localised data).

- [ ] **Step 10.5: ProvidersTab — free-tier vs Pro**

1. Log in as a **free-tier** account.
2. Navigate to Settings → Providers tab.
3. Expected: "API Keys" section shows the upgrade CTA paragraph and "Upgrade to Pro" link — no TextControl inputs visible.
4. Log in as a **Pro** account.
5. Expected: "API Keys" section shows the three TextControl fields for Claude, OpenAI, Gemini.

- [ ] **Step 10.6: UpgradeModal — simulate 429**

1. In DevTools, temporarily intercept the POST to `/wp-ai-mind/v1/conversations/{id}/messages` to return `{ status: 429, data: { code: 'token_limit_exceeded' } }`.
2. Send a chat message.
3. Expected: `UpgradeModal` appears with "Monthly limit reached" heading.
4. Click the overlay → modal closes.
5. Click "Upgrade to Pro" → opens `LEMONSQUEEZY_CHECKOUT_URL` in a new tab.
6. Click "Wait until [date]" → modal closes.

- [ ] **Step 10.7: UsageMeter and UsageWarning — Dashboard**

1. With a free-tier account that has consumed > 0 tokens, open the Dashboard.
2. Expected: `UsageMeter` progress bar visible below `StatusBanner`, showing correct token counts and resets date.
3. With a Pro account, open the Dashboard.
4. Expected: `UsageMeter` and `UsageWarning` are both absent (hidden by `plan === 'pro'`).
5. Manually set `entitlement.tokens_used` to ≥ 80% of `tokens_limit` in DevTools to verify the `--warn` class and yellow warning banner appear.
6. Set to 100% to verify `--hit` class, "Limit reached" text, and red banner with resets date.

- [ ] **Step 10.8: Final build + lint check**

```bash
npm run build
./vendor/bin/phpcs --standard=phpcs.xml.dist
# Both must exit 0 before the PR is raised.
```

- [ ] **Step 10.9: Commit verification step (no code changes — just the confirmation)**

If all checks pass, note the following in the PR description:
- Auth gate verified on Chat, Dashboard, Settings pages
- Login and signup flows tested
- ProvidersTab Pro/free-tier gating confirmed
- 429 → UpgradeModal flow confirmed
- UsageMeter and UsageWarning percentages verified
- Build exits 0, PHPCS exits 0

---

## Pre-launch Checklist

Before merging to `main`, ensure these items are actioned:

- [ ] Replace `LEMONSQUEEZY_CHECKOUT_URL` placeholder in `src/admin/shared/constants.js` with the real LemonSqueezy variant checkout URL.
- [ ] Replace `ACCOUNT_DASHBOARD_URL` placeholder in `src/admin/shared/constants.js` with the real NJ account dashboard URL.
- [ ] Confirm that `window.wpAiMindData` includes `isAuthenticated`, `user`, `entitlement` (with `plan`, `tokens_used`, `tokens_limit`, `resets_at`, `features`) — these keys are set by Phase 5's PHP localisation. If Phase 5 is not yet deployed, `AuthProvider` falls back safely to `isAuthenticated: false`.
- [ ] CSS for BEM classes (`wpaim-auth-card`, `wpaim-auth-form`, `wpaim-usage-meter`, `wpaim-usage-warning`, `wpaim-modal-overlay`, `wpaim-modal-card`) must be added to `src/admin/admin.css` or a dedicated `src/admin/auth/auth.css` (enqueued via `wp_enqueue_block_style()` if scoped, or `admin.css` if global admin-only). CSS authoring is not in scope for this plan but must be done before QA sign-off.
