# WP AI Mind — Repo-Specific Agent Instructions

> This file adds rules specific to the `niklas-joh/wp-ai-mind` repository.
> It extends (and does not replace) the shared WordPress profile in `CLAUDE.md` → `.agents/profiles/wordpress/AGENTS.md`.

---

## Local Setup (fresh clone)

```bash
npm install   # installs dependencies AND the pre-commit hook via the prepare script
composer install
```

The pre-commit hook (`scripts/pre-commit`) automatically runs `npm run build` and stages
the compiled `assets/` whenever `src/` files are committed. No manual build step needed.

---

## Git & GitHub Workflow

### Branch Protection — Never Commit to `main`

`main` is a protected branch. **All changes must go through a pull request.**

- Always create a feature branch before writing any code:
  ```bash
  git checkout -b feat/short-description   # new feature
  git checkout -b fix/short-description    # bug fix
  git checkout -b chore/short-description  # maintenance
  ```
- Never run `git push origin main` directly.
- Never use `git commit --amend` on commits that have already been pushed to a remote branch.

### Pull Request Rules

- Feature/fix/chore PRs target `develop`. Only hotfixes and `release/vX.Y.Z` branches target `main` directly (see "PR targets" section below).
- PR title must follow Conventional Commits: `feat:`, `fix:`, `chore:`, `docs:`, `refactor:`, `test:`.
- Include a short summary and a test plan in the PR body.
- Request review before merging — do not self-merge without explicit user instruction.

### Commit Message Convention

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<optional scope>): <short summary>

[optional body]
```

Examples:
- `feat(chat): add streaming response support`
- `fix(api): handle missing API key gracefully`
- `chore: bump version to 0.3.0`

---

## Agent Artifacts & Handoff Documents

All transient files created by agents MUST go in `.artifacts/` — this directory is gitignored and never committed.

| Sub-directory | Use |
|---|---|
| `.artifacts/reports/` | Handoff documents, review reports, JSON exports |
| `.artifacts/screenshots/` | Playwright screenshots, visual comparisons |

**Never write handoff docs, plans, or reports to the repository root or any tracked directory.**

Create the directories if missing:
```bash
mkdir -p .artifacts/reports .artifacts/screenshots
```

---

## GitHub Label Permissions

The following label-related MCP tools are explicitly allowed in `.claude/settings.json`:

| Tool | Purpose |
|---|---|
| `mcp__github__create_label` | Create labels like `auto-fix`, `code-review`, `blocking`, `enhancement` when they don't yet exist |
| `mcp__github__update_label` | Update label colour/description if needed |
| `mcp__github__list_labels` | Check which labels already exist before creating |
| `mcp__github__add_labels_to_issue` | Apply `auto-fix` (and others) to issues created by the code-review workflow |
| `mcp__github__remove_labels_from_issue` | Remove labels when triaging or resolving issues |

**`mcp__github__delete_label` is intentionally NOT allowed.**
Label creation was originally blocked by a missing permission (see issue #34).
The solution was to add `create_label` — not `delete_label`. Deleting labels
is destructive (it removes them from all issues/PRs in the repo) and is never
needed by the automated review or auto-fix workflows. The `auto-fix` label
used by `.github/workflows/auto-fix-review-issue.yml` only needs to be
*created* and *applied* — never deleted.

---

## Release Process

See `RELEASING.md` for the full release checklist.
Agents must never trigger a release without explicit user instruction.

---

## Feature-Grouping & Release System

The repo uses an AI-powered semantic tagging system. Understand it before touching branches, tags, or labels.

### Semantic tag schema

| Tag / label prefix | Version bump | PR targets | Meaning |
|---|---|---|---|
| `feat/<slug>` / `feat: <slug>` | minor | `develop` | New feature |
| `feat!/<slug>` / `feat!: <slug>` | **major** | `develop` | Breaking change |
| `fix/<slug>` / `fix: <slug>` | patch | `develop` | Bug fix |
| `hotfix/<slug>` / `hotfix: <slug>` | patch | `main` | Emergency fix bypassing develop |

### How it works

1. Feature/fix PRs target `develop`; hotfix PRs target `main`. All are merged as **merge commits** (not squash).
2. When a PR is opened (or updated), `.github/workflows/tag-infer.yml` calls `claude-haiku-4-5` to infer the best semantic slug, applies a GitHub label (`feat: <slug>` etc.) to the PR, force-updates a git tag (`feat/<slug>`) to the PR head SHA, and posts a comment explaining the choice.
3. On merge, `tag-infer.yml` moves the git tag to the merge commit SHA so `git checkout feat/<slug>` always resolves to the final merged state.
4. Multiple PRs that are part of the same feature receive the **same** semantic label (the AI reuses existing slugs when it detects continuity). This bundles them as a group.
5. The `release-ready` label on **any one PR** in a semantic group signals "include this whole group in the next release."
6. Running the `Build Release Branch` workflow (`workflow_dispatch`) collects all semantic labels (`feat:`, `feat!:`, `fix:`) whose group has a `release-ready` PR, cherry-picks **all** merged PRs in each matching group onto a `release/vX.Y.Z` branch, bumps versions, and opens a PR to `main`.
7. When that PR merges, `tag-release-merge.yml` creates the `vX.Y.Z` tag → `release.yml` builds the zip.

### Agent rules for this system

- **NEVER apply `release-ready` to a PR automatically.** It is a deliberate human release decision. Only apply it when explicitly instructed by the user.
- **NEVER trigger `build-release-branch.yml`** (or any release workflow) without explicit user instruction.
- **NEVER create, move, or delete `feat/*`, `feat!/*`, `fix/*`, or `hotfix/*` git tags manually.** They are managed exclusively by `tag-infer.yml`.
- **NEVER push `v*` tags directly.** Tags are created by `tag-release-merge.yml` on PR merge.
- PRs from agents targeting `develop` should follow the same Conventional Commits convention as all other PRs — this is critical for `semantic-release` to correctly derive the version bump.
- If the AI assigns an incorrect tag, override it by editing the semantic label on the PR — `tag-infer.yml` will retrigger automatically on the `labeled` event.

### Querying release state (for agents)

When the user asks "what features are queued for release?" or similar:

```bash
# List all semantic tags (feat/, fix/, hotfix/)
git tag -l 'feat/*' 'feat!/*' 'fix/*' 'hotfix/*' --sort=version:refname

# List all PRs with a semantic label and their release-ready status
gh pr list --repo niklas-joh/wp-ai-mind --state merged \
  --json number,title,labels,state \
  --jq '.[] | select(.labels[].name | test("^(feat[!]?|fix|hotfix): ")) | {number,title,labels:[.labels[].name]}'

# Check a specific PR
gh pr view <PR_NUMBER> --repo niklas-joh/wp-ai-mind \
  --json number,title,labels,state \
  --jq '{number,title,labels:[.labels[].name],state}'
```

### PR targets

- Feature/fix work: PR targets `develop`
- Hotfixes that must bypass `develop`: PR targets `main` directly (document in `RELEASING.md` emergency section)
- Release branches (`release/vX.Y.Z`): PR targets `main` (created automatically by `build-release-branch.yml`)
