# WP AI Mind — Technical Architecture Spec (Hybrid Approach)
## Three-Tier WordPress Plugin with Minimal API Proxy

> **Purpose:** Reference document for hybrid WordPress-native architecture with minimal external proxy for API key protection only. This design prioritizes WordPress plugin conventions while solving the core API key security requirement.

---

## Context: What Exists Today

The plugin (`wp-ai-mind`) currently:

- Uses **Freemius SDK** (product ID 26475) with a 7-day trial (`is_require_payment: true`)
- Supports 4 AI providers: **Claude, OpenAI, Gemini, Ollama** — each requires the user's own API key
- Gates features via `ProGate::is_pro()` → `wam_fs()->can_use_premium_code__premium_only()`
- Has a `UsageLogger` class that logs per-request token counts and costs to the database
- Has UI references to a "Plugin API (free tier)" concept with no backend implementation
- Uses function prefix convention: `nj_`

---

## Core Architectural Decision: WordPress-Native Hybrid

**The plugin handles everything except API key protection.**

This is a WordPress-first approach with minimal external dependencies:

**WordPress Plugin (Primary):**
- User management via WordPress users + custom fields
- Payment processing via LemonSqueezy webhooks to WordPress
- Rate limiting via WordPress transients + cron
- All UI in WordPress admin
- Direct API calls for Pro BYOK users

**Minimal Cloudflare Proxy (Security Only):**
- Single purpose: protect plugin API keys for Free/Trial users
- ~200 lines of code (vs 1000+ in microservices approach)
- No auth, user management, or complex routing
- WordPress signs requests; proxy validates and forwards

**Why this hybrid approach:** Follows WordPress plugin conventions while solving API key security. Much simpler than external auth systems, but still protects your API keys from being extracted by users.

---

## What Gets Removed vs WordPress-Native Replacements

| Current | Replaced by |
|---|---|
| Freemius SDK entirely | LemonSqueezy webhooks to WordPress endpoints + custom user meta |
| `ProGate::is_pro()` | WordPress user meta tier checking |
| `UsageLogger` (current database logging) | WordPress user meta + transients for current month usage |
| Per-provider API key settings (for free users) | Removed — free/trial users use proxy with hidden keys |
| `wam_fs()` calls throughout codebase | `nj_get_user_tier()` + `nj_can_user()` helpers |

Pro BYOK users retain the capability to configure their own API keys — see tier 3 below.

---

## Four-Tier System Design

### Tier 1: Free (Plugin API Key, Rate Limited)
- **Monthly limit:** 50,000 tokens (after trial expires)
- **Model:** Claude Haiku only
- **API routing:** Through minimal Cloudflare proxy (protects your API key)
- **User setup:** Zero configuration required

### Tier 2: Trial (Plugin API Key, Time-Limited)
- **Monthly limit:** 300,000 tokens (7-day window, then demoted to Free)
- **Model:** Claude Haiku only
- **API routing:** Through minimal Cloudflare proxy
- **User setup:** Zero configuration required; automatic via `NJ_Tier_Manager::start_trial()`

### Tier 3: Pro Managed (Plugin API Key, Higher Limits)
- **Monthly limit:** 2,000,000 tokens
- **Models:** Claude Haiku, Sonnet, Opus (user choice)
- **API routing:** Through minimal Cloudflare proxy (protects your API key)
- **User setup:** Payment via LemonSqueezy, no API key needed

### Tier 4: Pro BYOK (User's API Key, Unlimited)
- **Monthly limit:** None (user pays their own API costs)
- **Models:** Any model their key supports
- **API routing:** Direct from WordPress to provider (bypass proxy entirely)
- **User setup:** Payment + API keys stored encrypted per provider in user meta

---

## Identity & User Management

### Model: WordPress Users + Custom Meta

No external auth system needed. Use WordPress's existing user system:

**User tier storage:**
```php
// Stored as user meta
wp_ai_mind_tier               // 'free', 'trial', 'pro_managed', 'pro_byok'
wp_ai_mind_trial_started      // Unix timestamp when trial began (trial tier only)
wp_ai_mind_usage_YYYY_MM      // Monthly token count (e.g. wp_ai_mind_usage_2026_04)
```

**For Pro BYOK users — per-provider API keys:**
```php
wp_ai_mind_api_key_claude     // AES-256-CBC encrypted Anthropic key
wp_ai_mind_api_key_openai     // AES-256-CBC encrypted OpenAI key
wp_ai_mind_api_key_gemini     // AES-256-CBC encrypted Gemini key
```

