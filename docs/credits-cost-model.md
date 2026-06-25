# Credits Cost Model

**Purpose:** Costing foundation for the credits system migration. Produces per-feature
cost data, exchange rate analysis, and corrected multipliers. Does **not** set the
free-tier credit allowance — that is a downstream decision.

**Last verified:** 2026-06-25  
**Sources:** Anthropic, OpenAI, and Google AI pricing pages; devtk.ai; pricepertoken.com; metacto.com.  
**Verify before publishing:** provider prices change without notice.

---

## How to Measure Real Usage

### Option A — Provider dashboards (no code changes)

| Provider | URL | What you get |
|----------|-----|-------------|
| Anthropic | `console.anthropic.com/usage` | Per-model daily input/output token totals |
| OpenAI | `platform.openai.com/usage` | Per-model token totals by day |
| Google | `aistudio.google.com` → project → Usage | Per-model token totals |

Divide totals by API call count (if logged elsewhere) to get average tokens per call.
Useful for validating the estimates in this document once real traffic accumulates.

### Option B — WordPress per-call debug logging (fastest path)

`AbstractProvider::maybe_log()` (`includes/Providers/AbstractProvider.php:173`) receives
both `CompletionRequest` (which has `metadata['feature']` and `model`) and
`CompletionResponse` (which has `prompt_tokens`, `completion_tokens`, `model`, `cost_usd`).

Add a single `WP_DEBUG`-gated `error_log()` there, enable WP_DEBUG on the local Docker
instance, exercise each feature ~10 times, then grep `wp-content/debug.log` for `[Plume usage]`
lines. **See Agent Prompt A** at the end of this document.

### Option C — Cloudflare Analytics Engine (permanent production analytics)

Requires Cloudflare Workers Paid plan. Logs one data point per proxy call — model, tier,
provider, raw input/output tokens, weighted tokens. Also surfaces feature name once the
`feature` field is added to `ProxyRequest`. **See Agent Prompt B** at the end of this document.

---

## Current Model Inventory

### Text models

The proxy defaults are compiled into `plume-proxy/src/index.ts:28` (`DEFAULT_TIER_MODELS`)
and the weight table at `plume-proxy/src/index.ts:48` (`DEFAULT_MODEL_TOKEN_WEIGHT`).
Both can be overridden without a Worker redeploy by writing to `USAGE_KV['config:models']`
(see `getModelConfig()` at `plume-proxy/src/index.ts:66`).

**Current compiled defaults — several models are outdated:**

| API model ID | Provider | Current weight | Status |
|---|---|---|---|
| `claude-haiku-4-5-20251001` | Anthropic | 1 | ✓ Current |
| `claude-sonnet-4-6` | Anthropic | 3 | ✓ Current |
| `claude-opus-4-6` | Anthropic | 5 | ✓ Current |
| `gpt-4o-mini` | OpenAI | 1 | Superseded by `gpt-4.1-nano` |
| `gpt-4o` | OpenAI | 10 | Superseded by `gpt-4.1` |
| `gemini-2.5-flash` | Google | 1 | Superseded by `gemini-3.5-flash` |
| `gemini-2.5-pro` | Google | 5 | Superseded by `gemini-3.1-pro` |

### Image generation models

| Path | Current plugin model | Status |
|------|---------------------|--------|
| Gemini (default, `plume_image_provider = 'gemini'`) | `imagen-3.0-generate-001` | Deprecating |
| OpenAI | `dall-e-3` | **Removed 2026-05-12 — broken today** |
| Claude | not supported | — |

Both image providers require a code change in `GeminiProvider.php` and `OpenAIProvider.php`
before they work correctly. Cost estimates below use the migration target models.

---

## Pricing Reference (2026-06-25)

### Text models — price per million tokens

| Model | Input $/M | Output $/M | Avg $/M | Recommended weight |
|-------|-----------|-----------|---------|-------------------|
| `claude-haiku-4-5-20251001` | $1.00 | $5.00 | $3.00 | **1** (baseline) |
| `claude-sonnet-4-6` | $3.00 | $15.00 | $9.00 | **3** |
| `claude-opus-4-6` | $5.00 | $25.00 | $15.00 | **5** |
| `gpt-4.1-nano` | $0.10 | $0.40 | $0.25 | **1** (very cheap; 1 is safe) |
| `gpt-4.1` | $2.00 | $8.00 | $5.00 | **2** |
| `gemini-3.5-flash` | $1.50 | $9.00 | $5.25 | **2** |
| `gemini-3.1-pro` | $2.00 | $12.00 | $7.00 | **2** |
| ~~`gpt-4o-mini`~~ (legacy) | $0.15 | $0.60 | $0.375 | — |
| ~~`gpt-4o`~~ (legacy, weight=10) | $2.50 | $10.00 | $6.25 | — |
| ~~`gemini-2.5-flash`~~ (legacy) | $0.30 | $2.50 | $1.40 | — |
| ~~`gemini-2.5-pro`~~ (legacy) | $1.25 | $10.00 | $5.63 | — |

