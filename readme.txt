=== WP AI Mind ===
Contributors: njohansson
Tags: ai, content, seo, chatbot, openai, claude, gemini
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 0.3.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered content co-pilot for WordPress.

== Description ==

WP AI Mind integrates Claude (Anthropic), OpenAI, Google Gemini, and Ollama directly
into your WordPress dashboard, giving you AI assistance without leaving the editor.

**Features:**

* **Chat assistant** — Conversational AI with tool support (read posts, search content)
* **Content generator** — Generate full blog posts with customisable tone and length
* **Usage tracker** — Monitor API usage and token consumption across providers
* **Frontend widget** — Embeddable chat widget via `[wp_ai_mind_chat]` shortcode
* **Gutenberg integration** — Direct editor sidebar tools

**Supported AI providers:** Anthropic Claude, OpenAI (GPT-4+), Google Gemini, Ollama (local/self-hosted)

**External services:** This plugin sends content you submit to the AI provider of your choice
(Anthropic, OpenAI, or Google). Your content leaves your server and is processed by the
chosen provider. Review each provider's privacy policy and terms of service before use:

* Anthropic: https://www.anthropic.com/legal/privacy
* OpenAI: https://openai.com/policies/privacy-policy
* Google Gemini: https://policies.google.com/privacy
* Ollama is self-hosted; no external transmission occurs when using Ollama.

== Installation ==

1. Upload the `wp-ai-mind` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Navigate to **WP AI Mind → Settings** and enter an API key for your chosen AI provider.
4. Start using the Chat, Generator, or Usage modules from the admin menu.

== Frequently Asked Questions ==

= Which AI providers are supported? =

Claude (Anthropic), OpenAI (GPT-4 and above), Google Gemini, and Ollama. You can configure
one or more providers and switch between them in the settings.

= Does this plugin store my API keys securely? =

API keys are stored in the WordPress database (wp_options). They are never transmitted to
any server other than the AI provider you have chosen.

= Is this plugin GDPR-compliant? =

The plugin transmits content you submit to the AI provider you have configured. You are
responsible for ensuring that transmission is compliant with your applicable data protection
regulations. Consider adding your chosen provider's data processing agreement to your
privacy documentation.

= What WordPress roles can use the AI features? =

Users with the `edit_posts` capability (Authors, Editors, Administrators) can use the
content generation tools. Site settings require `manage_options` (Administrators only).

== Changelog ==

= 0.2.0 =
* Repo extraction, CI/CD pipeline, release workflow, and build script fix.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release. No upgrade steps required.

== Screenshots ==

1. The WP AI Mind chat assistant in the WordPress admin.
2. The blog post generator with tone and length controls.
