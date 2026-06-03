# Design Spec: Stilus — Admin Landing Page & Onboarding Modal

## Context

The plugin currently lands users directly in the Chat view when they open Stilus in the WordPress admin. There is no orientation layer — new users have no clear starting point, and existing users can't reach key features without knowing the sidebar structure. This spec defines a Dashboard landing page (the new top-level entry point) and a first-run onboarding modal to address both problems.

---

## Navigation Structure Change

The WordPress admin sidebar changes from a flat structure to a parent/sub-page hierarchy:

```
Before:                     After:
Stilus (→ Chat)         Stilus (→ Dashboard)  ← new landing page
                              ├─ Chat
                              ├─ Generator
                              ├─ Images
                              ├─ SEO
                              ├─ Usage & Cost
                              └─ Settings
```

The top-level `Stilus` menu item now registers the Dashboard page. All existing feature pages become sub-pages.

---

## Landing Page (Dashboard)

### Layout

Fully static. No API calls. Three visible layers plus a footer strip.

```
┌─ Top bar ──────────────────────────────────────────────┐
│  Stilus                                    v0.2.0  │
│  AI-powered content creation for WordPress            │
├─ Status banner (conditional) ─────────────────────────┤
│  Only rendered when there is something actionable      │
│  (e.g. no API key configured). Hidden otherwise.       │
├─ Start ────────────────────────────────────────────────┤
│  [ Write a new post ] [ Edit with AI ] [ Generate an   │
│  image ] [ Chat ]                                      │
├─ Resources ────────────────────────────────────────────┤
│  Getting started guide                                 │
│  Prompt writing tips                                   │
│  API key setup                                         │
│  Changelog                                             │
├─ Footer ───────────────────────────────────────────────┤
│  Settings · Run setup again · Documentation · Support  │
└────────────────────────────────────────────────────────┘
```

### Start Section

Four action tiles, each linking to a plugin feature. Tiles use verbs, not feature names. The first tile ("Write a new post") carries a primary visual treatment (blue-tinted border and background) to indicate the most common action.

| Tile | Destination |
|---|---|
| Write a new post | Generator |
| Edit with AI | Posts list (opens Gutenberg with AI sidebar) |
| Generate an image | Image Generator |
| Chat | Chat |

### Status Banner

Rendered conditionally based on PHP option flags:

- **No API key, using Plugin API (free):** show amber banner — "You are on the free Plugin API. Add your own key for unlimited access, or upgrade to Pro." — with two CTAs: "Add API key" (→ Settings) and "Upgrade to Pro" (→ upgrade page, new tab).
- **API key configured, valid:** banner hidden.
- **API key configured but invalid / expired:** show red variant — "Your API key appears to be invalid. Check your Settings."
- **Pro licence active:** banner hidden.

### Resources Section

Four external links, all `target="_blank" rel="nofollow noreferrer"`:

1. Getting started guide — "Five minutes to your first AI-generated post."
2. Prompt writing tips — "How to write prompts that consistently produce better output."
3. API key setup — "Connect OpenAI, Claude, or Gemini to remove usage limits."
4. Changelog — "What's new in v0.2.0." (version string pulled from plugin constant)

### Footer Strip

Inline links separated by dividers:
- **Settings** → plugin Settings page
- **Run setup again** → resets the onboarding seen flag and re-opens the modal
- **Documentation** → external docs (new tab, nofollow noreferrer)
- **Support** → external support URL (new tab, nofollow noreferrer)

### Design Tokens

Matches existing plugin dark zinc aesthetic. Key values:
- Background: `#08080a`
- Surface: `#111113`
- Border: `#1f1f23`
- Accent: `#3b82f6`
- Muted text: `#71717a`
- Font: DM Sans (body) + DM Mono (labels, badges, meta)

---

## Onboarding Modal

### Trigger

Shown automatically on first activation of the plugin. A WordPress option flag (`wpaim_onboarding_seen`) controls visibility. Once dismissed (any path), the modal never auto-appears again. "Run setup again" in the footer resets this flag.

### Branching Flow

```
Step 1: Choose connection
  ├─ Plugin API (free)  →  Done (1-step flow)
  ├─ Own API key        →  Step 2: Provider & models  →  Done (2-step flow)
  └─ Upgrade to Pro     →  Redirect to upgrade page (new tab), modal closes
```

### Step 1 — Choose Connection

Three radio options:

