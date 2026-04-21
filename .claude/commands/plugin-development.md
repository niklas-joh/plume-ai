---
description: Plugin Development Workflow
---
# Plugin Development Workflow

Creating a new plugin or modifying an existing one (e.g., `nj-example-plugin`).

## Sequence
1. **Scope and Features (Planner):**
   - Planner writes architectural design in `/plans/`.
   - Checks the *Plugin Decision Checklist* located in `wordpress-standards.md`.
   - Plan is sent to human review.
2. **Review (Human/Reviewer):**
   - Thoroughly review data model handling.
   - Reviewer signs off on security implications.
3. **Implementation (Coder):**
   - Write PHP code with the `nj_` prefix.
   - Adhere to `security-checklist.md`.
   - Writes deployment instructions and change notes to `handoff_notes` in `/state/current-task.yml`.
   - Run WPCS `composer lint` if set up locally.
4. **Testing (Reviewer):**
   - Conduct pre-deployment validation utilizing `context7` MCP, WP standards, and community best practices.
   - Push code to staging using the Coder's `handoff_notes` instructions.
   - Validate any new endpoints.
   - Test admin UI.
5. **Go Live (Human):**
   - Production deployment via Siteground interface.
