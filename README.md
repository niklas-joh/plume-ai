# Plume AI - Write and Design

[![Latest release](https://img.shields.io/github/v/release/niklas-joh/plume-ai)](https://github.com/niklas-joh/plume-ai/releases/latest)
[![Licence: GPL v2 or later](https://img.shields.io/badge/licence-GPLv2%2B-blue.svg)](LICENSE)
[![CI](https://github.com/niklas-joh/plume-ai/actions/workflows/ci.yml/badge.svg)](https://github.com/niklas-joh/plume-ai/actions/workflows/ci.yml)
[![WordPress plugin version](https://img.shields.io/wordpress/plugin/v/plume)](https://wordpress.org/plugins/plume/)
[![WordPress plugin rating](https://img.shields.io/wordpress/plugin/stars/plume)](https://wordpress.org/plugins/plume/#reviews)

AI-powered writing, image generation and content design tools for WordPress.

Plume AI brings an AI writing and design assistant into your WordPress dashboard and block editor. Draft posts, polish copy, write SEO metadata and create featured images without leaving WordPress.

> **Note:** `readme.txt` is the source of truth for the WordPress.org listing. This file is for GitHub only, so edit `readme.txt` to change what appears on WordPress.org.

## Features

- **Chat assistant**: brainstorm, research and rewrite in a conversation that can read your posts and search your content.
- **Post generator**: describe a post, pick a tone and length, and get a full draft ready to edit.
- **Editor sidebar**: chat about the post you are editing, rewrite, shorten or expand a block, and check a quick SEO checklist.
- **AI SEO**: generate meta titles, meta descriptions, excerpts and image alt text from one overview of all your posts.
- **AI images**: create a featured image from a text prompt (needs your own OpenAI or Gemini API key).
- **Usage dashboard**: see how many credits you have used this month.

## Providers and plans

Chat, the Post generator and AI SEO work out of the box through the Plume managed service, using monthly credits. Or connect your own Anthropic, OpenAI, Google Gemini or self-hosted Ollama account to remove the credit limit. Every feature is available on every plan. See the [WordPress.org listing](https://wordpress.org/plugins/plume/) for current plan details.

## Privacy

- Your own API keys are stored encrypted and only sent to the provider that issued them.
- Chat history stays in your WordPress database.
- No tracking or cookies in the plugin itself. The Plume managed service records usage statistics for that service (see the External services section of readme.txt).

Plume AI connects to external services (the managed service, your chosen AI provider and the checkout provider). The full disclosure, with terms and privacy links, is in the [External services section of readme.txt](https://github.com/niklas-joh/plume-ai/blob/main/readme.txt).

## Installation

**From WordPress.org:** in your admin, go to **Plugins → Add New Plugin**, search for **Plume AI**, then install and activate it. Or see the [plugin page](https://wordpress.org/plugins/plume/).

**From GitHub:** download the zip from the [latest release](https://github.com/niklas-joh/plume-ai/releases/latest), then upload it under **Plugins → Add New Plugin → Upload Plugin** and activate it.

Then open **Plume AI** in the admin menu. No API key is needed to start.

## Development

The plugin is namespaced PHP (`Plume\`) with a React admin UI. Source is in `src/` (compiled to `assets/`), and `plume-proxy/` is the Cloudflare Worker behind the managed service.

- [CONTRIBUTING.md](https://github.com/niklas-joh/plume-ai/blob/main/CONTRIBUTING.md): setup, local WordPress environment, checks and pull request rules
- [RELEASING.md](https://github.com/niklas-joh/plume-ai/blob/main/RELEASING.md): how releases work
- [CHANGELOG.md](https://github.com/niklas-joh/plume-ai/blob/main/CHANGELOG.md): release history

Pull request titles must be valid [Conventional Commits](https://www.conventionalcommits.org/); see CONTRIBUTING.md for details.

## Links

- [WordPress.org plugin page](https://wordpress.org/plugins/plume/)
- [Support forum](https://wordpress.org/support/plugin/plume/)
- [Report an issue](https://github.com/niklas-joh/plume-ai/issues)

## Licence

GPL v2 or later. See [LICENSE](LICENSE).
