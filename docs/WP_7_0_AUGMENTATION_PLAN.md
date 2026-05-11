# WordPress 7.0 — wp-ai-mind Augmentation Plan
## Phased Implementation Roadmap

> **Purpose.** Reference document for the WP 7.0 (GA 2026-05-20) augmentation work. Each phase is independently shippable; only Phase 3 depends on WP 7.0 actually being released. Companion to `WP_AI_MIND_ARCHITECTURE.md`.
>
> **Status.** Plan approved 2026-05-10 after dialogue. Ready for staged implementation through `wordpress-planner` → `wordpress-standards-validator` → human approval → `wordpress-coder` → `wordpress-reviewer` pipeline.

---

## Strategic context

WordPress 7.0 ships three native AI subsystems:

- **AI Client SDK** — `wp_ai_client_prompt()` for any plugin to make AI calls
- **Connectors API** — centralised provider/key management in core Settings
- **Abilities API** — machine-readable plugin capabilities, discoverable by core, command palette, MCP, WP-CLI, and external agents

### What changes for our value prop

| Tier | Pre-7.0 value prop | Post-7.0 reality |
|---|---|---|
| Free (50k tokens/mo) | Onramp: try AI without a key | Eroded — users can paste their own key into core's Connectors |
| Trial (300k tokens/mo) | Generous proof period | Same erosion |
| Pro BYOK (one-time fee) | Pay once for the privilege of bringing your own key | **Erased.** Core's Connectors API does this for free |
| Pro Managed (monthly) | Managed quota, single bill, no key headaches | Holds — core does not offer managed-quota as a service |

### Pricing pivot (agreed direction)

Tier structure stays. **Pro BYOK becomes a monthly subscription** (e.g. €9.90/mo) priced *below* Pro Managed (€13.90/mo). The two paid tiers now differ on *who pays for inference*, not on *what the customer gets*:

- **Pro BYOK (€9.90/mo)** — All features. User configures keys (via core Connectors on WP 7.0+, or our encrypted DB on WP <7.0). User pays Anthropic/OpenAI/Google directly. Lower price reflects we don't bear inference cost.
- **Pro Managed (€13.90/mo)** — All features. We manage keys, quota, and pay Anthropic. Higher price absorbs inference cost + convenience premium.

### Implication: what this plan rejects

- **No site-wide AI licensing chokepoint.** `wp_ai_client_prevent_prompt` is NOT wired up to gate other plugins' AI calls. That posture would cause uninstalls.
- **No provider registration with core.** wp-ai-mind providers are NOT exposed as core-visible providers. Doing so would (a) leak our Cloudflare Worker quota to other plugins, and (b) duplicate WP 7.0's first-party Anthropic/Google/OpenAI connectors.
- **No grandfathering decision in this plan.** Existing one-time-fee BYOK customer transition is flagged separately and must be decided before any pricing-page change ships.

---

## Overview table

