=== Plume AI - Write and Design ===
Contributors: niklasjohansson
Tags: ai, writing, content, images, chatbot
Requires at least: 6.4
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered writing, image generation, and content design tools for WordPress.

== Description ==

Plume AI brings an AI writing and design assistant into your WordPress dashboard and block editor. Draft posts, polish copy, write SEO metadata and create featured images without leaving WordPress or copying text between tabs.

Chat, the Post generator and AI SEO work out of the box with no API key. Or connect your own Anthropic, OpenAI, Google Gemini or self-hosted Ollama account. AI images use your own OpenAI or Google Gemini key.

= What you can do =

* **Chat assistant**: brainstorm, research and rewrite in a conversation that can read your posts and search your content.
* **Post generator**: describe a post, pick a tone and length, and get a full draft ready to edit.
* **Editor sidebar**: chat about the post you are editing, rewrite, shorten or expand a block, and check a quick SEO checklist without leaving the block editor.
* **AI SEO**: generate meta titles, meta descriptions, excerpts and image alt text from one overview of all your posts.
* **AI images**: create a featured image from a text prompt and set it in one click (needs your own OpenAI or Gemini API key).
* **Usage dashboard**: see how many credits you have used this month.

= Plans =

Every feature works on every plan. Nothing is locked. Plans differ only in monthly credits and which models the managed service offers. AI images always use your own OpenAI or Gemini key, on any plan.

* **Free**: 100 credits a month on fast, efficient models such as Claude Haiku 4.5 and GPT-4.1 nano.
* **Pro Managed** ($79/year, or billed monthly): 500 credits a month plus larger models such as Claude Sonnet 4.6, Claude Opus 4.6 and GPT-4.1.
* **Your own API key** (free, any plan): requests go straight to your provider with no credit limit. You pay your provider directly. Ollama runs on your own server at no cost.

= Privacy first =

* Your own API keys are stored encrypted (AES-256-CBC) and only ever sent to the provider that issued them.
* Chat history stays in your WordPress database.
* No tracking or cookies in the plugin itself. If you use the Plume managed service, it records usage statistics for that service (see External services).

== External services ==

Plume AI connects to the following services. Nothing is sent until you use an AI feature or finish the setup wizard.

**Plume managed service** (`plume-proxy.plumewp.workers.dev`). Used for Chat, the Post generator and AI SEO when no API key of your own is set. The first time you use one of these (or finish the setup wizard with the managed option), it receives your site URL to register the site and assign its plan. With each request, it receives your prompt and the content you send, forwards it to the AI provider for the model you chose and returns the response. Message content is not stored beyond the request. For each request and registration attempt, the service also records usage statistics: your site's domain (including any path in the site URL, recorded only for successful registrations and for requests from registered sites), your plan, the feature used, the AI provider and model, token counts, credits used, response time, and whether the request succeeded or failed. Registration attempts that fail (an invalid challenge, too many attempts, or a failed verification) record no domain. A failed verification records only a short hashed reference derived from the site URL (the first 12 characters of its SHA-256 hash, called `site_ref`) and the failure reason. Prompts, responses, API keys, IP addresses and user details are never part of these statistics. They are used to operate and bill the service (credit limits), monitor reliability and speed, and understand usage to improve the product, and are kept for up to 90 days. Site records needed to run the service are kept while the site stays registered. [Terms](https://wpaimind.com/terms.html), [Privacy policy](https://wpaimind.com/privacy-policy.html).

