# File Hygiene — Agent Artifacts

## Rule: All agent-generated files go in `.artifacts/`

Agents MUST save all transient files to `.artifacts/` in the repository root.
This directory is gitignored. Nothing in it is ever committed.

### Sub-directories

| Sub-directory | Use |
|---|---|
| `.artifacts/screenshots/` | Playwright screenshots, MCP browser captures, visual comparisons |
| `.artifacts/reports/` | File-based review reports, HTML diffs, JSON exports |

### Naming convention for screenshots

```
{timestamp}-{context}-{breakpoint}.{ext}
```

- `timestamp`: `date +%Y%m%d-%H%M%S` — always first, enables chronological sorting and bulk deletion
- `context`: what is being reviewed (e.g. `review-homepage`, `verify-blog-grid`, `coder-site-editor`)
- `breakpoint`: viewport width in px (e.g. `1280`, `900`, `375`) or `full`
- `ext`: `jpeg` for screenshots, `png` for diagrams/overlays

Example: `.artifacts/screenshots/20260323-143022-review-homepage-1280.jpeg`

### Create the directories if missing

```bash
mkdir -p /Users/niklas/Documents/Homepages/blog.njohansson.eu/.artifacts/screenshots
mkdir -p /Users/niklas/Documents/Homepages/blog.njohansson.eu/.artifacts/reports
```

### Cleanup policy

Each agent deletes **only the specific files it created**, never a wholesale directory wipe.
This is safe for parallel agents. At task completion, delete only files you saved:

```bash
# List the exact files you created and delete only those
rm -f .artifacts/screenshots/20260323-143022-review-homepage-1280.jpeg
rm -f .artifacts/screenshots/20260323-143055-verify-blog-grid-375.jpeg
```

**Never** run `rm -f .artifacts/screenshots/*` — other agents may be running concurrently.

To wipe everything manually (user only): `bin/clean-artifacts.sh` — never called by agents.

### What is NOT a transient artifact

- Agent role files (`.agents/roles/`) — version controlled
- Shared standards (`.agents/_shared/`) — version controlled
- State files (`/state/current-task.yml`) — gitignored, dedicated path
- Plans (`plans/`) — gitignored, dedicated path
- Production code — always in `wp-content/`

### Enforcement

A `PreToolUse` hook (`.claude/hooks/enforce-artifact-path.sh`) intercepts Write tool calls.
If a `.png`, `.jpeg`, `.jpg`, `.gif`, `.webp`, `.html`, or `.htm` file is written to the
repository root, the hook blocks the call and prints the correct target path.

MCP browser tools (Playwright, Claude Preview, etc.) bypass the Write hook — for those,
the agent instructions above are the enforcement mechanism.


---

# Coding Conventions and Best Practices

All coders MUST adhere to the following when writing implementations:

## Prefixing
- Function prefix: `nj_`
- All custom functions, global variables, and options must use this prefix to prevent collisions.

## Language and i18n
- Use British English in content.
- All user-facing strings MUST be translatable using standard WP functions (`__()`, `_e()`, `esc_html__()`, etc).
- Use `nj-` text domain prefix where appropriate (e.g. `nj-theme`, `nj-example-plugin`).

## PHP Standards (Brief)
- Prevent direct file access in plugins:
  ```php
  if ( ! defined( 'ABSPATH' ) ) {
      exit;
  }
  ```
- Use descriptive hook names with prefixes.
- Follow WordPress Coding Standards (sniffs available via `composer lint`).

## CSS Standards (Block Theme)

This project is a block theme. CSS must follow the block-theme styling hierarchy:

1. `theme.json` first — colors, spacing, typography, per-block styles
2. `wp_enqueue_block_style()` — per-block CSS, loaded on-demand
3. `wp_enqueue_style()` — only for global styles with no per-block equivalent
4. **Never** use `add_action('wp_head', ...)` to output inline styles