The old `gpt-4o` weight of 10 was calibrated against now-removed $3.75/$15 pricing.
At GPT-4.1 pricing ($2/$8 avg $5/M), a weight of 2 is accurate relative to Haiku's $3/M.

### Image generation — price per image (1024×1024)

| Provider | Migration-target model | Quality | $/image |
|----------|----------------------|---------|---------|
| Gemini | `gemini-2.5-flash-image` | standard | $0.039 |
| OpenAI | `gpt-image-1.5` | medium | $0.034 |
| OpenAI | `gpt-image-1.5` | high | $0.133 |

---

## Per-Feature Cost Table

Feature access is defined in `includes/Tiers/TierConfig.php:50`.
Generator, SEO, and Images are unavailable on the free tier.

### Generator (typical: 500 in + 1 000 out tokens)

System prompt: VoiceInjector ~200–500 tok + "Post generation" instructions.
User prompt: title + keywords + tone + length ~150–250 tok.
`max_tokens` cap: 1 000 (free), 4 000 (trial), 8 000 (pro) — `plume-proxy/src/index.ts:826`.

| Model | In tok | Out tok | $/call |
|-------|--------|---------|--------|
| `claude-haiku-4-5-20251001` | 500 | 1 000 | **$0.0055** |
| `claude-sonnet-4-6` | 500 | 1 000 | **$0.0165** |
| `claude-opus-4-6` | 500 | 1 000 | **$0.0275** |
| `gpt-4.1-nano` | 500 | 1 000 | **$0.00045** |
| `gpt-4.1` | 500 | 1 000 | **$0.009** |
| `gemini-3.5-flash` | 500 | 1 000 | **$0.00975** |
| `gemini-3.1-pro` | 500 | 1 000 | **$0.013** |

### SEO (typical: 750 in + 300 out tokens)

System prompt: "You are an expert SEO specialist…" ~50 tok.
User prompt: post title + excerpt + first 2 000 chars of content ~600–800 tok.
Output: ~200–400 tok (JSON with title, meta description, keywords).

| Model | $/call |
|-------|--------|
| `claude-haiku-4-5-20251001` | **$0.00225** |
| `claude-sonnet-4-6` | **$0.00675** |
| `claude-opus-4-6` | **$0.01125** |
| `gpt-4.1-nano` | **$0.000195** |
| `gpt-4.1` | **$0.0039** |
| `gemini-3.5-flash` | **$0.003825** |
| `gemini-3.1-pro` | **$0.0051** |

### Images (3 images, 1024×1024 — default count)

Not token-based. Images module calls `UsageTracker::log_usage(count($images))`, tracking
image count rather than tokens. Costs below are direct API image prices × 3.

| Provider | Model | Quality | $/call (3 imgs) |
|----------|-------|---------|----------------|
| Gemini (default) | `gemini-2.5-flash-image` | standard | **$0.117** |
| OpenAI | `gpt-image-1.5` | medium | **$0.102** |
| OpenAI | `gpt-image-1.5` | high | **$0.399** |

### Chat (single turn: 800 in + 400 out tokens)

System prompt: VoiceInjector ~200–500 tok + optional post context injection.
User message: ~100–500 tok. Full history replayed on every turn.
Max tool iterations per turn: 5 (`MAX_TOOL_ITERATIONS`).

| Model | $/turn |
|-------|--------|
| `claude-haiku-4-5-20251001` | **$0.0028** |
| `claude-sonnet-4-6` | **$0.0084** |
| `claude-opus-4-6` | **$0.014** |
| `gpt-4.1-nano` | **$0.00024** |
| `gpt-4.1` | **$0.0048** |
| `gemini-3.5-flash` | **$0.0048** |
| `gemini-3.1-pro` | **$0.0064** |

Long turn (2 000 in + 1 000 out): ~2.5× single-turn.
5-iteration tool exchange (~8 000 tok total): ~6–7× single-turn.

---

## Credit Exchange Rate

### Anchor

**1 credit = $0.003** — the cost of 1 000 raw tokens on `claude-haiku-4-5-20251001`
(input rate $1.00/M × 1 000 tokens = $0.001; output rate $5.00/M × 1 000 = $0.005;
average: $0.003).

