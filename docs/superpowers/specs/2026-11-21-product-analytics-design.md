# Product analytics: design

Date: 2026-11-21
Status: draft, awaiting approval

## Why

Plume gets a few downloads a day but almost no proxy requests, and today the only
data available is the proxy's own chat and registration records. Nobody can tell
whether new installs stall, break, or simply use their own API key. The owner wants
to answer four questions:

1. **Where do people drop off?** Download, activate, setup, first AI action, repeat use, paid plan.
2. **How can they be brought back?** Which drop-off points are worth fixing first.
3. **Do own-key (BYOK) requests fail, and why?** So the failures can be fixed.
4. **Which features are used most and give the best return?** Usage, cost and revenue per feature.

## Goals

- See the full journey for every opted-in install, on any plan: managed, own-key or Ollama.
- Record the outcome (success, error code, latency, model) of every AI request, including
  own-key requests that never touch the proxy.
- Join website visits (wpaimind.com), Search Console, plugin events, proxy usage and
  (later) Lemon Squeezy revenue in one place: BigQuery project `gen-lang-client-0450123411`.
- Keep permanent history (Analytics Engine keeps only about three months).

## Non-goals

- Changing the wpaimind.com website itself (it already has Google Analytics `G-7HRNFQN6X9`).
- Building win-back features now. They come after the data shows where people drop off.
- Customer acquisition cost. That needs ad spend data, and there isn't any yet.

## Anti-goals (would make this a failure even if it works)

- Sending anything before the site owner has said yes. WordPress.org Guideline 7 forbids it
  and it would put the plugin listing at risk.
- Sending prompts, generated content, API keys, post titles, user names, emails or site URLs
  in plugin events.
- Slowing down admin pages or AI requests. Telemetry must never block or break a request.
- Third-party scripts (Google Analytics or similar) inside users' WordPress admin.

## Constraints

- WordPress.org: explicit opt-in, clearly worded, easy to turn off; documented in
  `readme.txt` "External services". The current readme line "No tracking or cookies in
  the plugin itself" must be rewritten.
- GDPR: anonymous random ID only; EU storage. Existing datasets in the project, both `EU` and
  both already receiving data: `analytics_534483561` (GA4 daily export for wpaimind.com,
  first day 2026-11-19, so no earlier history) and `searchconsole` (bulk export, first load pending).
- Analytics Engine: one index, up to 20 blobs and 20 doubles per datapoint; the
  `plume_metrics` schema is append-only (`plume-proxy/src/metrics.ts`).
