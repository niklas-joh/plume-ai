---
description: Bug Fix Workflow
---
# Bug Fix Workflow

This workflow dictates the accelerated sequence for handling bugs on `blog.njohansson.eu`.

## Sequence

1. **Bug Report:** User reports an issue (or an error is tracked). The **Orchestrator** creates a task.
   - State: `status: planning`
   - Owner: `planner`
2. **Analysis (Planner):**
   - Analyze error logs or staging to determine the root cause.
   - Draft a minimal plan to patch the issue in `/plans/`.
   - Handoff -> State: `status: plan_review`, Owner: `reviewer` or `human`.
3. **Approval (Human):**
   - If the patch touches database columns or core functionality, human approval is mandatory. Small CSS/logic fixes require it as a general rule, but they are fast-tracked.
   - Handoff -> State: `status: approved`, Owner: `coder`.
4. **Fix Configuration (Coder):**
   - **Coder** writes the fix locally   - Pushes code to GitHub.
   - Updates `handoff_notes` in `/state/current-task.yml` outlining changes and deployment instructions.
   - Handoff -> State: `status: testing`, Owner: `reviewer`.
5. **Testing (Reviewer):**
   - Validate the patch statically against WP standards and `context7` best practices.
   - Syncs patch to staging using the Coder's instructions.
   - **Reviewer** specifically checks for regressions across the patched area on the staging site.
   - Handoff -> State: `status: ready_to_deploy`.
6. **Deploy (Human):**
   - Push to production via Siteground Site Tools.
