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

- **All PRs target `main` directly.** There is no `develop` branch.
- PR title **must** follow Conventional Commits — CI enforces this via `commitlint`:
  `feat(scope):`, `fix(scope):`, `chore(scope):`, `docs(scope):`, `refactor(scope):`, `test(scope):`
  Scope is strongly recommended (CI warns if absent). A PR title that is not a valid
  conventional commit will cause the "Commit convention (PR title)" check to fail.
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

Releases are fully automated via `semantic-release`. Agents must never trigger a release
without explicit user instruction.

### How it works

1. All PRs target `main` directly and are merged as **squash merges**.
   The squash commit message = the PR title, which must be a valid Conventional Commit.
2. Every push to `main` triggers `release.yml`, which runs `npx semantic-release`.
3. `semantic-release` reads commit messages since the last `vX.Y.Z` tag to determine
   whether a release is warranted and what version bump to apply:
   - `feat(scope):` → minor bump
   - `fix(scope):` / `perf(scope):` → patch bump
   - Breaking change (`!` or `BREAKING CHANGE:`) → major bump
   - `chore:`, `docs:`, `test:`, `refactor:`, `style:`, `build:`, `ci:` → **no release**
4. If a release is warranted, `semantic-release` automatically:
   - Updates the version in `wp-ai-mind.php` (header + constant), `readme.txt`, `package.json`
   - Writes `CHANGELOG.md`
   - Commits those changes back with `[skip ci]` to prevent an infinite loop
   - Creates a `vX.Y.Z` tag
   - Builds a plugin zip into `dist/`
   - Publishes a GitHub Release with the zip attached

### Agent rules

- **NEVER bump versions manually** (no `sed`, no `npm version`, no hand-editing version strings).
  `semantic-release` owns all version state.
- **NEVER push `v*` tags directly.** Tags are created by `semantic-release` on merge to `main`.
- **NEVER trigger `release.yml`** or any release workflow without explicit user instruction.
- The `[skip ci]` commit that `semantic-release` pushes back must never be amended or force-pushed.

### PR targets

- All work (features, fixes, chores): PR targets `main`
- There is no `develop` branch