**Specificity:** Always wrap selectors in `:root :where()`:
```css
:root :where(.wp-block-cover) { min-height: 400px; }
```

**Preset variables:** Never hardcode values that exist as theme.json presets:
```css
/* Correct */   color: var(--wp--preset--color--primary);
/* Wrong */     color: #06b6d4;
```

**Naming:** Prefix custom classes with `nj-`. Use BEM: `nj-block__element--modifier`.
Use WordPress admin color variables in wp-admin UI.

## JavaScript Standards
- Write modular ES6+ compliant code.
- Prefix globals if necessary.
- Follow Block Editor `@wordpress/blocks` standards for custom Gutenberg blocks.


---

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


---

---
description: Emergency Rollback Procedures
---
# Emergency Rollback Procedures

This workflow triggers when a deployment to staging or production fails fatally, or if severe bugs are caught post-deploy.

## Staging Rollback
1. An issue is detected on `staging5.blog.njohansson.eu` (by a Human or Reviewer).
2. The **Coder** or Human runs the rollback script:
   ```bash
   ./scripts/rollback-staging.sh <timestamp>
   ```
   (where `<timestamp>` is fetched from `/state/current-task.yml` or standard output.)
3. Validate recovery by clearing cached data and testing the staging site again.

## Production Rollback
Since we rely on Siteground Site Tools for production deployments:
1. **STOP all agent activity.**
2. A **Human** must manually revert the push utilizing Siteground Site Tools "Backups" tab or doing a reverse custom deploy.
3. Inform the Orchestrator that the task state is `failed`.


---

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


---

# WordPress Development Profile — AI Agent Instructions

> This is the WordPress profile for the `niklas-joh/ai-instructions` system.
> Core skills (brainstorming, TDD, debugging, git worktrees, etc.) are in `_core/skills/`.
> Core shared standards (file-hygiene, coding-conventions) are in `_core/_shared/`.

---

## Environment Setup (Fresh Clone / New Machine)

If you are in a freshly cloned environment and `.claude/agents/` symlinks are missing or broken:

1. Check whether initialization has already run: `ls .agents/.initialized 2>/dev/null`
   - **If the file exists**: the environment is initialized. Do NOT run `ai-init` again.
   - **If the file is absent**: run `ai-init --restore` once to recreate symlinks.

2. After running `ai-init --restore`, verify: `ls .claude/agents/` should list only unnumbered `.md` files.

**Never run `ai-init` more than once per machine per project.** Running it repeatedly creates duplicate symlinks.

---

## Mandatory Development Pipeline

**Every coding request — no exceptions — must follow this pipeline. Never write code without an approved plan.**

```
1. wordpress-planner             → draft implementation plan
2. wordpress-standards-validator → validate plan against WP standards + block-first rules
       ↓ REVISE if rejected (back to step 1)
       ↓ APPROVED
3. ⛔ HUMAN APPROVAL             → present plan to user, wait for explicit go-ahead
4. wordpress-coder               → implement approved plan locally
5. wordpress-reviewer            → review on localhost:8080 (local Docker)
       ↓ FAIL → back to step 4 with reviewer findings
       ↓ PASS
6. git push + staging deploy     → `git push` then verify on staging
7. Production                    → manual deployment by human
```

**Shortcuts are not permitted:**
- Never skip the planner because a task "seems small"
- Never skip the validator because a plan "looks fine"
- Never write a single line of production code before human approval
- Never deploy to staging without a reviewer sign-off
- Never deploy to production — that is always a manual human step

**Use the task-orchestrator agent as the entry point for all coding requests.**

---

## Which Agent to Use

| Task                        | Agent                          | File                                                        |
|-----------------------------|--------------------------------|-------------------------------------------------------------|
| New feature / bug fix       | task-orchestrator              | `.agents/profiles/wordpress/roles/task-orchestrator.md`     |
| Planning only               | wordpress-planner              | `.agents/profiles/wordpress/roles/wordpress-planner.md`     |
| Validate a plan             | wordpress-standards-validator  | `.agents/profiles/wordpress/roles/wordpress-standards-validator.md` |
| Implementation              | wordpress-coder                | `.agents/profiles/wordpress/roles/wordpress-coder.md`       |
| QA & review                 | wordpress-reviewer             | `.agents/profiles/wordpress/roles/wordpress-reviewer.md`    |

