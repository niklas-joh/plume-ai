---
description: Theme Modification Workflow
---
# Theme Modification Workflow

When editing the site's theme (e.g. `nj-theme`), strict adherence to WP standards is required.

## Checklist Sequence

1. **Orchestrate Task:** Task initialized by **Orchestrator**.
2. **Design Plan (Planner):**
   - Evaluate whether it is a `theme.json` update or CSS (`style.css`).
   - Will this break existing custom blocks? Planners must check.
   - Draft plan to `/plans/`. Human approval is **MANDATORY**.
3. **Code & Build (Coder):**
   - If a block theme, implement React/JSX code and run `@wordpress/scripts` via `npm run build`.
   - Updates `handoff_notes` in `/state/current-task.yml` outlining changes and deployment instructions.
4. **Verify Theme (Reviewer):**
   - Code Validation: Statically validate the code against WP standards and context7 MCP insights prior to deployment.
   - Execute deployment: Backup staging and `rsync` theme files directly to the server, per the `handoff_notes`.
   - Complete `accessibility-checklist.md` verification thoroughly. Contrast and ARIA elements are critical for themes.
5. **Ship (Human):**
   - Final human review on `staging5.blog.njohansson.eu`.
   - Manually deploy.