**Anthropic (Claude)**: receives your prompts and content when you use a Claude model, directly with your own key or through the managed service. [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy policy](https://www.anthropic.com/legal/privacy).

**OpenAI**: receives your prompts and content when you use an OpenAI model, and your image prompts when OpenAI is your image provider (sent directly with your own key). [Terms](https://openai.com/policies/terms-of-use), [Privacy policy](https://openai.com/policies/privacy-policy).

**Google Gemini**: receives your prompts and content when you use a Gemini model, and your image prompts when Gemini is your image provider (sent directly with your own key). [Terms](https://ai.google.dev/gemini-api/terms), [Privacy policy](https://policies.google.com/privacy).

**Lemon Squeezy**: handles Pro checkout and payments. You only reach it by clicking an upgrade link, and payment details are never stored by the plugin. [Terms](https://www.lemonsqueezy.com/terms), [Privacy policy](https://www.lemonsqueezy.com/privacy).

**Ollama** runs on infrastructure you control (default `http://localhost:11434`). No data leaves your network unless you point it at a remote server.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin** and search for **Plume AI**.
2. Click **Install Now**, then **Activate**.
3. Open **Plume AI** in the admin menu and start with Chat, the Post generator or SEO. No API key needed.
4. Optional: under **Plume AI → Settings**, add your own Anthropic, OpenAI or Gemini API key, or your Ollama server URL. AI images need an OpenAI or Gemini key.

To install manually, upload the `plume` folder to `/wp-content/plugins/` and activate it from the **Plugins** screen.

== Frequently Asked Questions ==

= Do I need an API key? =

Not for Chat, the Post generator or AI SEO. Without a key, those requests go through the Plume managed service using your plan's monthly credits. AI images need your own OpenAI or Gemini key. Adding your own key is free on any plan and removes the credit limit.

= What is a credit? =

Each request to the managed service (a chat reply, a generated draft or an SEO suggestion) uses a small number of credits. Larger models use more. Credits reset every month. With your own API key, there is no credit limit.

= Which AI providers are supported? =

Anthropic Claude, OpenAI, Google Gemini and Ollama (self-hosted). You can configure several and switch between them in the settings or per conversation.

= Are my API keys stored securely? =

Yes. Keys are encrypted (AES-256-CBC) in your WordPress database and only sent to the provider that issued them, never to the Plume managed service or anyone else.

= Is Plume AI GDPR-compliant? =

Content you submit is sent to the AI provider you choose, and, if you have no API key, through the Plume managed service (see External services). You are responsible for making sure this suits your data protection obligations. Consider listing the Plume privacy policy and your provider's data processing terms in your own privacy documentation. Ollama keeps everything on your own server.

= Who can use the AI features? =

Users who can edit posts (Contributors and above) can use Chat, the Post generator and SEO. AI images also need permission to upload files (Authors and above). Only Administrators can change plugin settings.

= Where is the source code? =

All source code, including the uncompiled React files, is at [github.com/niklas-joh/plume-ai](https://github.com/niklas-joh/plume-ai). To rebuild the compiled assets, install Node.js 18+ and run `npm install && npm run build`.

== Screenshots ==

1. The dashboard: quick starts for writing, editing, images and chat, plus your monthly credit usage.
2. The chat assistant with quick actions and model selection.
3. The post generator: describe your post, choose tone and length, get a full draft.
4. AI SEO: see which posts are missing metadata and generate it in one click.
5. AI images: create a featured image from a text prompt with your own OpenAI or Gemini key.
6. Settings: choose your default AI and image providers, or add your own API keys.

== Changelog ==

= 2.0.0 =
First release on WordPress.org.

* Chat assistant, Post generator, AI SEO and AI images, all available on every plan.
* Usage is measured in monthly credits: Free includes 100, Pro Managed 500. Your own API key removes the limit on any plan.
* Choose your provider and model on every plan: Anthropic Claude, OpenAI, Google Gemini or self-hosted Ollama.
* Removed the 30-day trial and the front-end `[plume_chat]` shortcode. Admin and editor tools are unaffected.

Earlier development history: [CHANGELOG.md on GitHub](https://github.com/niklas-joh/plume-ai/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 2.0.0 =
First WordPress.org release. Usage now uses monthly credits, all features are available on every plan, and the [plume_chat] shortcode has been removed.