### Feature multiplier analysis

| Feature | Proposed credits | Credits × $0.003 | Typical actual cost | Result |
|---------|-----------------|-----------------|---------------------|--------|
| Generator | 10 | $0.030 | $0.0055 (Haiku) | 5.5× over-charged — safe margin |
| SEO | 1 | $0.003 | $0.00225 (Haiku) | 1.3× over-charged — fine |
| Images (Gemini default, 3 imgs) | 10 | $0.030 | **$0.117** | 4× **under-charged** ⚠ |
| Images (OpenAI high, 3 imgs) | 10 | $0.030 | **$0.399** | 13× **under-charged** ⚠ |
| Chat | 1 / 1 000 tok | $0.003 | $0.003 (Haiku) | 1.0× — exact anchor |

### Corrected multipliers

| Feature | Original | Corrected | Rationale |
|---------|----------|-----------|-----------|
| Generator | 10 | **10** | Wide margin; perceived value justifies it |
| SEO | 1 | **1** | Break-even at the margin; acceptable |
| Images (per call) | 10 | **45** | 45 × $0.003 = $0.135; covers Gemini default ($0.117) with ~15% margin |
| Chat | 1 / 1 000 tok | **1 / 1 000 tok** | Anchors the exchange rate |

**Alternative for images:** charge 15 credits per image (`count × 15` credits).
This scales naturally with the `count` parameter (1–3 images) and gives $0.045 per image
vs $0.039 actual on Gemini (+15% margin). For OpenAI high quality ($0.133/image),
15 credits ($0.045) is 3× under-charged — see the warning below.

**Warning — OpenAI high quality on pro_managed:**
At 45 credits per 3-image call, the exchange amount ($0.135) covers Gemini but not
OpenAI high quality ($0.399). Options: (a) charge a higher tier of credits for the
high-quality path (>133 credits per 3-image call to break even), (b) restrict OpenAI
high-quality images to pro_byok only, or (c) accept a loss and recoup via the
pro_managed subscription margin.

---

## Chat Credit Formula

```
credits_charged = ceil(weighted_tokens / 1 000)
weighted_tokens = (input_tokens + output_tokens) × model_weight
```

The proxy already computes `rawTokens * weight` at `plume-proxy/src/index.ts:799–802`.
The same value can drive credit debits. The debit fires once per proxy call, not once
per user turn — a 5-iteration tool loop generates 5 debit events totalling
`ceil(all_raw_tokens_across_iterations * weight / 1000)` credits.

### Worked examples

**Example A — Short turn (Haiku, no tools)**

```
Input:  450 tok  (system 300 + user 150)
Output: 280 tok
Weight: 1
Weighted: 730
Credits: ceil(730 / 1 000) = 1
```

**Example B — Long turn with post context (Sonnet 4.6)**

```
Input:  2 100 tok  (system 400 + injected post 1 400 + message 300)
Output:   850 tok
Weight: 3
Weighted: (2 100 + 850) × 3 = 8 850
Credits: ceil(8 850 / 1 000) = 9
```

**Example C — Multi-turn tool exchange (Haiku, 3 iterations)**

```
Iter 1: 800 in + 300 out = 1 100 raw
Iter 2: 1 400 in + 200 out = 1 600 raw  (history replay + tool result)
Iter 3: 2 000 in + 400 out = 2 400 raw
Total raw: 5 100, weight: 1
Credits: ceil(5 100 / 1 000) = 6 (for the full exchange)
```

---

## Worst-Case Cost Table

Credit allowances below are **proposed values** — the current weighted-token system
uses `MONTHLY_LIMITS` (`plume-proxy/src/index.ts:820`: free=50 000, trial=300 000,
pro_managed=2 000 000) and these have not yet been converted to credits.

| Tier | Proposed allowance | Worst-case pattern | Model | Calls | Plume API cost |
|------|-------------------|-------------------|-------|-------|----------------|
| free | 100 cr | Chat only (1 cr/1k tok) | Haiku | 100k tok | **$0.30** |
| trial | 500 cr | Images × 45 cr/call | Gemini Flash Image | 11 calls | **$1.29** |
| trial | 500 cr | Images × 45 cr/call | GPT Image 1.5 high | 11 calls | **$4.39** |
| trial | 500 cr | Generator × 10 cr/call | Haiku | 50 calls | **$0.28** |
| pro_managed | 2 000 cr | Images × 45 cr/call | GPT Image 1.5 high | 44 calls | **$17.56** |
| pro_managed | 2 000 cr | Chat, long turns ~9 cr | Sonnet 4.6 | 222 turns | **$1.87** |