---

## Environments

| Environment | URL / Alias | Notes |
|-------------|-------------|-------|
| Local       | localhost:8080 | Docker, full DB access, safe playground |
| Staging     | staging4.blog.njohansson.eu | Integration testing via WP-CLI + SSH |
| Production  | blog.njohansson.eu | Live site, manual deploy only |

- SSH alias: `siteground-staging` (port 18765)
- Hosting: Siteground GoGeek

### Remote Paths
- Staging: `/home/u2842-hw3sugd8pbqa/www/staging4.blog.njohansson.eu/public_html`
- Production: `/home/u2842-hw3sugd8pbqa/www/blog.njohansson.eu/public_html`

---

## Commands

### Local Development
- **Start environment**: `docker compose up -d`
- **Database sync from staging**: `./bin/db-pull.sh`
- **Database push to staging**: `./bin/db-push.sh` (use sparingly)
- **Direct database access**: `docker exec blognjohanssoneu-db-1 mysql -uwp_user -pwp_pass wordpress`
- **Local WordPress**: http://localhost:8080

### Remote Operations
- **WP-CLI**: `wp @staging <command>` or `wp @production <command>`
- **SSH**: `ssh siteground-staging` then `cd ~/www/staging4.blog.njohansson.eu/public_html`
- **SFTP/SCP**: Use port 18765

### Daily Session
1. `docker compose up -d` → `./bin/db-pull.sh`
2. Agents work with local files + database
3. Deploy to staging: `git push` + `./bin/db-push.sh` (if DB changes)
4. Production: manual via Siteground Site Tools

---

## WordPress Coding Standards