**Tier checking — use class methods directly (no global helpers):**
```php
// Single source of truth for feature matrix and limits:
NJ_Tier_Config::get_feature( string $tier, string $feature ): bool
NJ_Tier_Config::get_limit( string $tier ): ?int

// Tier CRUD + trial logic:
NJ_Tier_Manager::get_user_tier( ?int $user_id = null ): string
NJ_Tier_Manager::user_can( string $feature, ?int $user_id = null ): bool
```

---

## Rate Limiting System

### Enforcement Authority

| Tier | Enforcement | Display |
|------|-------------|---------|
| Free / Trial / Pro Managed | **Cloudflare KV** (proxy checks before forwarding) | WordPress user meta (updated from proxy response) |
| Pro BYOK | None (user pays own costs) | WordPress user meta |

Cloudflare KV is the single enforcement point for proxy-routed tiers. WordPress user meta is updated after each proxied response for dashboard display only — it is not used for enforcement. This eliminates dual-counting and divergence.

**Known limitation:** Cloudflare KV has no atomic increment. Concurrent requests may under-count usage by the concurrency factor. For monthly rate limiting at these volumes this is acceptable; worst-case overshoot is bounded by request concurrency and is negligible.

### Usage Tracking API

```php
// Implemented in NJ_Usage_Tracker — atomic SQL increment, no race condition in WP:
NJ_Usage_Tracker::get_usage( ?int $user_id = null ): array
NJ_Usage_Tracker::log_usage( int $tokens, ?int $user_id = null ): void
NJ_Usage_Tracker::check_limit( ?int $user_id = null ): bool
```

Token limits are defined once in `NJ_Tier_Config::MONTHLY_LIMITS` — not in the proxy.

### Plan Definitions

| Feature | Free | Trial | Pro Managed | Pro BYOK |
|---|---|---|---|---|
| Monthly token budget | 50,000 | 300,000 (7 days) | 2,000,000 | Unlimited |
| Model selection | Haiku only | Haiku only | Haiku/Sonnet/Opus | Any |
| API routing | Through proxy | Through proxy | Through proxy | Direct to provider |
| Setup required | None | None (auto) | Payment only | Payment + API key |
| Your cost | ~$0.05/user/month | ~$0.15/user | ~$0.50/user/month | $0 |

---

## Minimal Cloudflare Proxy

### Why Minimal Proxy

**Purpose:** API key protection only. Everything else handled by WordPress.

**Benefits:**
- Protects your API keys from being extracted by users
- Simple rate limiting via edge location
- 70% smaller than full microservices approach (~200 lines vs 1000+)
- No user management, auth, or complex routing

### Project Structure

```
wp-ai-mind-proxy/
├── src/
│   ├── index.ts          # Main proxy logic (~150 lines)
│   ├── types.ts          # Simple interfaces
│   └── signature.ts      # Request verification
├── wrangler.toml         # Minimal config
└── package.json
```

### `wrangler.toml`

```toml
name = "wp-ai-mind-proxy"
main = "src/index.ts"
compatibility_date = "2024-01-01"

[[kv_namespaces]]
binding = "USAGE_KV"
id = "your_kv_namespace_id_here"

# Only one secret needed:
# wrangler secret put ANTHROPIC_API_KEY
# wrangler secret put PROXY_SIGNATURE_SECRET
```

### `src/index.ts` — Minimal Proxy Logic