| # | Item | Phase | Priority | Effort | Risk | WP 7.0 dep | Depends on |
|---|---|---|---|---|---|---|---|
| 1.1 | Central `Gatekeeper::can_request_ai()` predicate | 1 | High | S–M | Low | No | — |
| 1.4 | Recognise `ANTHROPIC_API_KEY` alongside `CLAUDE_API_KEY` | 1 | High | S | Low | No | — |
| 1.3 | Tier-state caching with explicit invalidation | 1 | Medium | S | Low | No | — |
| — | **GA validation spike** (validate exact 7.0 API names) | 2 | Gate | XS | Low | Yes | 1.x complete |
| 4.1 | Map all 10 tools to Abilities (read + plan + write) | 3 | High | M | Medium | Yes | 1.1, GA |
| 4.2 | `wp_ai_mind_register_tools` extensibility hook | 3 | Medium | XS | Low | No (paired) | 4.1 |
| 2.1 | Connectors API as third BYOK key source | 3 | High | S–M | Low | Yes | 1.4, GA |
| 2.2 | Single clear UX path for BYOK key entry | 3 | High | S | Low | Yes | 2.1 |
| 3.3 | Emit `GenerativeAiResult` from abstract provider | 4 | Medium | XS | Low | Yes | GA |
| 5.2 | Voice/tone as WP-7.0 system instruction *(if API exists)* | 4 | Medium | S–M | Medium | Yes | GA |
| — | Issue [#497](https://github.com/niklas-joh/wp-ai-mind/issues/497) — Tier-sync webhook | Out of plan | High | M+M | Medium | No | 1.3 (optional) |

**Dropped after dialogue (audit trail):**

| # | Item | Why dropped |
|---|---|---|
| 1.2 | Wire `wp_ai_client_prevent_prompt` site-wide | Hostile to other plugins; uninstall risk |
| 3.1 | Register wp-ai-mind providers with `wp_ai_client` | Cost leakage (our Worker quota exposed) + duplication of core connectors |
| 3.2 | Register models with WP 7.0 model registry | Moot without 3.1 |
| 5.1 | Frontend widget JS AI Client fallback | Two code paths, low priority — defer indefinitely |
| 4.3 | Tier features as queryable Abilities | `own_api_key` not coherent under monthly pricing — defer to phase 2 if customer ask materialises |

---

## Phase 1 — Foundations (no WP 7.0 dependency)

**Goal.** Refactor and forward-compat work that improves the codebase regardless of WP 7.0 outcomes. Ship immediately.

**Why first.** All three items are pure-PHP refactors with measurable internal value (gate consolidation, performance). They unblock Phase 3 (1.1 → 4.1's permission_callback; 1.3 → cached tier reads under Abilities load) without committing to any WP 7.0 API shape.

### 1.1 Central `Gatekeeper::can_request_ai()` predicate

Single function consolidating the four scattered gates (tier feature check, monthly quota, site-registration-with-proxy, WP capability) into one canonical predicate. Returns `true|WP_Error` with a typed reason code.

- **New file:** `includes/Core/Gatekeeper.php`
- **Touch points:** `includes/Proxy/NJ_Proxy_Client.php:49`, `includes/Modules/Generator/GeneratorModule.php:91`, `includes/Modules/Seo/SeoModule.php:107` and `:125` (second `permission_callback` for the SEO apply route), `includes/Modules/Images/ImagesModule.php:104`, `includes/Tools/ToolExecutor.php:394` (tier check) and `:403` (quota check)
- **Required by:** 4.1 (Abilities `permission_callback`s)

### 1.4 Recognise `ANTHROPIC_API_KEY` alongside `CLAUDE_API_KEY`

`ProviderSettings::ENV_VARS` (`includes/Settings/ProviderSettings.php:27-31`) maps `claude` → `CLAUDE_API_KEY`. Recognise both, with `ANTHROPIC_API_KEY` winning if both are present (it's the standard name and what WP 7.0's Connectors API expects).

- **Files:** `includes/Settings/ProviderSettings.php:27-31, 61-67`
- **Required by:** 2.1

### 1.3 Tier-state caching with explicit invalidation

`NJ_Tier_Manager::get_user_tier()` reads `user_meta` on every call. Cache to `wp_cache_get/set` with a 1-hour TTL, invalidate on `set_user_tier()` and on a new `wp_ai_mind_tier_changed` action.

- **Files:** `includes/Tiers/NJ_Tier_Manager.php:37-41` (get), `:53-59` (set)
- **Required by:** 4.1 (permission_callback hot path), useful for Issue [#497](https://github.com/niklas-joh/wp-ai-mind/issues/497)

### Phase 1 acceptance

- All six legacy gate sites call `Gatekeeper::can_request_ai()` — no behavioural change
- `ANTHROPIC_API_KEY` env var works for `claude` provider
- `ollama` provider is intentionally absent from `ENV_VARS` (no standard env var; local address configured separately) — no env lookup attempted for `ollama`
- Multi-call benchmark on `NJ_Tier_Manager::get_user_tier()` shows single `get_user_meta` per cache window
- Force-update tier via `set_user_tier()` → next read hits DB (cache invalidated correctly)
- Existing PHPUnit suite passes; new unit tests cover Gatekeeper × four tiers × six gate sites

---

## Phase 2 — WP 7.0 GA validation spike

**Goal.** One-shot validation gate before Phase 3 starts. Confirms exact WP 7.0 API names against this plan; updates the plan if anything diverges.

**Trigger.** Phase 1 complete AND WP 7.0 GA released (2026-05-20).

**Effort.** ~½ day. No code changes to the plugin.

### Validation checklist

| API surface | Plan assumes | Validate against |
|---|---|---|
| Connectors read | `wp_get_connector( 'anthropic' )` returning `[ 'api_key' => ... ]` | WP 7.0 source / Connectors API dev-note final version |
| Abilities category | `wp_register_ability_category( 'wp-ai-mind', [...] )` on `wp_abilities_api_categories_init` | Abilities API dev-note + `wp_register_ability` source |
| Abilities | `wp_register_ability( 'slug', [...] )` with `execute_callback`, `permission_callback`, `meta.annotations.{readonly,destructive,idempotent}` | same |
| Generative result | `do_action( 'wp_ai_record_result', new \WP_AI_Generative_Result( [...] ) )` or equivalent | AI Client dev-note final version |
| System instruction registry | `wp_ai_register_instruction( 'slug', $text )` *or builder-only* | AI Client dev-note final version |
| Connectors UI URL | `options-general.php?page=ai-connectors` (slug guess) | actual menu registration |

### Output

A short validation note appended to this document (or as a new `WP_7_0_API_VALIDATION_NOTES.md`) listing any divergences. If any diverge, the relevant Phase 3 item plan is updated *before* coding starts.

### Decision points

- **If `wp_ai_register_instruction()` does not exist:** drop item 5.2.
- **If Abilities annotations don't include `destructive`:** investigate whether MCP clients have an alternative confirmation signal; possibly delay write-tool exposure in 4.1 until a clearer signal exists.
- **If Connectors API isn't read-accessible from PHP without explicit user opt-in:** delay 2.1 / 2.2 until a stable read path is documented.

---

## Phase 3 — WP 7.0 integrations (post-validation)

**Goal.** The headline value-add work: Abilities discoverability + cleaner key-entry UX. Each item ships independently.

> **Note on item numbering.** Item numbers (4.1, 4.2, 2.1, 2.2) match the overview table and original backlog IDs — they are not sequential within this phase. `4.x` items precede `2.x` here because Abilities (4.x) must be ready before the UX redirect (2.2) can meaningfully reference them.

### 4.1 Map all 10 tools to Abilities (read + plan + write)

**THE headline integration.** Every entry in `ToolRegistry::register_tools()` becomes a `wp_register_ability()` registration with an `execute_callback` that delegates to `ToolExecutor::execute()`. The chat loop continues to use the existing tool path; Abilities is a parallel surface.

- **All 10 tools** registered, including write tools (`create_post`, `update_post`, `plan_post`, `plan_update`, `generate_seo_meta`) the chat loop currently hides. Note: `plan_post` and `plan_update` currently have no `ToolExecutor` handler — see sub-task below.
- Strong `permission_callback` (calls `Gatekeeper::can_request_ai()` plus `current_user_can( $tool->capability )`)
- Explicit `annotations.destructive=true` for write tools so consenting MCP clients can require confirmation
- Abilities run as the *current user* — no privilege escalation

**Files:**
- New `includes/Abilities/AbilityRegistrar.php`
- New `includes/Abilities/ToolToAbility.php` adapter
- Sub-task: add `public function all(): array { return $this->tools; }` to `ToolRegistry` (currently private at `:26`)
- Sub-task: add `plan_post` and `plan_update` handler methods to `ToolExecutor` and register them in the dispatch table. These two tools appear in `ToolRegistry` but have no corresponding private methods or dispatch entries in `ToolExecutor` — calling them today returns `['error' => 'Unknown tool: plan_post']`. They must be implemented before Phase 4.1 ships, or explicitly excluded from the Abilities surface with a documented rationale.

**Why headline.** Core command palette, MCP clients (Claude Desktop, Claude Code), WP-CLI, and any agent-framework plugin gain access to wp-ai-mind tools without bespoke integration. This positions wp-ai-mind as the agent-action layer for the site **without** forcing other plugins through our tier system.

### 4.2 `wp_ai_mind_register_tools` extensibility hook

`ToolRegistry::register_tools()` is private and hardcoded. Add an action hook fired after built-in registration so third-party plugins can append `ToolDefinition` instances. The Abilities exposure (4.1) then picks them up automatically.

- **Data flow clarification.** This hook lets *other plugins add their own tools* to wp-ai-mind's catalogue. It does NOT let other plugins consume our Anthropic quota or licence. Tools execute as plain PHP under the *current user's* WP capabilities.
- **Trust model.** Tools registered via this hook are code, not user input. Standard WordPress trust model applies — only trusted plugins should register tools. Document this clearly.

**Files:** `includes/Tools/ToolRegistry.php:33-35, 89+`

### 2.1 Connectors API as third BYOK key source

Insert WP 7.0's Connectors API between env-var and encrypted DB in the lookup chain. Resolution order becomes: **env var → Connectors API → encrypted DB**. Existing keys keep working unchanged.

- **Files:** `includes/Settings/ProviderSettings.php:60-73`. Optional new `includes/Settings/ConnectorBridge.php`
- **Function-exists guard** on `wp_get_connector` → graceful no-op on WP <7.0

### 2.2 Single clear UX path for BYOK key entry

On WP 7.0+, the plugin's API-key admin page (`includes/Admin/NJ_Api_Key_Settings.php:178+`) hides its key-entry fields and shows a "Configure your API keys at Settings → AI Connectors" link. Encrypted DB storage remains intact for users on WP <7.0 *and* for users who explicitly opt out via `define( 'WP_AI_MIND_FORCE_LOCAL_KEY_STORAGE', true )`.

**The trade-off, made explicit:**

| Path | UX | At-rest storage |
|---|---|---|
| Default on WP 7.0+ (no constant set) | Single core screen | WP 7.0 default (currently unencrypted, core trac #64789) |
| Opt-out: `WP_AI_MIND_FORCE_LOCAL_KEY_STORAGE` | Plugin's own page | AES-256-CBC keyed off `AUTH_KEY+SECURE_AUTH_KEY` |
| WP <7.0 | Plugin's own page (only option) | AES-256-CBC keyed off `AUTH_KEY+SECURE_AUTH_KEY` |

**Files:** `includes/Admin/NJ_Api_Key_Settings.php:178+`, plus a small constant-check helper.

### Phase 3 acceptance

- `wp ability list | grep wp-ai-mind` shows all 10 tools after installing `wp-cli/ability-command:dev-main`
- `wp ability run wp-ai-mind/get_recent_posts` as admin → returns posts; as subscriber → fails
- `wp ability run wp-ai-mind/create_post` as editor → creates post; as subscriber → fails
- Claude Desktop / Claude Code MCP can list and call wp-ai-mind abilities
- Side plugin registers a tool via `wp_ai_mind_register_tools` action → appears as `wp-ai-mind/{tool_name}` Ability
- On WP 7.0 staging with Anthropic key in core's Connectors → plugin chat works without a DB key entry
- Plugin's API-key admin page shows redirect link on WP 7.0; defining `WP_AI_MIND_FORCE_LOCAL_KEY_STORAGE` shows form; on WP 6.x always shows form

---

## Phase 4 — Polish

**Goal.** Optional polite-citizen items in the WP 7.0 ecosystem. Marginal cost, marginal value. Ship after Phase 3 stabilises.

### 3.3 Emit `GenerativeAiResult` from the abstract provider

When `AbstractProvider::maybe_log()` (`includes/Providers/AbstractProvider.php:157`) records a successful completion, also emit a WP-7.0 `GenerativeAiResult` so core can aggregate cross-plugin token usage. Doesn't replace `NJ_Usage_Tracker` (which remains authoritative for tier enforcement).

- **Files:** `includes/Providers/AbstractProvider.php:157-159`
- **Class-exists guard** on `\WP_AI_Generative_Result` → graceful no-op on WP <7.0

### 5.2 Voice/tone as WP-7.0 system instruction *(conditional)*

**Only ship if Phase 2 validation confirms a system-instruction registry exists.**

`VoiceInjector::build_system_prompt()` is currently called explicitly from chat/generator/SEO. If WP 7.0 ships a registry, register voice rules there so any AI call that opts in (including third-party plugins using `using_system_instruction()`) can inherit the site's tone.

- **Opt-in by callers, not enforced site-wide.** The plugin's own modules continue to inject voice explicitly. This is purely additive: "site voice is *available* as a registered instruction other plugins *may* use".
- **Files:** `includes/Voice/VoiceInjector.php`, hook from `Plugin::init_hooks()`
- **If no registry API exists:** drop this item.

### Phase 4 acceptance

- `GenerativeAiResult` records appear in any core admin AI dashboard (if shipped) with correct token counts and model
- *(If 5.2 ships)* third-party plugin calling `wp_ai_client_prompt('write a sentence')->using_system_instruction('wp-ai-mind/site-voice')->generate_text()` produces output reflecting the configured site voice

---

## Out of plan — Issue [#497](https://github.com/niklas-joh/wp-ai-mind/issues/497)

**Tier-sync webhook gap.** Today the Cloudflare Worker stores LemonSqueezy tier upgrades in KV but never pushes them back to WP user meta. Pro-tier features that gate on `NJ_Tier_Manager::user_can()` (SEO, Images, Generator, model selection in admin UI) stay locked even after a paid upgrade.

The Worker has no read or write access to MySQL — only its own Cloudflare KV namespace and the HTTP `fetch` API. The fix is therefore: Worker, after writing KV, makes an outbound HTTPS POST to `{site_url}/wp-json/wp-ai-mind/v1/tier-update` with HMAC signature; WordPress receives the webhook and writes its own DB itself.

**This work is NOT part of the WP 7.0 plan.** Filed as a standalone issue for a separate implementation session. It can be picked up before, during, or after any phase here. It optionally benefits from Phase 1 item 1.3 (tier-cache invalidation on `wp_ai_mind_tier_changed`).

---

## Cross-cutting concerns

### Backward compatibility

- All WP 7.0-dependent items use `function_exists()` / `class_exists()` guards
- Plan is "compat now, bump `Requires at least: 7.0` in 6–12 months and remove legacy paths"
- No item in this plan breaks WP 6.x behaviour

### Pre-GA risk

WP 7.0 is RC3 at time of writing. Two of three core dev-notes are 2026-03-18 and 2026-03-24. Real-time collaboration was *pulled* from 7.0 on 2026-05-08. Non-trivial chance one or more AI APIs change shape before GA. Mitigation: each augmentation is `function_exists`/`class_exists`-guarded and independently shippable; Phase 2 explicitly validates assumed names against GA before Phase 3 starts.

### Pricing transition (out of scope)

The decision to switch Pro BYOK from one-time fee → monthly subscription has implications for existing one-time-fee customers. **Suggested approach** (for a separate decision before any pricing-page change ships): grandfather all current one-time-fee BYOK licences as "lifetime BYOK access"; new BYOK signups go on the €9.90/mo plan. This is **not** part of this plan's implementation scope.

---

## Open questions

| # | Item | Question | Resolution |
|---|---|---|---|
| Q1 | 2.1 | Exact Connectors API function name (`wp_get_connector` vs alternative) | Phase 2 spike |
| Q2 | 2.2 | Exact admin page slug for core's Connectors screen | Phase 2 spike |
| Q3 | 3.3 | Exact result-class name and action signature for `GenerativeAiResult` emission | Phase 2 spike |
| Q4 | 4.1 | MCP clients calling `wp-ai-mind/create_post` directly — do we want a per-ability "approval required" UX layer beyond `permission_callback` + `destructive: true`? | Phase 2 spike |
| Q5 | 5.2 | Does WP 7.0 ship a `wp_ai_register_instruction()` registry, or is `using_system_instruction()` on the builder the only entry point? | Phase 2 spike — drop item if registry doesn't exist |
| Q6 | Strategic | Transition for existing one-time-fee BYOK customers when switching to monthly subscription | **Not part of this plan.** Decide before any pricing-page change. Suggested: grandfather existing licences. |

---

## File modification index

| File | Items | Phase |
|---|---|---|
| `includes/Core/Gatekeeper.php` *(new)* | 1.1 | 1 |
| `includes/Core/Plugin.php:97` (init hooks) | 4.1, 4.2, 5.2 | 3, 4 |
| `includes/Tiers/NJ_Tier_Manager.php:37, :53` | 1.3 | 1 |
| `includes/Settings/ProviderSettings.php:27, :60` | 1.4, 2.1 | 1, 3 |
| `includes/Settings/ConnectorBridge.php` *(new, optional)* | 2.1 | 3 |
| `includes/Admin/NJ_Api_Key_Settings.php:178` | 2.2 | 3 |
| `includes/Providers/AbstractProvider.php:157` | 3.3 | 4 |
| `includes/Tools/ToolRegistry.php:26, :33` | 4.1, 4.2 | 3 |
| `includes/Tools/ToolExecutor.php` (add `plan_post`/`plan_update` handlers; delegated from Ability callback) | 4.1 | 3 |
| `includes/Abilities/AbilityRegistrar.php` *(new)* | 4.1 | 3 |
| `includes/Abilities/ToolToAbility.php` *(new)* | 4.1 | 3 |
| `includes/Voice/VoiceInjector.php` | 5.2 | 4 |

(Out-of-plan tier-sync items in [#497](https://github.com/niklas-joh/wp-ai-mind/issues/497) touch `includes/Payments/`, `includes/Settings/NJ_Site_Registration.php`, `wp-ai-mind-proxy/src/webhook.ts`, `wp-ai-mind-proxy/src/registration.ts`.)

---

## Per-item verification

- **1.1** — Unit-test `Gatekeeper::can_request_ai()` for all four predicates × four tiers. Confirm callers swap to one call without behaviour change.
- **1.4** — Set `ANTHROPIC_API_KEY` env, no DB key → `get_api_key('claude')` returns env value. Set both `ANTHROPIC_API_KEY` and `CLAUDE_API_KEY` → `ANTHROPIC_API_KEY` wins.
- **1.3** — Multi-call benchmark on `NJ_Tier_Manager::get_user_tier()` shows single `get_user_meta` per cache window. Force-update tier and assert next read hits DB.
- **2.1** — On WP 7.0 staging, configure Anthropic key in core's Connectors → `get_api_key('claude')` returns it. Remove env var, remove DB key, leave Connectors → still works.
- **2.2** — On WP 7.0 staging, visit plugin's API-key page → see redirect link. Define `WP_AI_MIND_FORCE_LOCAL_KEY_STORAGE` → see plugin's own form. On WP 6.x → always see plugin's own form.
- **3.3** — On WP 7.0 staging, run a chat completion → inspect any core admin AI dashboard (if shipped) → confirm wp-ai-mind completion appears with correct token counts.
- **4.1 / 4.2** — Run `wp ability list | grep wp-ai-mind` after installing `wp-cli/ability-command:dev-main`. Run `wp ability run wp-ai-mind/get_recent_posts` as admin → returns posts. Run `wp ability run wp-ai-mind/create_post` as editor → succeeds; as subscriber → fails. From Claude Desktop or Claude Code MCP, list tools and call one. From a side plugin, register a test tool via `wp_ai_mind_register_tools` action and confirm it appears as `wp-ai-mind/{tool_name}` Ability.
- **5.2** *(if shipped)* — On WP 7.0, call `wp_ai_client_prompt('write a sentence')->using_system_instruction('wp-ai-mind/site-voice')->generate_text()` from another plugin → response reflects configured site voice.

### End-to-end smoke test for the augmented stack

On a WP 7.0 staging site with wp-ai-mind installed and an Anthropic key configured via core's Connectors API:

1. The plugin chat continues to work (key resolved via Connectors API path 2.1)
2. `wp ability list` shows `wp-ai-mind/get_recent_posts`, `wp-ai-mind/create_post`, etc.
3. Claude Code (MCP client) can list and call wp-ai-mind abilities under the current user's capabilities
4. Third-party plugins making their own `wp_ai_client_prompt()` calls work *independently* through core's first-party connectors, untouched by wp-ai-mind

---

## References

- [`docs/WP_AI_MIND_ARCHITECTURE.md`](./WP_AI_MIND_ARCHITECTURE.md) — current architecture spec
- [Issue #497](https://github.com/niklas-joh/wp-ai-mind/issues/497) — Tier-sync webhook gap (out of plan)
- WP 7.0 dev-notes:
  - AI Client SDK (2026-03-18)
  - Connectors API (2026-03-24)
  - Abilities API (TBD before GA)
- Plan dialogue session: 2026-05-10