1. **Use Plugin API** `FREE` — "Start immediately. Built-in access with usage limits. No API key needed."
2. **Use my own API key** — "Connect OpenAI, Claude, or Gemini directly. Unlimited usage."
3. **Upgrade to Pro** `PRO` — "Full access, priority support, and advanced features."

CTA: "Get started →"
- If Plugin API selected: advances to Done screen.
- If Own API key selected: advances to Step 2.
- If Pro selected: button label changes to "Go to Upgrade ↗", opens upgrade page in new tab, modal closes.

"Skip setup" link dismisses modal and sets the seen flag. User lands on Dashboard.

### Step 2 — Provider & Models (own API key path only)

**Text model provider** (required) — three cards: OpenAI, Claude, Gemini. Each card includes a "Get API key ↗" link (`target="_blank" rel="nofollow noreferrer"`):
- OpenAI → `https://platform.openai.com/api-keys`
- Claude → `https://console.anthropic.com/settings/keys`
- Gemini → `https://aistudio.google.com/apikey`

Selecting a provider reveals an inline API key input field (password type, monospace font).

**Image model provider** (optional) — collapsible section clearly labelled "Optional". Two provider cards:
- OpenAI (DALL·E) → links to `https://platform.openai.com/docs/guides/images`
- Gemini (Imagen 3) → links to `https://ai.google.dev/gemini-api/docs/image-generation`
- "More coming" placeholder slot (dashed border, non-interactive)

> **Dev note — Gemini image endpoint:** Gemini image generation uses Imagen 3 (`imagen-3.0-generate-*` models). Before shipping, verify whether this endpoint is callable via the same `generativelanguage.googleapis.com` base URL used for text, or whether it requires Vertex AI / a separate endpoint.

CTA: "Finish setup →" | "← Back"

### Done Screen (shared)

Shown after both the Plugin API path and the Own API key path complete.

- Success heading: "You're all set"
- Summary line: "Using [API tier]. Change this anytime in Settings."
- Primary CTA: "Open Chat →" (full-width green button)
- Secondary link: "Go to Dashboard instead"

### Progress Indicator

Pip bar at the top of the modal header:
- Plugin API path: 1 pip (active on Step 1, done on Done)
- Own API key path: 2 pips (active/done as user progresses)

---

## Pro Gating

Features unavailable on the current tier show an amber `PRO` badge. In the Start tiles, the Generator and Image tiles may carry this badge if the user is on the free Plugin API without a Pro licence. Exact gating logic deferred to the licensing system — badge display is driven by a PHP helper that checks licence status.

---

## Settings: "Run Setup Again"

A "Run setup again" entry in the plugin's Settings page (General or Account section) resets `wpaim_onboarding_seen` to `false`. On next Dashboard load, the modal re-opens from Step 1.

---

## What Is Explicitly Out of Scope

- Quick Edit (recent posts list with AI action chips) — scoped out, revisit post-launch
- Live usage stats on the Dashboard — fully static, no WP REST calls on this page
- Role-based content (admin vs. editor) — not in this iteration

---

## Files to Create / Modify

| File | Action |
|---|---|
| `stilus.php` or main plugin file | Register new Dashboard page as top-level menu; demote existing pages to sub-menus |
| `src/admin/index.js` | Add Dashboard view entry point |
| `templates/admin/dashboard.php` (new) | Dashboard page template |
| `templates/admin/onboarding-modal.php` (new) | Onboarding modal markup |
| `src/admin/dashboard/` (new) | React components: `StartTiles`, `ResourceList`, `StatusBanner`, `OnboardingModal` |
| `includes/class-wpaim-dashboard.php` (new) | PHP: register page, handle `wpaim_onboarding_seen` option, render status banner state |
| Existing CSS / design token file | Ensure DM Sans + DM Mono are loaded on admin pages |

---

## Verification

1. Activate plugin on a fresh WordPress install — onboarding modal appears automatically.
2. Select "Plugin API" → modal advances to Done in one step.
3. Select "Own API key" → Step 2 appears with provider cards and key input. "Get API key" links open in new tab.
4. Select "Pro" → upgrade page opens in new tab, modal closes.
5. "Skip setup" dismisses modal; Dashboard loads; modal does not reappear on refresh.
6. Settings → "Run setup again" → modal reappears on next Dashboard load.
7. Dashboard loads with no API key → status banner visible. Configure a valid key → banner hidden.
8. All four Start tiles navigate to their correct destinations.
9. All Resource links open in new tab with nofollow noreferrer.
10. Sidebar now shows Dashboard as parent with Chat, Generator, Images, SEO, Usage & Cost, Settings as sub-pages.