**Key finding:** The image worst case on pro_managed ($17.56/month per user) exceeds the
pro_managed subscription revenue at current pricing. OpenAI high-quality images must be
re-priced, quality-gated, or provider-restricted before the credits system goes live.

Note: Generator, SEO, and Images are unavailable on the free tier
(`includes/Tiers/TierConfig.php:50`), so free-tier worst case is chat-only.

---

## Agent Prompts

### Agent Prompt A — WordPress per-call debug logging

Use this with your local AI agent (Docker environment at `localhost:8080`).

```
We need to add temporary per-call debug logging to the Plume plugin to measure actual
token counts for each feature. The plugin is at the current working directory.

In `includes/Providers/AbstractProvider.php`, find the `maybe_log()` method (around
line 173). Add a WP_DEBUG-gated error_log() call BEFORE the existing
UsageTracker::log_usage() line:

    protected function maybe_log( CompletionRequest $request, CompletionResponse $response ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Plume usage] feature=%s model=%s prompt_tokens=%d completion_tokens=%d cost_usd=%.6f',
                $request->metadata['feature'] ?? 'unknown',
                $response->model ?: $request->model,
                $response->prompt_tokens,
                $response->completion_tokens,
                $response->cost_usd,
            ) );
        }
        UsageTracker::log_usage( $response->total_tokens );
    }

Also enable debug logging on the local Docker instance:
1. Add WP_DEBUG=true and WP_DEBUG_LOG=true to wp-config.php inside the container,
   or via the docker-compose.yml environment block.
2. The log appears at wp-content/debug.log inside the container.

Restart the WordPress container after the PHP edit (OPcache serves stale bytecode):
    docker restart blognjohanssoneu-wordpress-1

Exercise each feature in the WordPress admin (~5 times each):
- Chat: short message, medium message, message that triggers a tool call
- Generator: short post, long post
- SEO: run on a real post with substantial content
- Images: generate 1 image, then 3 images

Parse the results:
    docker exec blognjohanssoneu-wordpress-1 grep '\[Plume usage\]' /var/www/html/wp-content/debug.log

Do NOT commit either change — this is a temporary measurement step. Revert the
error_log() addition and WP_DEBUG settings after collecting the data.
```

### Agent Prompt B — Cloudflare Analytics Engine per-call logging

Use this with your local AI agent. Requires Cloudflare Workers **Paid plan**.

```
We need to add Cloudflare Analytics Engine logging to the Plume proxy for permanent
per-call usage data. The proxy is in plume-proxy/src/.

## Step 1 — Add `feature` to the ProxyRequest type (plume-proxy/src/types.ts)

In the ProxyRequest interface, add one optional field:

    feature?: string;

## Step 2 — Forward `feature` from WordPress (includes/Proxy/ProxyClient.php)

In the $payload construction (around line 59), add:

    if ( isset( $options['feature'] ) ) {
        $payload['feature'] = sanitize_key( $options['feature'] );
    }

In each provider that calls ProxyClient::chat(), pass:
    'feature' => $request->metadata['feature'] ?? 'unknown'

Files to update: ClaudeProvider.php, OpenAIProvider.php, GeminiProvider.php
(search for `ProxyClient::chat(` in each).

## Step 3 — Bind Analytics Engine (plume-proxy/wrangler.toml)

Add:

    [[analytics_engine_datasets]]
    binding = "ANALYTICS"
    dataset = "plume_usage"

## Step 4 — Add ANALYTICS to the Env interface (plume-proxy/src/types.ts)

    ANALYTICS: AnalyticsEngineDataset;

## Step 5 — Write data points (plume-proxy/src/index.ts)

After the `await updateUsage(...)` call at line 802, add:

    env.ANALYTICS.writeDataPoint( {
        blobs: [
            provider,
            selectedModel,
            tier,
            ( body as ProxyRequest ).feature ?? 'unknown',
        ],
        doubles: [
            normalized.usage.input_tokens,
            normalized.usage.output_tokens,
            rawTokens * weight,
        ],
        indexes: [ selectedModel ],
    } );

Blobs: [provider, model, tier, feature]
Doubles: [input_tokens, output_tokens, weighted_tokens]

## Step 6 — Deploy

    cd plume-proxy && npm run build && npx wrangler deploy

## Step 7 — Query

Workers & Pages → your worker → Analytics → dataset: plume_usage.
Analytics Engine data has up to 5 minutes of ingestion delay.

Follow repo conventions: Conventional Commit messages, TypeScript strict mode.
Create a draft PR targeting main with title:
    feat(proxy): add Analytics Engine per-call usage logging
```