- Project rules: `Plume\` namespace, text domain `plume`, `@since NEXT_VERSION`, British English,
  the full planner, validator, approval, coder and reviewer pipeline in `CLAUDE.md`.

## Approaches considered

1. **Plugin sends opt-in events to the Plume Worker, then a daily copy goes to BigQuery** (chosen).
   One joined-up store, no Google code in users' admin, reuses the Worker's existing privacy
   discipline. Most work to build.
2. **Plugin sends events straight to Google Analytics.** Quick, but GA hides small numbers,
   is poor for error debugging, cannot join with proxy data, and sends admin activity to Google.
3. **Product analytics service (PostHog or similar).** Good funnels out of the box, but another
   service and bill, and data split away from the Google stack.

The owner picked option 1, after confirming it covers own-key users: events come from the
plugin itself, not from requests passing through the proxy.

## Design

### 1. Plugin (WordPress side)

**Consent**
- New option `plume_telemetry_consent`: `unset` (default), `yes`, `no`.
- Asked on the first onboarding screen with one plain sentence and a link to what is sent.
  Also a toggle in Settings. Nothing is sent while `unset` or `no`.
- Pre-consent "install ping" is **dropped**: it would breach Guideline 7. WordPress.org
  active-install counts serve as the denominator for "what share of installs we can see".

**Install ID**
- Option `plume_install_id`: random UUID v4, created when consent becomes `yes`. Never derived
  from the site URL. Deleted on uninstall and when consent is withdrawn (a new one is made if
  consent is given again).

**Events** (all carry install ID, plugin version, timestamp)

| Event | When | Extra fields |
|---|---|---|
| `consent_given` | Consent set to yes | onboarding or settings |
| `onboarding_step` | Each onboarding step viewed or completed | step name, action |
| `onboarding_done` | Onboarding finished | route chosen (managed, own key, Ollama) |
| `ai_request` | After every `AbstractProvider::complete()` / `stream()` | feature, provider, model, route (proxy, byok, ollama), outcome, normalised error code, HTTP status, latency, input and output tokens |
| `upgrade_click` | Pricing or upgrade link clicked | where in the UI |
| `heartbeat` | Daily via WP-Cron | tier, configured providers (types only), WordPress, PHP and plugin versions, locale, days since activation, days since last AI request |
| `deactivated` | Plugin deactivated | none |

- `ai_request` is recorded in the `final` `complete()` and `stream()` methods in
  `includes/Providers/AbstractProvider.php`, plus `generate_image()` for the Images feature
  (no tokens; image count instead). *Builder-check call:* images are in scope because goal 4
  needs them.
- *Builder-check call:* one `ai_request` per provider call (so a chat turn with tool calls
  produces several), each carrying a `turn_id` so turns can be counted too. Retries inside
  `with_retry()` produce one event with the final outcome, an `attempts` count and the
  `first_error_code`, so own-key failures that later succeed are still visible. Error codes are normalised to a fixed list
  (for example `auth_invalid`, `rate_limited`, `quota_exceeded`, `model_not_found`,
  `timeout`, `network`, `server_error`, `bad_request`, `other`). Raw provider messages are never sent.
- The feature name must reach the provider layer; today it is known in each module. How it is
  passed (a field on `CompletionRequest` is the obvious route) is left to the builder.
- Sending: events are queued in memory during a request and sent once on `shutdown` as one
  batch with a non-blocking `wp_remote_post` (short timeout, `blocking => false`). Heartbeat
  sends from cron. Failures are ignored.
- Links from the plugin to wpaimind.com and the Lemon Squeezy store get UTM tags
  (`utm_source=plugin&utm_medium=<screen>`) so Google Analytics can attribute in-plugin clicks.
- Privacy: `wp_add_privacy_policy_content()` text, readme "External services" update, uninstall
  removes both options.

### 2. Worker (`plume-proxy`)

- New `POST /v1/events`: no site token needed (own-key sites never register). Accepts a batch
  of up to 50 events, max 16 KB. Every event name and field is checked against an allowlist;
  unknown fields are dropped, unknown events rejected. Install ID must be a valid UUID v4.
- Abuse protection: per-install and per-IP rate limit (Cloudflare rate limiting binding is the
  expected route; left to the builder).
- Written to a **new** Analytics Engine dataset `plume_events` (and `plume_events_dev` for the
  dev Worker), with install ID as the index and its own append-only schema locked by a test,
  following the `metrics.ts` pattern.
- Joining to proxy data: the plugin sends `install_id` with `/register` and `/v1/chat`
  (only when consent is `yes`). `plume_metrics` gains an append-only `blob10 install_id`.
- Lemon Squeezy (phase 3): the existing `/webhook` handler also writes an order or
  subscription datapoint (plan, amount, currency, event type, install ID where known).

### 3. Daily copy to BigQuery

- A Worker cron trigger (daily, early morning UTC) reads the previous day from both
  Analytics Engine datasets via the Analytics Engine SQL API and loads it into BigQuery
  dataset `plume_product` (EU) in project `gen-lang-client-0450123411`.
- Tables: `events`, `proxy_requests`, later `revenue`. Partitioned by day. Load is idempotent
  per day (re-running a day replaces it).
- First run backfills the last 90 days of `plume_metrics`.
- *Builder-check call:* only production datasets are copied. The dev Worker (shared by the
  companion blog's local install and staging4) gets no cron and no Google secrets, so test
  traffic never reaches the funnels.
- Secrets (set by the owner with `wrangler secret put`, never committed): a Cloudflare API token
  with Analytics Engine read, and a Google service account key with BigQuery Data Editor on
  `plume_product` and Job User on the project.
- Copy failures are logged to Workers observability and retried the next day for any missing day.

### 4. Reporting

- BigQuery views in `plume_product`: `funnel_daily`, `feature_usage`, `byok_failures`,
  `retention_cohorts` (weekly cohorts by activation), `feature_value` (usage, credits cost and,
  once revenue lands, revenue per feature), and `acquisition` (built from
  `analytics_534483561.events_*` and `searchconsole` tables for wpaimind.com).
- A Looker Studio report on those views. The owner creates it (it needs their Google login),
  following written steps; Claude can also query the views directly via the BigQuery connector.

## Phases

1. Plugin consent, install ID, events; Worker `/v1/events` and `plume_events`. (Answers BYOK
   failures and feature usage within days of release.)
2. Daily BigQuery copy, views, Looker Studio steps. (Funnel and retention.)
3. Lemon Squeezy revenue, then win-back ideas based on what phases 1 and 2 show.

Each phase is its own pull request.

## Left to the builder

- Exact onboarding wording (keep it to one sentence plus a link) and Settings placement.
- How the feature name reaches the provider layer.
- Rate-limiting mechanism and limits.
- Analytics Engine slot layout for `plume_events`, within the append-only rule.
- BigQuery table schemas and load method (load job versus streaming insert).