- Prefix all custom functions: `nj_` (theme/site plugins; WP AI Mind plugin uses `WP_AI_Mind\` namespace instead)
- Use British English in content and comments
- Follow WordPress PHP Coding Standards
- Escape output: `esc_html()`, `esc_attr()`, `esc_url()`
- Sanitize input: `sanitize_text_field()`, `absint()`

### Block-First Mandate
Before writing any custom PHP or CSS for UI needs, always check:
1. Does a core block solve this? → Use it
2. Can theme.json handle it? → Use it
3. Is there a block variation or pattern? → Use it
4. Only then: write custom code

See `.agents/profiles/wordpress/_shared/block-reference.md` for the full decision tree.

---

## File Locations

| Purpose                            | Location                                        |
|------------------------------------|-------------------------------------------------|
| Agent role definitions             | `.agents/profiles/wordpress/roles/`             |
| WordPress shared standards         | `.agents/profiles/wordpress/_shared/`           |
| WordPress workflow guides          | `.agents/profiles/wordpress/workflows/`         |
| Core skills (brainstorming, TDD…)  | `.agents/_core/skills/`                         |
| Core shared standards              | `.agents/_core/_shared/`                        |
| Implementation plans               | `plans/`                                        |
| Screenshots & visual artifacts     | `.artifacts/screenshots/`                       |
| Reports & comparison files         | `.artifacts/reports/`                           |

**Agent artifact rule:** All transient files created by agents MUST go to `.artifacts/`. This directory is gitignored. Each agent deletes only the files it created when its task is complete. Writing any `.png`, `.jpeg`, `.jpg`, or `.html` file to the repository root is a policy violation.

**Tool compatibility:**
- `CLAUDE.md` → symlink to `.agents/profiles/wordpress/AGENTS.md` (Claude Code)
- `GEMINI.md` → symlink to `.agents/profiles/wordpress/AGENTS.md` (Gemini CLI)
- `.claude/agents/` → symlinks into `.agents/profiles/wordpress/roles/`

---

## WP AI Mind Plugin

Active plugin. Maintained in a dedicated repository: **[niklas-joh/wp-ai-mind](https://github.com/niklas-joh/wp-ai-mind)**

The plugin is referenced in this blog repo as a git submodule at `wp-content/plugins/wp-ai-mind/`.

| Item | Value |
|------|-------|
| Plugin repo | `https://github.com/niklas-joh/wp-ai-mind` |
| Submodule path | `wp-content/plugins/wp-ai-mind/` |
| Active branches | `main` (stable), `develop` (next release) |
| Namespace | `WP_AI_Mind\` (not `nj_` prefix) |
| Release docs | `RELEASING.md` in plugin repo |

### Plugin Development Workflow

```bash
# Start a session
docker compose up -d && ./bin/db-pull.sh

# Plugin dev — work directly in submodule
cd wp-content/plugins/wp-ai-mind
git checkout develop   # or feature/xxx

# After PHP edits — clear OPcache
docker restart blognjohanssoneu-wordpress-1

# Build React assets
npm run build

# Tests (run from plugin dir)
./vendor/bin/phpunit tests/Unit/ --colors=always
./vendor/bin/phpcs --standard=phpcs.xml.dist
```

### Initialise Submodule (fresh clone)

```bash
git submodule update --init --recursive
```

### Submodule Update (after plugin repo advances)

```bash
git -C wp-content/plugins/wp-ai-mind pull origin main
git add wp-content/plugins/wp-ai-mind
git commit -m "chore: update wp-ai-mind submodule"
```

### Local Admin Credentials (Docker only)
- Login: `nj_agent` / `C8IcqAWJu8F3dOw6E4ndWhIe`
- Container names: `blognjohanssoneu-wordpress-1`, `blognjohanssoneu-db-1`

### Plugin Gotchas
- **OPcache**: Docker serves stale bytecode after PHP edits — restart the container
- **`json_decode($json, true)` converts `{}` → `[]`**: Cast empty arrays to `new \stdClass()` wherever JSON objects are required (tool `properties`, tool `input` fields)
- **`wp_ai_mind_is_pro()`**: Global function in `Core/ProGate.php` — use `\wp_ai_mind_is_pro()` (global prefix) inside namespaced classes
- **Staging deploy**: `git push origin main` in plugin repo, then `ssh siteground-staging "cd .../public_html && git submodule update --remote wp-content/plugins/wp-ai-mind"`



---

## Core Skills

The following skills are available in `.agents/_core/skills/`:

- **brainstorming** — `.agents/_core/skills/brainstorming/SKILL.md`
- **dispatching-parallel-agents** — `.agents/_core/skills/dispatching-parallel-agents/SKILL.md`
- **executing-plans** — `.agents/_core/skills/executing-plans/SKILL.md`
- **finishing-a-development-branch** — `.agents/_core/skills/finishing-a-development-branch/SKILL.md`
- **receiving-code-review** — `.agents/_core/skills/receiving-code-review/SKILL.md`
- **requesting-code-review** — `.agents/_core/skills/requesting-code-review/SKILL.md`
- **subagent-driven-development** — `.agents/_core/skills/subagent-driven-development/SKILL.md`
- **systematic-debugging** — `.agents/_core/skills/systematic-debugging/SKILL.md`
- **test-driven-development** — `.agents/_core/skills/test-driven-development/SKILL.md`
- **using-git-worktrees** — `.agents/_core/skills/using-git-worktrees/SKILL.md`
- **using-superpowers** — `.agents/_core/skills/using-superpowers/SKILL.md`
- **verification-before-completion** — `.agents/_core/skills/verification-before-completion/SKILL.md`
- **writing-plans** — `.agents/_core/skills/writing-plans/SKILL.md`
- **writing-skills** — `.agents/_core/skills/writing-skills/SKILL.md`

To invoke a skill, use the `Skill` tool with the skill name (e.g. `Skill("brainstorming")`).
