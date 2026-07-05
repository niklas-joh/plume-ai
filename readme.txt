=== Plume AI - Write and Design ===
Contributors: niklasjohansson
Tags: ai, writing, content, images, chatbot
Requires at least: 6.4
Tested up to: 7.0
Stable tag: 1.11.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered writing, image generation, and content design tools for WordPress.

== Description ==

Plume AI - Write and Design integrates Claude (Anthropic), OpenAI, Google Gemini, and Ollama directly
into your WordPress dashboard, giving you AI assistance without leaving the editor.

**Features:**

* **Chat assistant** — Conversational AI with tool support (read posts, search content)
* **Content generator** — Generate full blog posts with customisable tone and length
* **AI SEO** — Generate meta titles, descriptions, excerpts, and image alt text
* **AI Images** — Generate and insert featured images directly from a text prompt
* **Usage tracker** — Monitor credit consumption across providers
* **Gutenberg integration** — Direct editor sidebar tools

**Free vs Pro:**

| | Free | Pro Managed | Pro BYOK |
|---|---|---|---|
| **Price** | Free | $79/year | Free (your own API key, billed by your provider) |
| **Credits/month** | 100 | 500 | No credit limit — billed directly by your provider |
| **Available models** | Claude Haiku 4.5, GPT-4.1 nano | + Claude Sonnet 4.6, Claude Opus 4.6, GPT-4.1, Gemini 3.1 Pro | Any model your configured provider supports |
| **Chat, Generator, SEO, Images** | All included | All included | All included |
| **Your own API key (BYOK)** | — | — | Required |

* **Free** — Chat, Generator, SEO, and Images all included from day one.
  100 credits/month using Claude Haiku 4.5 or GPT-4.1 nano.
* **Pro Managed** — Everything in Free, plus 500 credits/month and access
  to Claude Sonnet 4.6, Claude Opus 4.6, GPT-4.1, and Gemini 3.1 Pro.
  $79/year.
* **Pro BYOK** — Everything in Free, with no credit limit and access to
  any model your configured provider supports. Requires your own API key,
  sent directly to your chosen provider — never through Plume's managed
  service.

**Supported AI providers:** Anthropic Claude, OpenAI (GPT-4+), Google Gemini, Ollama (local/self-hosted)

**External services:** This plugin sends content you submit to the AI provider of your choice
(Anthropic, OpenAI, or Google). Your content leaves your server and is processed by the
chosen provider. Review each provider's privacy policy and terms of service before use:

* Anthropic: https://www.anthropic.com/legal/privacy
* OpenAI: https://openai.com/policies/privacy-policy
* Google Gemini: https://policies.google.com/privacy
* Ollama is self-hosted; no external transmission occurs when using Ollama.
* **Plume AI - Write and Design** (`https://plume-proxy.plumewp.workers.dev`): Free and managed-pro
  tiers route chat requests through this service. The service receives your
  site URL (for registration) and the chat messages you send. No messages are stored
  beyond the in-flight API call. See: https://wpaimind.com/privacy-policy

== Installation ==

1. Upload the `plume` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. (Optional — Pro BYOK only) Navigate to **Plume AI - Write and Design → Settings** and enter your own API key. Free and managed-plan users do not need an API key.
4. Start using the Chat, Generator, or Usage modules from the admin menu.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Claude (Anthropic), OpenAI (GPT-4 and above), Google Gemini, and Ollama. You can configure
one or more providers and switch between them in the settings.

= Does this plugin store my API keys securely? =

**Free / Pro Managed tiers:** No API key is required — chat requests are routed
through Plume AI - Write and Design (see External services above). Your messages are
forwarded to Claude (Anthropic) on your behalf.

**Pro BYOK tier:** Your own API key is stored encrypted (AES-256-CBC) in the WordPress
database and is transmitted directly to the AI provider you have chosen. It is never sent
to any other server.

= Is this plugin GDPR-compliant? =

The plugin transmits content you submit to Plume AI - Write and Design and/or the AI
provider you have configured (Anthropic Claude, OpenAI, Google Gemini). Both the service and
the AI provider receive your chat messages. You are responsible for ensuring that
transmission is compliant with your applicable data protection regulations. Consider adding
the Plume AI - Write and Design privacy policy and your chosen provider's data processing agreement to your
privacy documentation.

