---
description: Content Update Workflow
---
# Content Update Workflow

Use this workflow to generate, modify, and manage posts or page content using WP-CLI.

## Sequence

1. **Content Request:** Orchestrator tracks user request.
2. **Drafting (Planner/Coder):**
   - Content is drafted visually or programmatically.
   - **Coder** uses `wp @staging post create` (or `update`) to insert the draft text. Alternatively, it might just assist the user via the local AI environment.
3. **Review (Reviewer):**
   - **Reviewer** validates spelling, formatting, i18n considerations, and SEO metadata on staging.
4. **Approval & Publish (Human):**
   - Since moving database/content between staging and production can be complex, typically this means either directly creating the post on production via WP-CLI, or doing a full DB sync from staging -> prod via Site Tools. Human supervision is strictly required.
