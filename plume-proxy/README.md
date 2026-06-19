# plume-proxy

Minimal Cloudflare Worker that protects the Anthropic API key for Free/Trial/Pro Managed users.
WordPress signs requests with HMAC-SHA256; the Worker validates the signature, enforces per-tier
token limits via KV, and forwards to Anthropic.

## wp-config.php constants

```php
// URL of the deployed Worker (or set via WP Admin → Plume Settings)
define( 'PLUME_PROXY_URL', 'https://plume-proxy.plume.workers.dev' );
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

## Tier limits (mirrors TierConfig::MONTHLY_LIMITS)

| Tier | Tokens/month | Models |
|------|-------------|--------|
| free | 50,000 | Haiku only |
| trial | 300,000 | Haiku only |
| pro_managed | 2,000,000 | Haiku, Sonnet, Opus |
| pro_byok | — | bypasses proxy entirely |

## KV hotfix procedure (model weights)

Model token weights can be overridden via KV without a code deployment.
This allows emergency pricing corrections without a wrangler deploy cycle.

```bash
# Override a single model's weight (binding is USAGE_KV; shape: model_token_weight key)
wrangler kv key put --binding=USAGE_KV "config:models" '{"model_token_weight":{"claude-opus-4-6":5}}'
```

`getModelConfig()` in `index.ts` reads `config:models` from `USAGE_KV` and deep-merges
`parsed.model_token_weight` into `DEFAULT_MODEL_TOKEN_WEIGHT`, so a partial override
(only the Opus entry) does not discard other model weights.

Remove the key to revert to the code default:

```bash
wrangler kv key delete --binding=USAGE_KV "config:models"
```
