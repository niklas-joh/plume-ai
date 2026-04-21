---
description: New Feature Workflow
---
# New Feature Workflow

This workflow dictates the sequence of events and agent interactions required to build a new feature for `blog.njohansson.eu`.

## Sequence

1. **User Request:** User asks to create a new feature (e.g., "Add a newsletter block"). The **Orchestrator** receives this and creates a task entry in `/state/current-task.yml`. 
   - State: `status: planning`
   - Owner: `planner`
2. **Analysis & Planning (Planner):**
   - The **Planner** reads requirements and searches WordPress Docs.
   - For any step that involves an external API, library, or framework: look up the current API pattern via the `context7` MCP server before finalising architectural decisions. Record the validated pattern in the plan document.
   - Outputs a file in `/plans/` documenting architectural decisions, security requirements, and testing criteria.
   - Handoff -> State: `status: plan_review`, Owner: `reviewer` or `human`.
3. **Plan Review (Reviewer / Human):**
   - The **Reviewer** critiques the document. 
   - Human approval is REQUIRED. The Orchestrator strictly enforces `awaiting_human: true`.
   - Handoff -> State: `status: approved`, Owner: `coder`
4. **Implementation (Coder):**
   - The **Coder** implements code exactly according to the approved plan.
   - Pushes code to GitHub.
   - Updates `handoff_notes` in `/state/current-task.yml` outlining changes and deployment instructions.
   - Handoff -> State: `status: testing`, Owner: `reviewer`
5. **Testing (Reviewer):**
   - The **Reviewer** statically validates the code against WP standards, community best practices, and the `context7` MCP server.
   - If validation passes, the **Reviewer** deploys the changes to staging using the `handoff_notes` instructions.
   - The **Reviewer** executes testing validation against the staging site based on acceptance criteria and shared checklists.
   - Handoff -> State: `status: ready_to_deploy`
6. **Deployment (Human):**
   - Manual push from staging to production occurs via Siteground Site Tools.
   - Task completed -> State: `status: deployed`
