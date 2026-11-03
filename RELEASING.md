# Release Process

## How releases work

This repo uses **trunk-based development** with **semantic-release**. You do not manage
versions, tags, changelogs, or release branches manually — everything is derived automatically
from your commit messages.

### The full flow

```
feature/* ──PR──▶ main
                    │
                    ▼ (batched — see "Triggering a release" below)
             release.yml runs
                    │
           reads conventional commits
           since last tag
                    │
         ┌──────────┴──────────────┐
         │ no feat/fix commits?    │ feat/fix commits?
         ▼                         ▼
       nothing                 semantic-release:
       happens                 1. bumps version (semver)
                               2. writes CHANGELOG.md
                               3. bumps plume.php (×2),
                                  readme.txt, package.json
                               4. builds plugin zip
                               5. commits "[skip ci]"
                               6. creates vX.Y.Z tag
                               7. publishes GitHub Release
                                  with zip attached
```

**You never touch versions, tags, or changelogs.**

---

## Triggering a release

Releases are **not triggered automatically on every merge to `main`**. Instead:

| Trigger | When | How |
|---|---|---|
| Scheduled | Every Friday at 10:00 UTC | Automatic — bundles the week's merged PRs |
| Manual | Any time | Actions tab → **Release** → **Run workflow** |

The scheduled run exits cleanly if there are no releasable commits since the last tag —
no release is created and no version is bumped.

### Out-of-band (hotfix) release

If a critical fix needs to ship before Friday:

1. Merge the `fix(scope):` PR to `main` as normal.
2. Go to **Actions → Release → Run workflow** and click **Run workflow**.
3. Semantic-release reads all commits since the last tag (including the hotfix) and cuts
   a patch release.

---

## Commit message convention

Commit messages (and PR titles, which become the squash-merge commit) control versioning:

| Prefix | Example | Version bump |
|---|---|---|
| `fix(scope):` | `fix(api): handle rate limit errors` | patch `0.2.1` |
| `feat(scope):` | `feat(chat): add streaming support` | minor `0.3.0` |
| `feat!:` or `BREAKING CHANGE:` | `feat!: remove legacy endpoint` | major `1.0.0` |
| `chore:` / `docs:` / `refactor:` / `test:` | `chore(deps): update composer` | no release |

The scope is required (enforced by commitlint in CI).

---

## Multi-branch features

When a feature spans multiple branches, use an **umbrella branch**:

```
main
  └── feature/ai-streaming          ← umbrella; never merges to main until done
        ├── feature/ai-streaming-backend  → PR → feature/ai-streaming
        └── fix/streaming-timeout         → PR → feature/ai-streaming
```

When the whole feature is ready, open one PR from `feature/ai-streaming` → `main`
with a conventional PR title (`feat(chat): add streaming response support`).
The squash-merge produces one clean commit on main.

---

## Hotfixes

Branch off `main` directly as `fix/short-description`. PR to `main` with a `fix(scope):` title.
Semantic-release will cut a patch release automatically on merge.

---

## WP.org SVN submission

Stable release deployment to WordPress.org is automated via `wporg-deploy.yml`.
It fires automatically on every stable (non-pre-release) GitHub Release.

Pre-release tags (`v0.3.0-beta.1`) publish a GitHub Release marked as pre-release
and are **not** deployed to WordPress.org.

### First-time setup

The plugin was approved on WordPress.org on 19 October 2026 (slug `plume`,
SVN `https://plugins.svn.wordpress.org/plume`, committer `niklasjohansson`).

Add these two repository secrets (Settings → Secrets and variables → Actions)
**before** the first release is cut:

| Secret | Value |
|---|---|
| `WPORG_USERNAME` | `niklasjohansson` |
| `WPORG_PASSWORD` | Your **SVN password**: profiles.wordpress.org → Edit Profile → Account & Security → SVN credentials. Your login password and application passwords do not work for SVN. |

### First WordPress.org release (2.0.0)

1. Add the two secrets above.
2. Merge the launch PR. Its squash commit must start with `feat(wporg)!:` (the `!`
   marks a breaking change, which bumps 1.x to 2.0.0). Check the commit message
   in GitHub's merge dialog before confirming.
3. Run **Actions → Release → Run workflow** (or wait for the Friday run).
4. The `v2.0.0` GitHub Release triggers **WordPress.org Deploy**, which commits
   the zip to `trunk/`, copies it to `tags/2.0.0/` and uploads `wp-org-assets/`
   to SVN `assets/`.
5. Check https://wordpress.org/plugins/plume/. Search results can take up to
   72 hours to update.

If the deploy job fails (for example, missing secrets), fix the cause and re-run
**WordPress.org Deploy** with tag `v2.0.0`. Re-running is safe.

### readme.txt and changelog

`readme.txt` controls the public plugin page. semantic-release sets `Stable tag`
and replaces `= NEXT_VERSION =` headings in the Changelog and Upgrade Notice
sections with the real version. Before a release, add user-facing notes under a
`= NEXT_VERSION =` heading. Plugin page images live in `wp-org-assets/`
(icons, banners, `screenshot-N.png` matching the readme's Screenshots captions)
and are synced on every deploy.

### Manual deploy / testing

You can trigger a deployment manually via the Actions tab:
select **WordPress.org Deploy** → **Run workflow** → supply the release tag (e.g. `v1.3.3`).

---

## Workflows in use

| File | Trigger | Purpose |
|---|---|---|
| `ci.yml` | PR to `main` | PHPCS, PHPUnit, JS lint, commitlint |
| `release.yml` | Weekly schedule (Fri 10:00 UTC) or `workflow_dispatch` | Full semantic-release pipeline |
| `wporg-deploy.yml` | Stable release published | Deploy ZIP + `wp-org-assets/` to WordPress.org SVN |
| `claude-code-review.yml` | PR opened/updated | Automated code review |
| `auto-fix-ci.yml` | CI failure | Auto-fix lint issues |
| `auto-fix-review-issue.yml` | Issue labelled | Auto-fix code review issues |
| `claude.yml` | Manual / comment | Claude Code agent tasks |

## Deleted workflows (no longer needed)

The following were removed as part of the trunk-based migration:

- `build-release-branch.yml` — release branches no longer exist
- `tag-feature-merge.yml` — `merged/*` tags no longer exist
- `tag-release-merge.yml` — semantic-release creates tags directly
- `backfill-merged-tags.yml` — one-time utility, removed
- `backfill-semantic-tags.yml` — one-time utility, removed