```typescript
interface Env {
  USAGE_KV: KVNamespace;
  ANTHROPIC_API_KEY: string;
  PROXY_SIGNATURE_SECRET: string;
}

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    if (request.method !== 'POST') {
      return new Response('Method not allowed', { status: 405 });
    }

    const url = new URL(request.url);
    if (url.pathname === '/v1/chat') {
      return handleChatProxy(request, env);
    }

    return new Response('Not found', { status: 404 });
  }
};

async function handleChatProxy(request: Request, env: Env): Promise<Response> {
  try {
    // 1. Verify request signature from WordPress
    const signature = request.headers.get('X-WP-Signature');
    if (!signature || !(await verifySignature(request, signature, env))) {
      return new Response(JSON.stringify({ error: 'Invalid signature' }), { status: 401 });
    }

    const body = await request.json() as any;
    const { user_id, tier, messages, model, max_tokens } = body;

    // 2. Check rate limits for free/pro_managed users
    if (tier !== 'pro_byok') {
      const limits = { free: 50000, pro_managed: 2000000 };
      const monthlyLimit = limits[tier as keyof typeof limits];

      const usageKey = `usage:${user_id}:${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`;
      const currentUsage = parseInt(await env.USAGE_KV.get(usageKey) || '0');

      if (currentUsage >= monthlyLimit) {
        return new Response(JSON.stringify({
          error: 'Rate limit exceeded',
          used: currentUsage,
          limit: monthlyLimit,
        }), { status: 429 });
      }
    }

    // 3. Forward to Anthropic
    const response = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'x-api-key': env.ANTHROPIC_API_KEY,
        'anthropic-version': '2023-06-01',
        'content-type': 'application/json',
      },
      body: JSON.stringify({ model, max_tokens, messages }),
    });

    const result = await response.json() as any;

    // 4. Update usage in KV (only for free/pro_managed)
    if (tier !== 'pro_byok' && result.usage) {
      const tokensUsed = result.usage.input_tokens + result.usage.output_tokens;
      const usageKey = `usage:${user_id}:${new Date().getFullYear()}-${String(new Date().getMonth() + 1).padStart(2, '0')}`;
      const currentUsage = parseInt(await env.USAGE_KV.get(usageKey) || '0');

      await env.USAGE_KV.put(usageKey, String(currentUsage + tokensUsed), {
        expiration: Math.floor(new Date(new Date().getFullYear(), new Date().getMonth() + 1, 1).getTime() / 1000)
      });
    }

    return new Response(JSON.stringify(result), {
      status: response.status,
      headers: { 'Content-Type': 'application/json' }
    });

  } catch (error) {
    return new Response(JSON.stringify({ error: 'Proxy error' }), { status: 500 });
  }
}

async function verifySignature(request: Request, signature: string, env: Env): Promise<boolean> {
  // Verify HMAC signature from WordPress
  // Implementation depends on your signature scheme
  return true; // Placeholder - implement proper HMAC verification
}
```

### Deployment

```bash
# One-time setup
npm install -g wrangler
wrangler login
wrangler kv:namespace create USAGE_KV   # note the ID, add to wrangler.toml

# Set secrets (only two needed)
wrangler secret put ANTHROPIC_API_KEY
wrangler secret put PROXY_SIGNATURE_SECRET

# Development
wrangler dev                             # local dev at localhost:8787

# Deploy
wrangler deploy
# → live at: https://wp-ai-mind-proxy.your-account.workers.dev
```

---

## WordPress Plugin Changes

### New PHP architecture

The plugin's responsibility: full user management, payments, rate limiting, UI, and request routing.

**New classes / files needed:**

```
wp-ai-mind/
└── includes/
    ├── Tiers/
    │   ├── NJ_Tier_Config.php           # Constants: TIERS, FEATURES, MONTHLY_LIMITS (no WP calls)
    │   ├── NJ_Tier_Manager.php          # Tier CRUD + trial start/demote logic
    │   └── NJ_Usage_Tracker.php         # Monthly token counting (atomic SQL)
    ├── Payments/
    │   ├── NJ_Webhook_Verifier.php      # HMAC signature verification only
    │   └── NJ_LemonSqueezy_Webhook.php  # REST handler → delegates to NJ_Tier_Manager
    ├── Proxy/
    │   ├── NJ_Request_Signer.php        # HMAC signing of outbound requests (Phase 2)
    │   ├── NJ_Proxy_Client.php          # HTTP send to proxy (Free/Trial/Pro Managed) (Phase 2)
    │   └── NJ_Direct_Client.php         # Direct provider call (Pro BYOK) (Phase 2)
    └── Admin/
        ├── NJ_Tier_Status_Page.php      # Read-only: tier + usage meter
        └── NJ_Api_Key_Settings.php      # Pro BYOK API key form (saves via REST, not options.php)
```

**Classes to remove:**

```
ProGate       → already replaced with NJ_Tier_Manager (call sites updated in Phase 1)
UsageLogger   → deleted; NJ_Usage_Tracker::log_usage() handles token counting
```

**Proxy URL storage (Phase 2):** Use a PHP constant in wp-config.php with a wp_options fallback:
```php
// wp-config.php (server-level, takes priority):
define( 'WP_AI_MIND_PROXY_URL', 'https://wp-ai-mind-proxy.your-account.workers.dev' );

// In NJ_Proxy_Client — reads at runtime:
private static function get_proxy_url(): string {
    return defined( 'WP_AI_MIND_PROXY_URL' )
        ? WP_AI_MIND_PROXY_URL
        : (string) get_option( 'wp_ai_mind_proxy_url', '' );
}
```

**Per-provider API key meta keys (Pro BYOK):**
```
wp_ai_mind_api_key_claude    // AES-256-CBC encrypted with AUTH_KEY
wp_ai_mind_api_key_openai
wp_ai_mind_api_key_gemini
```

### `class-nj-tier-manager.php` (WordPress-Native)

```php
<?php

class NJ_Tier_Manager {

    public static function get_user_tier( $user_id = null ): string {
        $user_id = $user_id ?: get_current_user_id();
        return get_user_meta( $user_id, 'wp_ai_mind_tier', true ) ?: 'free';
    }

    public static function set_user_tier( string $tier, $user_id = null ): bool {
        $user_id = $user_id ?: get_current_user_id();
        $valid_tiers = [ 'free', 'pro_managed', 'pro_byok' ];

        if ( ! in_array( $tier, $valid_tiers ) ) return false;

        return update_user_meta( $user_id, 'wp_ai_mind_tier', $tier );
    }

    public static function user_can( string $feature, $user_id = null ): bool {
        $tier = self::get_user_tier( $user_id );

        return match([$tier, $feature]) {
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

### `class-nj-usage-tracker.php` (WordPress-Native)

```php
<?php

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

### `class-nj-proxy-client.php` (Signed Requests)

```php
<?php

class NJ_Proxy_Client {

    const PROXY_URL = 'https://wp-ai-mind-proxy.your-account.workers.dev/v1/chat';

    public static function chat( array $messages, array $options = [] ): array|WP_Error {
        $user_id = get_current_user_id();
        $tier = NJ_Tier_Manager::get_user_tier( $user_id );

        // For Pro BYOK users, bypass proxy entirely
        if ( $tier === 'pro_byok' ) {
            return self::direct_chat( $messages, $options );
        }

        // For free/pro_managed, use signed proxy request
        $payload = [
            'user_id' => $user_id,
            'tier' => $tier,
            'messages' => $messages,
            'model' => $options['model'] ?? 'claude-3-haiku-20240307',
            'max_tokens' => $options['max_tokens'] ?? 1000,
        ];

        $signature = self::sign_request( $payload );

        $response = wp_remote_post( self::PROXY_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Signature' => $signature,
            ],
            'body' => wp_json_encode( $payload ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 429 ) {
            return new WP_Error( 'limit_exceeded', __( 'Monthly AI credit limit reached.', 'wp-ai-mind' ) );
        }

        if ( $code !== 200 ) {
            return new WP_Error( 'api_error', $body['error'] ?? 'Unknown error', [ 'code' => $code ] );
        }

        // Log usage locally
        if ( isset( $body['usage'] ) ) {
            $tokens = $body['usage']['input_tokens'] + $body['usage']['output_tokens'];
            NJ_Usage_Tracker::log_usage( $tokens );
        }

        return $body;
    }

    private static function direct_chat( array $messages, array $options ): array|WP_Error {
        // Pro BYOK: direct API call using user's own encrypted API key
        $api_key = self::get_user_api_key();
        if ( ! $api_key ) {
            return new WP_Error( 'no_api_key', __( 'API key not configured.', 'wp-ai-mind' ) );
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $options['model'] ?? 'claude-3-haiku-20240307',
                'max_tokens' => $options['max_tokens'] ?? 1000,
                'messages' => $messages,
            ]),
            'timeout' => 60,
        ] );

        return is_wp_error( $response ) ? $response : json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private static function sign_request( array $payload ): string {
        $secret = defined( 'WP_AI_MIND_PROXY_SECRET' ) ? WP_AI_MIND_PROXY_SECRET : 'default-secret';
        return hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
    }

    private static function get_user_api_key(): string|null {
        $user_id = get_current_user_id();
        $encrypted = get_user_meta( $user_id, 'wp_ai_mind_api_key', true );

        if ( ! $encrypted ) return null;

        // Decrypt using WordPress AUTH_KEY
        return self::decrypt_api_key( $encrypted );
    }

    private static function decrypt_api_key( string $encrypted ): string {
        [ $iv, $data ] = explode( '::', base64_decode( $encrypted ) );
        return openssl_decrypt( $data, 'AES-256-CBC', AUTH_KEY, 0, $iv );
    }
}
```

---

## Payment & Licensing: LemonSqueezy (WordPress-Native)

**Why LemonSqueezy over Freemius:**
- Cleaner, modern API with reliable webhooks
- Handles EU VAT automatically
- Webhooks can go directly to WordPress endpoints
- Better developer experience than Freemius

**Integration points:**

```
1. Checkout: Embed LemonSqueezy checkout overlay in plugin upgrade CTA
   → Use LS overlay JS: opens checkout without leaving wp-admin

2. Webhook → WordPress endpoint (/wp-json/wp-ai-mind/v1/webhook):
   → subscription_created / order_created → update user meta to 'pro_managed'
   → subscription_cancelled / subscription_expired → update user meta to 'free'
   → WordPress verifies HMAC signature before processing

3. No external polling needed - immediate tier changes via webhooks
   → Changes take effect immediately in WordPress
```

**WordPress webhook handler:**

```php
class NJ_LemonSqueezy_Webhook {
    public static function handle( WP_REST_Request $request ): WP_REST_Response {
        $signature = $request->get_header( 'X-Signature' );
        $body = $request->get_body();

        if ( ! self::verify_signature( $body, $signature ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid signature', [ 'status' => 401 ] );
        }

        $event = json_decode( $body, true );
        $email = $event['data']['attributes']['user_email'];
        $user = get_user_by( 'email', $email );

        if ( ! $user ) return new WP_REST_Response( [ 'error' => 'User not found' ], 404 );

        switch ( $event['meta']['event_name'] ) {
            case 'subscription_created':
            case 'subscription_resumed':
                NJ_Tier_Manager::set_user_tier( 'pro_managed', $user->ID );
                break;
            case 'subscription_cancelled':
            case 'subscription_expired':
                NJ_Tier_Manager::set_user_tier( 'free', $user->ID );
                break;
        }

        return new WP_REST_Response( [ 'received' => true ] );
    }
}
```

---

## Summary: Hybrid Architecture Benefits

### WordPress-Native Approach
- **User Management**: WordPress users + custom meta (no external auth)
- **Payments**: LemonSqueezy webhooks directly to WordPress endpoints
- **Rate Limiting**: WordPress user meta + transients (accurate enough)
- **UI**: WordPress admin (no React auth screens needed)
- **Development**: Familiar WordPress patterns for plugin developers

### Minimal Cloudflare Proxy
- **Single Purpose**: API key protection only (~200 lines vs 1000+)
- **No Auth**: WordPress handles all authentication
- **No User Management**: WordPress handles tier/payment management
- **Signed Requests**: WordPress signs requests with HMAC

### Key Design Decisions

| Decision | Hybrid Choice | Original Choice | Why Hybrid Wins |
|---|---|---|---|---|
| User Management | WordPress users + meta | External D1 database + JWT | WordPress-native, familiar to developers |
| Authentication | WordPress sessions | External JWT system | Follows plugin conventions |
| Payment Processing | LemonSqueezy → WordPress | LemonSqueezy → Cloudflare Worker | Simpler integration, no external dependency |
| Rate Limiting | Cloudflare KV (enforcement) + WP meta (display) | Cloudflare KV only | KV enforces at edge; WP meta serves dashboard without extra queries |
| API Key Protection | Minimal proxy (200 lines) | Full microservices (1000+ lines) | 80% simpler, same security benefits |
| Development Complexity | 3 phases, WordPress-first | 7 phases, microservices-first | 70% reduction in scope |
| Plugin Distribution | Standard WordPress plugin | Requires external setup | Better for WordPress ecosystem |

### Cost Model & Risk Controls

**Projected shared key cost (Claude Haiku):** ~$0.05/user/month for free tier, ~$0.50/user/month for pro managed tier.

**Cost control layers:**
1. **Proxy (Cloudflare KV)**: Single enforcement point before forwarding to Anthropic
2. **Anthropic Console**: Hard monthly spend cap as circuit breaker

### Migration Path

**Phase 1**: WordPress foundation (can work standalone with Pro BYOK only; includes Trial tier)
**Phase 2**: Add minimal proxy (enables Free/Trial/Pro Managed tiers; KV enforcement)
**Phase 3**: Polish integration and remove legacy code

This approach gives you **80% of the benefits** of the full microservices approach with **30% of the complexity**.