= What WordPress roles can use the AI features? =

Users with the `edit_posts` capability (Authors, Editors, Administrators) can use the
content generation tools. Site settings require `manage_options` (Administrators only).

== Development ==

Full source code including all React source files (src/) and build tooling is
publicly available at: https://github.com/niklas-joh/plume-ai

To regenerate compiled assets: install Node.js 18+ and npm, then run:

    npm install && npm run build

== Changelog ==

= NEXT_VERSION =
* **All features are now available on every tier.** Chat, Generator, SEO, and Images are no longer locked behind Pro — every site gets full access to all four modules from the moment you install or update.
* **Usage is now measured in credits, not tokens.** Each AI action (a chat turn, a generated draft, an SEO suggestion, an image) costs a small number of credits depending on the model used. Your plan determines how many credits you get each month and which models you can choose from — not which features you can use.
* **Free** now includes 100 credits/month, with access to Claude Haiku 4.5 and GPT-4.1 nano.
* **Pro Managed** ($79/year) now includes 500 credits/month, plus access to larger models — Claude Sonnet 4.6, Claude Opus 4.6, GPT-4.1, and Gemini 3.1 Pro — for higher-quality results on demanding tasks.
* **Pro BYOK** is unchanged: connect your own API key and get unlimited usage with no credit ceiling, billed directly by your provider.
* The 30-day trial has been removed. There's no longer a time-boxed "all features unlocked" period to track, because every tier already has full feature access — a trial no longer has anything distinct left to offer.
* Removed: the public-facing `[plume_chat]` shortcode and front-end chat widget module. This does not affect the in-editor SEO panel or admin-side Chat, Generator, Images, or SEO tools, which are unaffected and unlocked for all tiers as above.

= 1.7.1 =
* Fix chat post content not being read and add post-attach guard for quick actions.

= 1.7.0 =
* Chat layout improvements: collapsible sidebar, height fix, and title tooltips.

= 1.6.0 =
* Centre chat input and add suggestion chips on launch.

= 1.5.0 =
* Add generate_seo_meta tool for chat SEO optimisation.

= 1.4.0 =
* Fold usage widget into Dashboard and show percentage display.

= 1.3.6 =
* Fix context_post_id not being passed when sending messages from MiniChat in the editor.

= 1.3.5 =
* Sanitise conversation titles explicitly and remove unreachable database-error branch.

= 1.3.4 =
* Fix default provider selection, conversation title update, and inline delete errors.

= 1.3.3 =
* Add pro_byok guard in proxy Worker and clarify 401 error message.

= 1.3.2 =
* Prevent infinite loop in maybe_demote_expired_trials().

= 1.3.1 =
* Fix CI to push and close each auto-fix issue immediately after fixing.

= 1.3.0 =
* Batch auto-fix: one Claude session per PR, remove max-turns limits.

= 1.2.1 =
* Restore isPro field — revert erroneous canChat rename in settings.

= 1.2.0 =
* Consolidate PR review nits into a single issue per PR in the automated workflow.

= 1.1.0 =
* Add delete conversation with confirmation dialog and inline error state.

= 1.0.3 =
* Add @wordpress/element to devDependencies alongside peerDependencies.

= 1.0.2 =
* Return full conversation object on create; return HTTP 500 on DB failure.

= 1.0.1 =
* Guard putenv cleanup with try/finally in test suite.

= 1.0.0 =
* Full initial stable release: chat REST API, React UI, Gutenberg sidebar, SEO and Images pages.
* Encrypted API key storage, provider abstraction (Claude, OpenAI, Gemini, Ollama), tool-calling loop.
* Frontend chat widget shortcode, content generator wizard, and usage dashboard.

= 0.2.0 =
* Repo extraction, CI/CD pipeline, release workflow, and build script fix.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 1.7.1 =
No breaking changes. Update safely.

= 0.1.0 =
Initial release. No upgrade steps required.

== Screenshots ==

1. The Plume AI - Write and Design chat assistant in the WordPress admin.
2. The blog post generator with tone and length controls.
