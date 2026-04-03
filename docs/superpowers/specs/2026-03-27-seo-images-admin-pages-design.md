# Design Spec: WP AI Mind — SEO & Images Admin Pages

## Context

Two Pro-gated admin pages are registered in the WordPress sidebar (`wp-ai-mind-seo`, `wp-ai-mind-images`) but currently render nothing — their PHP callbacks return `__return_false`. The REST endpoints and asset-loading PHP are already fully built. This spec defines the React UI for both pages.

**Core user problem:**
- Bloggers accumulate posts with incomplete SEO metadata and missing featured images.
- These pages let them scan for gaps at a glance and fill them with AI in one click per post.

Both pages share the same UX pattern: a list-first table showing gap status, with an inline work area that expands per row.

---

## Shared Architecture

### New webpack entries (`webpack.config.js`)

```js
'seo/index':    path.resolve( __dirname, 'src/seo/index.js' ),
'images/index': path.resolve( __dirname, 'src/images/index.js' ),
```

These match exactly what the PHP asset-loading hooks already expect (`assets/seo/index.js`, `assets/images/index.js`).

### Shared component: `src/shared/PostListTable.jsx`

Handles the list + inline expand pattern used by both pages. Responsibilities:
- Fetch posts and pages via WP core REST API (`/wp/v2/posts` and `/wp/v2/pages`, merged and sorted by date)
- Pagination (20 per page)
- Search (client-side filter on title)
- Tab filter (passed in as prop — each page defines its own tabs and filter logic)
- Expand/collapse state: one row open at a time; clicking an open row collapses it
- Renders a work area component (passed as prop) inside the expanded row

Props:
```js
PostListTable({
  tabs,            // [{ label, filter: (post) => bool }]
  badgeRenderer,   // (post) => <Badge />
  WorkArea,        // component rendered in expanded row, receives { post, onClose }
  columns,         // extra column definitions beyond title/type/updated
})
```

### Pro gate

Both pages check `window.wpAiMindData.isPro`. Non-Pro users see a lock screen — the list is never rendered. The lock screen contains:
- Lock icon
- Feature title + one-sentence value description
- "Upgrade to Pro →" CTA button

### Design tokens

Inherits `src/styles/tokens.css`. The plugin now uses the WP admin light palette — the dark zinc values have been removed. Key values in use:

| Token | Value | Use |
|---|---|---|
| `--color-bg` | `#fff` | Page background |
| `--color-surface` | `#f8f9fa` | Cards, work area |
| `--color-surface-2` | `#f0f0f1` | Inputs, hover backgrounds |
| `--color-border` | `#dcdcde` | Default borders |
| `--color-text-primary` | `#1d2327` | Body text |
| `--color-text-secondary` | `#50575e` | Labels, secondary text |
| `--color-text-muted` | `#787c82` | Placeholders, muted text |
| `--wp-admin-theme-color` | (WP native) | Accent — buttons, focus rings, highlights |

Use `var(--wp-admin-theme-color)` for all interactive accent elements (primary buttons, selected states, focus borders). Do not hardcode `#3b82f6`.

### Folder structure

```
src/
  shared/
    PostListTable.jsx       ← new
    MarkdownContent.jsx     ← existing
  seo/
    index.js
    SeoApp.jsx
    SeoBadge.jsx
    SeoWorkArea.jsx
    seo.css
  images/
    index.js
    ImagesApp.jsx
    ImagesBadge.jsx
    ImagesWorkArea.jsx
    images.css
```

---

## SEO Page (`wp-ai-mind-seo`)

### Purpose

Let editors scan all posts and pages for SEO gaps and fill them with AI-generated metadata — one post at a time, inline.

### List view

**Columns:** Title | Type (`post` / `page` badge) | SEO Status | Updated | Action button

**SEO Status badge** (single aggregate):
- `Complete` (green) — all four fields present: meta title, OG description, excerpt, featured image alt text
- `Partial` (amber) — some fields present
- `Missing` (red) — no fields present

