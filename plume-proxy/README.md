# plume-proxy

Minimal Cloudflare Worker that protects the Anthropic API key for Free/Trial/Pro Managed users.
WordPress signs requests with HMAC-SHA256; the Worker validates the signature, enforces per-tier
token limits via KV, and forwards to Anthropic.

## wp-config.php constants

```php
// URL of the deployed Worker (or set via WP Admin → Plume Settings)
define( 'PLUME_PROXY_URL', 'https://plume-proxy.plumewp.workers.dev' );
```

## First-time setup

```bash
# 1. Install dependencies
npm install

# 2. Login to Cloudflare
npx wrangler login

# 3. Create KV namespaces and update wrangler.toml
npx wrangler kv namespace create USAGE_KV
npx wrangler kv namespace create USAGE_KV --preview

# 4. Set secrets (never commit these values)
npx wrangler secret put ANTHROPIC_API_KEY
# Note: no PLUME_PROXY_SECRET wrangler secret is needed — HMAC signing uses a
# per-site rotating secret that is generated on first registration and stored in KV.

# 5. Type-check
npm run typecheck

# 6. Local development
npm run dev

# 7. Deploy
npm run deploy
```

## Smoke tests

```bash
# Missing signature → 401
curl -s -X POST http://localhost:8787/v1/chat \
  -H "Content-Type: application/json" \
  -d '{"user_id":1,"tier":"free","messages":[]}' | jq .
# {"error":"Invalid signature"}

# Wrong method → 405
curl -s http://localhost:8787/v1/chat | jq .
# {"error":"Method not allowed"}
```

## Tier limits (mirrors UsageTracker::FREE_CREDITS / PRO_MANAGED_CREDITS)

| Tier | Credits/month | Models |
|------|-------------|--------|
| free | 100 | Claude Haiku 4.5, Gemini 3.1 Flash-Lite |
| pro_managed | 500 | Claude Haiku 4.5 / Sonnet 4.6 / Opus 4.6, GPT-4.1, Gemini 3.6 Flash |
| pro_byok | — | bypasses proxy entirely |

Source of truth is `DEFAULT_TIER_MODELS` / `MONTHLY_CREDIT_LIMITS` in
`plume-proxy/src/index.ts` (overridable at runtime via the `config:models`
KV key) — this table is a snapshot, not authoritative.

