# WordPress Development Profile — AI Agent Instructions (Plume)

> This is the WordPress development profile for the `plume-ai` plugin repo.
> Core skills (brainstorming, TDD, debugging, git worktrees, etc.) are in `.agents/_core/skills/`.
> Repo-specific git/PR/release rules are in `.claude/CLAUDE.md` — this file covers the
> development pipeline and environment topology.
>
> Plume has no standalone WordPress site of its own. Its real-world test surface is a
> **companion repo**, `blog.njohansson.eu` (`~/Documents/Homepages/blog.njohansson.eu`),
> which runs this plugin locally via a direct Docker bind-mount. That repo has its own
> CLAUDE.md/AGENTS.md for its own site-level concerns (theme, content, Siteground
> deploys) — don't conflate the two; this file only covers what's relevant to
> *developing the plugin*.

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
5. wordpress-reviewer            → review against wp-env / the companion blog install
       ↓ FAIL → back to step 4 with reviewer findings
       ↓ PASS
6. git push + PR                 → per .claude/CLAUDE.md's branch/PR rules
7. Merge → semantic-release      → automatic; see .claude/CLAUDE.md "Release Process"
```

Role definitions: `.claude/agents/{task-orchestrator,wordpress-planner,wordpress-standards-validator,wordpress-coder,wordpress-reviewer}.md`.

**Shortcuts are not permitted:**
- Never skip the planner because a task "seems small"
- Never skip the validator because a plan "looks fine"
- Never write a single line of production code before human approval
- Never merge without a reviewer sign-off

---

## Local development environments (two — don't confuse them)

| Environment | Location | Proxy target | Use |
|---|---|---|---|
| `wp-env` (this repo) | `localhost:8888` | Production Worker by default; override via `.wp-env.override.json` (gitignored) — see `CONTRIBUTING.md` | Plugin unit/integration tests, quick manual checks, isolated from the blog |
| `blog.njohansson.eu` Docker (companion repo) | `localhost:8080` | `https://plume-proxy-dev.plumewp.workers.dev` (deployed dev Worker, hardcoded in that repo's `docker-compose.yml`) | Full-site testing as actually installed. That repo bind-mounts this one directly (`../wp-ai-mind:/var/www/html/wp-content/plugins/wp-ai-mind` in its `docker-compose.yml`) — edits here are live there immediately, no git/submodule step needed for local iteration. How it reaches `staging4.blog.njohansson.eu` / `blog.njohansson.eu` production is owned by that repo, not documented here. |

See `CONTRIBUTING.md` → "Pointing wp-env at a local proxy Worker" for full `wp-env` + `wrangler dev` setup steps.

### Gotchas (discovered debugging PR #943's billing)

- **`wrangler dev --remote` (or a real deploy) can never pass WP site-registration
  against `wp-env`.** Registration requires the Worker to call back to
  `http://localhost:8888/wp-json/plume/v1/activation-verify` — Cloudflare's edge (or
  `--remote` execution mode) cannot reach a laptop's `localhost`. Use plain
  `wrangler dev --env dev` (fully local execution) from `plume-proxy/` when you need
  registration to succeed.
- **Testing against the companion `blog.njohansson.eu` install (`localhost:8080`)
  requires a real deploy**, not a local `wrangler dev` instance — its `PLUME_PROXY_URL`
  points at the deployed `plume-proxy-dev` Worker. Use `npm run deploy:dev` (in
  `plume-proxy/`) to push Worker changes there, and `npx wrangler tail --env dev` to
  watch live logs. That Worker is shared with `staging4.blog.njohansson.eu` too — a
  debug deploy there affects both.

---

## Coding Conventions

- **Namespace**: `Plume\` (not a function-prefix convention — this is namespaced PHP)
- **Text domain**: `plume`
- Use British English in content and comments
- All user-facing strings translatable (`__()`, `_e()`, `esc_html__()`, etc.)
- Prevent direct file access: `if ( ! defined( 'ABSPATH' ) ) { exit; }`
- Escape output (`esc_html()`, `esc_attr()`, `esc_url()`), sanitise input (`sanitize_text_field()`, `absint()`)
- Follow WordPress PHP Coding Standards (`composer run phpcs` / `phpstan`)

### Block-First Mandate (for any admin UI work)
1. Does a core block solve this? → Use it
2. Can `theme.json`/block styles handle it? → Use it
3. Only then: write custom PHP/CSS

### Code Documentation
- PHP: PHPDoc on all public methods/classes, `@since NEXT_VERSION` for new code (see `.claude/CLAUDE.md`)
- JS/React: JSDoc on exported components, `@param {Object} props` per prop, `@example` on shared components
- Inline comments: "why", never "what" — only when the reason is non-obvious

---

## Agent Artifacts

All transient files (screenshots, reports, handoff docs) go in `.artifacts/` (gitignored).
See `.claude/CLAUDE.md` → "Agent Artifacts & Handoff Documents" for sub-directories and naming.
Never write these to the repo root.

---

## Core Skills

Available in `.agents/_core/skills/`: brainstorming, dispatching-parallel-agents,
executing-plans, finishing-a-development-branch, receiving-code-review,
requesting-code-review, subagent-driven-development, systematic-debugging,
test-driven-development, using-git-worktrees, using-superpowers,
verification-before-completion, writing-plans, writing-skills.

Invoke via the `Skill` tool.