**PHP requirement:** The WP REST API does not expose Yoast/RankMath meta fields by default. The `SeoModule` (or a new `SeoStatusController`) must register a computed `wpaim_seo_status` field on posts and pages via `register_rest_field()`. This field returns an object:
```json
{
  "meta_title":     "filled" | "empty",
  "og_description": "filled" | "empty",
  "excerpt":        "filled" | "empty",
  "alt_text":       "filled" | "empty"
}
```
The React badge derives `Complete` / `Partial` / `Missing` from this object client-side.

Field "filled" logic in PHP:
- `meta_title`: `filled` if `_yoast_wpseo_title` OR `rank_math_title` post meta is non-empty
- `og_description`: `filled` if `_yoast_wpseo_metadesc` OR `rank_math_description` post meta is non-empty
- `excerpt`: `filled` if `post_excerpt` is non-empty
- `alt_text`: `filled` if the post has a featured image AND `_wp_attachment_image_alt` on that attachment is non-empty

**Tabs:** All | Missing | Partial | Complete

**Search:** Client-side filter on post title. Input in toolbar row alongside tabs.

### Inline work area (expanded row)

**Header:** Post title (truncated) + "✦ Generate SEO" button (right-aligned).

**Fields grid** (2 columns):
| Field | Notes |
|---|---|
| Meta title | Character count display (target: ≤ 60). Single line input. |
| OG description | Character count display (target: ≤ 160). Single line input. |
| Excerpt | Full width (spans both columns). Textarea. |
| Alt text | Full width. Single line input. Label clarifies "featured image alt text". |

After `POST /seo/generate` returns, the four fields populate and highlight (blue border) to signal AI-generated content. All fields are editable before applying.

**Actions (right-aligned):**
- "Edit post →" — opens Gutenberg in a new tab (`wp-admin/post.php?post={id}&action=edit`)
- "Discard" — clears fields, collapses row
- "✓ Apply all" — calls `POST /seo/apply` with the current field values; on success collapses the row and updates the badge

**Loading state:** Generate button shows a spinner and "Generating…" label. Fields show a skeleton shimmer.

**Error state:** Inline error message below the fields. Generate button re-enabled.

### Generate button behaviour

- If the work area has no unsaved AI output: clicking Generate calls the API immediately.
- If the work area already has AI output (user clicked Generate twice): show a confirm prompt — "Replace current suggestions?"

### Non-Pro state

Lock screen with:
- Lock icon
- Title: "AI SEO requires WP AI Mind Pro"
- Body: "Automatically generate meta titles, OG descriptions, excerpts, and image alt text for every post — in one click."
- CTA: "Upgrade to Pro →"

---

## Images Page (`wp-ai-mind-images`)

### Purpose

Let editors identify posts and pages without a featured image and generate one with AI — directly setting it on the post without leaving this page.

### List view

**Columns:** Title | Type | Featured Image | Updated | Action button

**Featured Image column:**
- If post has a featured image: show a 36×36px thumbnail + `Has image` (green) badge
- If no featured image: `No image` (red) badge

**Tabs:** All | No image | Has image

**Search:** Client-side filter on post title.

**Data source:** `featured_media` field is part of the standard WP REST API post response. No custom PHP needed for the badge. To get the thumbnail URL inline, fetch posts with `?_embed` — the `_embedded['wp:featuredmedia'][0].media_details.sizes.thumbnail.source_url` path provides the URL without a second request.

### Inline work area (expanded row)

**Layout:** Prompt textarea (left, ~70% width) + controls column (right, ~30% width).

**Prompt textarea:**
- Placeholder: "Describe the image you want to generate…"
- Free-form text. No AI assistance on the prompt itself.

**Controls column (stacked vertically):**
1. **Aspect ratio** — select dropdown: `16:9` (default), `1:1`, `4:3`, `9:16`
2. **Count** — pill toggle: `1` | `2` | `3` (default: `2`)
3. **✦ Generate** — primary blue button

**Results grid:**
- Generated images appear as cards in a 3-column grid (or fewer if count < 3)
- Each card: image thumbnail (correct aspect ratio) + footer with dimensions + "View →" link (media library attachment page, new tab)
- Click a card to select it (blue border + "✓ Selected" badge)
- Only one image selectable at a time