Only the providers in `enabled_providers` are served natively. The compiled
default is Claude only (#1020): a managed chat for OpenAI or Gemini is answered
by Claude with the tier's default Claude model. See
[Managed providers](#managed-providers-enabled_providers).

## KV hotfix procedure (model weights)

Model token weights can be overridden via KV without a code deployment.
This allows emergency pricing corrections without a wrangler deploy cycle.

```bash
# Override a single model's weight (binding is USAGE_KV; shape: model_token_weight key)
# --remote targets the deployed Worker's KV; Wrangler 4 defaults to local storage.
npx wrangler kv key put --binding=USAGE_KV "config:models" '{"model_token_weight":{"claude-opus-4-6":5}}' --remote
```

`getModelConfig()` in `index.ts` reads `config:models` from `USAGE_KV` and deep-merges
`parsed.model_token_weight` into `DEFAULT_MODEL_TOKEN_WEIGHT`, so a partial override
(only the Opus entry) does not discard other model weights.

Remove the key to revert to the code default:

```bash
npx wrangler kv key delete --binding=USAGE_KV "config:models" --remote
```

> **`put` replaces the whole JSON value.** `config:models` is a single JSON
> document holding `tier_models`, `model_token_weight` and `enabled_providers`.
> Read the current value first (`npx wrangler kv key get --binding=USAGE_KV
> "config:models" --remote`) and repeat every field you want to keep in the new
> value, otherwise the put silently drops them.

## Managed providers (`enabled_providers`)

`config:models` may carry `enabled_providers`, the managed providers the Worker
serves for `/v1/chat`:

```json
{ "enabled_providers": [ "claude", "gemini" ] }
```

- Absent, or not an array → compiled default `DEFAULT_ENABLED_PROVIDERS`
  (`[ "claude" ]` while #1020 is in force).
- Unknown slugs and duplicates are dropped; `claude` is always added.
- A provider is **served** when it is enabled *and* its allow-list for the
  caller's tier is a non-empty array. A request for a provider that is not
  served is answered by Claude with the tier's default Claude model
  (`TEMPORARY(#1020)`); the response reports that real `model`, credits use its
  weight, and `wrangler tail` shows a `provider_substituted` log line (no content).
- KV reads may be cached at the edge for up to about 60 s.

The plugin mirrors the default as `ManagedCatalogue::FALLBACK_ENABLED`; both
sides pin the same literal in their tests.

### Re-enabling a provider

```bash
# Gemini (after its API credit is topped up). Add --env dev for the dev Worker.
npx wrangler kv key put "config:models" '{"enabled_providers":["claude","gemini"]}' --binding USAGE_KV --remote
```

- Chats route natively within about 60 s. Plugins that read `/v1/config` pick up
  the change when their transient expires (up to 1 h).
- **OpenAI** additionally needs the #1006 Worker code (replacement model ids and
  weights) to be deployed first.
- Merge with any existing `tier_models` / `model_token_weight` overrides (see the
  caveat above).
- Back to Claude-only: delete the key (or put a value without `enabled_providers`
  if it holds other overrides):

  ```bash
  npx wrangler kv key delete "config:models" --binding USAGE_KV --remote
  npx wrangler kv key delete "config:models" --binding USAGE_KV --env dev --remote  # dev Worker
  ```

## `GET /v1/config`

Bearer-authenticated and read-only. Response for the metered tiers:

```json
{
  "credit_limit": 100,
  "tier": "free",
  "default_models": {
    "claude": "claude-haiku-4-5-20251001",
    "openai": "claude-haiku-4-5-20251001",
    "gemini": "claude-haiku-4-5-20251001"
  },
  "enabled_providers": [ "claude" ],
  "models": { "claude": [ "claude-haiku-4-5-20251001" ] }
}
```

| Field | Meaning |
|---|---|
| `credit_limit` | Monthly credits for the tier. |
| `tier` | The site's tier. |
| `default_models` | Model a chat for each provider uses when it names none. For a provider that is not served this is the Claude model that answers it. A provider is omitted only if no route can be resolved (Claude's own allow-list empty). |
| `enabled_providers` | Providers served natively for this tier, in canonical order. |
| `models` | Allow-list per served provider for this tier. |

For `pro_byok`, `credit_limit`, `default_models`, `enabled_providers` and
`models` are all `null` (BYOK sites never use the proxy).

## Reverting Claude-only

The Claude-only behaviour from #1020 is temporary. It can be undone in two levels.

### Level 1: switch it off (KV only, no deploy)

```bash
# Add --env dev for the dev Worker. Merge any existing overrides into the same value.
npx wrangler kv key put "config:models" '{"enabled_providers":["claude","openai","gemini"]}' --binding USAGE_KV --remote
```

Native routing resumes within about 60 s; the substitution code stays in place
but dormant. Remember that a `put` replaces the whole `config:models` value. For
OpenAI, deploy the #1006 model changes first. To make the switch permanent, set
`DEFAULT_ENABLED_PROVIDERS` in `src/index.ts` to all providers (and its PHP
mirror `ManagedCatalogue::FALLBACK_ENABLED`, with both parity tests).

To undo Level 1 and return to Claude-only, delete the key (or put the previous
value back if it held other overrides):

```bash
npx wrangler kv key delete "config:models" --binding USAGE_KV --remote
npx wrangler kv key delete "config:models" --binding USAGE_KV --env dev --remote  # dev Worker
```

### Level 2: delete the temporary code

1. Delete `src/substitution.ts` and `test/substitution.test.ts`.
2. Run `grep -rn "TEMPORARY(#1020)" plume-proxy` and follow the
   "On removal:" note at each hit:
   - `resolveChatRoute`: throw a tagged 400 `provider_unavailable` for a
     provider that is not served, instead of calling `substituteRoute()`.
   - `handleChatProxy`: remove the translation branch and the
     `provider_substituted` log line.
   - `getDefaultModels`: no edit; a disabled provider is then omitted.
   - `DEFAULT_ENABLED_PROVIDERS`: back to all providers.
   - `ChatRoute.substituted` in `src/types.ts`: delete the field, and the
     `substituted: false` line in `resolveChatRoute`.
   - Tests: delete every `describe( 'TEMPORARY(#1020) substitution' )` block;
     re-pin the `DEFAULT_ENABLED_PROVIDERS` parity test to the new default;
     drop the `enableAllProviders()` calls if the default is all providers again.
     Untagged tests do not depend on substitution or on the Claude-only default.
3. `npm test && npm run typecheck`.

**Permanent (keep):** `enabled_providers` in `config:models` and
`sanitiseEnabledProviders()`, `isProviderServed()`, `resolveChatRoute()` (without
the substitution branch), the `/v1/config` `enabled_providers` and `models`
fields, and `ChatRoute` without `substituted`. The plugin relies on these to
build its provider and model lists from the Worker (#1006).