**Actions (right-aligned):**
- "Edit post →" — opens Gutenberg in new tab
- "Discard" — clears results, collapses row
- "✓ Set as featured image" — enabled only when an image is selected; calls `PATCH /wp/v2/posts/{id}` (or `/wp/v2/pages/{id}`) with `{ featured_media: attachment_id }`; on success collapses the row and updates the badge + thumbnail in the list

**Loading state:** Generate button shows spinner. Result grid area shows 2–3 placeholder shimmer cards.

**Error state:** Inline error message below the prompt. Generate button re-enabled.

**Partial success (HTTP 207):** If some images failed but at least one succeeded, show the successful ones plus a dismissible warning: "1 of 2 images failed to generate."

### Non-Pro state

Lock screen with:
- Lock icon
- Title: "AI image generation requires WP AI Mind Pro"
- Body: "Generate beautiful featured images from a text prompt and set them directly on any post or page."
- CTA: "Upgrade to Pro →"

---

## What Is Explicitly Out of Scope

- Bulk generation (selecting multiple posts and generating for all at once)
- Prompt history or previously generated image gallery
- nj-seo-essentials integration or upsell
- Custom post types beyond `post` and `page`
- Role-based access beyond the existing `edit_posts` capability check

---

## Files to Create / Modify

| File | Action |
|---|---|
| `webpack.config.js` | Add `seo/index` and `images/index` entries |
| `src/shared/PostListTable.jsx` | New — shared list + inline expand component |
| `src/seo/index.js` | New — entry point, mounts `SeoApp` |
| `src/seo/SeoApp.jsx` | New — Pro gate + `PostListTable` wiring |
| `src/seo/SeoBadge.jsx` | New — Complete/Partial/Missing badge |
| `src/seo/SeoWorkArea.jsx` | New — generate/edit/apply work area |
| `src/seo/seo.css` | New — page-scoped styles |
| `src/images/index.js` | New — entry point, mounts `ImagesApp` |
| `src/images/ImagesApp.jsx` | New — Pro gate + `PostListTable` wiring |
| `src/images/ImagesBadge.jsx` | New — Has image/No image badge + thumbnail |
| `src/images/ImagesWorkArea.jsx` | New — prompt/controls/grid/set work area |
| `src/images/images.css` | New — page-scoped styles |
| `includes/Modules/Seo/SeoModule.php` | Add `register_rest_field()` for `wpaim_seo_status` on posts and pages |

---

## Verification

1. **SEO page — Pro:**
   - Navigate to WP AI Mind → SEO. List of posts and pages loads with correct badges.
   - "Missing" tab filters to only posts with no SEO fields.
   - Search filters by title.
   - Click "Generate ▼" on a post → work area expands, other rows stay closed.
   - "✦ Generate SEO" → spinner appears, fields populate with highlighted AI content.
   - Edit a field → change persists in the input.
   - "✓ Apply all" → row collapses, badge updates to Complete (or Partial if some fields were blank).
   - "Discard" → row collapses, no changes saved.
   - "Edit post →" opens Gutenberg in a new tab.
   - Pagination: navigate to page 2 and back.

2. **SEO page — non-Pro:**
   - Lock screen renders. List is not rendered. "Upgrade to Pro →" button present.

3. **Images page — Pro:**
   - Navigate to WP AI Mind → Images. List loads with Has image / No image badges. Posts with featured images show thumbnails.
   - "No image" tab filters correctly.
   - Click "Generate ▼" → work area expands.
   - Enter prompt, select aspect ratio and count, click "✦ Generate" → spinner, then image grid appears.
   - Click an image → selected state (blue border).
   - "✓ Set as featured image" → row collapses, table cell updates to show new thumbnail + Has image badge.
   - "View →" on image card opens media library in new tab.
   - HTTP 207 partial success: warning banner shown, successful images displayed.

4. **Images page — non-Pro:**
   - Lock screen renders. List is not rendered.
