---
name: issue-triage
description: Triage all open GitHub issues, classify by effort and priority, group into atomic PRs, draft a concrete implementation plan per group, run a dual parallel review of that plan, present it for approval, then implement. Never begins implementation without explicit user approval.
---

# Issue triage and implementation

## Invoke on startup

Before anything else, invoke the following skills to load them into context:

1. `/using-superpowers` — loads available skills (main session only, not in subagents)
2. `/subagent-driven-development` — implementation loop used in Phase 7
3. `/dispatching-parallel-agents` — parallel review pattern used in Phase 5

---

## Phase 1 — Discovery and filtering (read-only, no edits)

Read project context before touching any issue:

```bash
cat CLAUDE.md
cat README.md
cat package.json 2>/dev/null || cat composer.json 2>/dev/null
```

### Step 1a — Fetch open issues

```bash
gh issue list --state open --json number,title,labels,body,comments --limit 200
```

### Step 1b — Fetch open PRs and extract linked issues

```bash
gh pr list --state open --json number,title,body,headRefName
```

Parse every `Closes #N` / `Fixes #N` reference from PR bodies. Any issue number found here is **already covered** — mark it `SKIP (PR #N)` and exclude it from classification entirely.

### Step 1c — Read the deferred state file

```bash
cat .artifacts/reports/triage-deferred.md 2>/dev/null
```

If the file exists, it contains issues deferred in previous runs with a timestamp and the decision question. For each deferred issue:

```bash
gh issue view <n> --json updatedAt,comments
```

Compare `updatedAt` against the `deferred_at` timestamp in the file.

- **No new activity** → mark `SKIP (deferred, no update)` and exclude from classification
- **New activity** → read all comments posted after `deferred_at` and evaluate:
  - **Noise** (label change, bot comment, automated status update, no human text) → mark `SKIP (deferred, noise)` and continue skipping
  - **Decision made** (a direct answer to the deferred question, a chosen approach, an explicit instruction) → re-classify normally and proceed to implementation planning
  - **New information or partial clarification** (adds context but does not fully resolve the question) → re-evaluate whether the issue can now be planned. If yes, re-classify. If the decision question is still open, update the deferred state file with the new context and post a follow-up comment asking the remaining clarifying question
  - **Unrelated comment** (off-topic, acknowledgement only) → mark `SKIP (deferred, no update)` and continue skipping

**If the deferred state file does not exist or is empty**, reconstruct the deferred list from GitHub using the `comments` data already loaded in Phase 1a — do not make additional API calls. For each open issue in the candidate set:

- If it has at least one comment whose body starts with `**Nightly triage` AND no owner/member comment was posted after the last such triage comment → treat as `SKIP (deferred, no update)` and exclude from the working set.
- If it has a triage comment followed by an owner/member reply → treat as `Deferred re-evaluated (new activity)` and re-classify normally.

This makes GitHub issue comments the authoritative source of deferred state. The local `.artifacts/reports/triage-deferred.md` file is a performance cache — the run is correct with or without it.

### Step 1d — Check recent commits

```bash
git log --oneline --since="60 days ago" | head -60
```

### Step 1e — Build the working set

The working set for this run = all open issues **minus** SKIP (PR) **minus** SKIP (deferred, no update).

Output a pre-classification filter summary before proceeding:

```
## Filter summary
- Total open issues: 24
- Skipped (covered by open PR): #663 (PR #670), #668 (PR #676), #666 (PR #673)
- Skipped (deferred, no update): #7, #18
- Deferred re-evaluated (new activity): #22
- Working set: 17 issues
```

---

## Phase 2 — Classification

Classify each issue in the working set across two axes.

**Effort:**

| Level | Signal |
|---|---|
| Quick win | Single file, no logic change, under 30 min |
| Low | 1–2 files, well-scoped change, clear acceptance criteria |
| Medium | Multiple files or moderate risk, needs a test |
| High | Architectural impact, unclear scope, or strategic dependency |

**Priority:**

| Level | Signal |
|---|---|
| P0 | Blocks WP plugin directory approval (coding standards, security, licensing) |
| P1 | User-facing bug or broken feature |
| P2 | Developer experience or code quality |
| P3 | Enhancement or nice-to-have |

**Strategic flag:** Issues that cannot be resolved without an architectural or product decision get a `DEFER` flag and a one-line note describing the decision needed. Do not stop the run. Continue with everything else; DEFER items are written to the deferred state file at the end of the run.

When an issue is marked DEFER, check whether a triage comment has already been posted **using the `comments` data already fetched in Phase 1a** — do not make another API call.

A triage comment is any comment whose body starts with `**Nightly triage`.

Post a new DEFER comment only when **at least one** of these is true:
- No triage comment exists on the issue yet.
- The last triage comment was followed by an owner/member reply (i.e. the human responded, but the question is still not fully resolved — a follow-up clarification is warranted).

**Do not post** if the most recent comment on the issue is itself a triage comment with no owner/member reply since — the issue is already awaiting a decision and another comment is noise.

When posting is warranted:

> **Sentinel:** The opening line `**Nightly triage` is the string both Phase 1c and Phase 2 use to identify previously-posted triage comments. If you change the header below, update both detection checks to match.

```bash
gh issue comment <n> --body "**Nightly triage — decision needed**

This issue was reviewed in the automated nightly triage run but cannot be resolved without a decision.

**Question:** <one-line decision question>

Reply to this comment with your decision and the next nightly run will pick it up automatically."
```

> _Detection sentinel: comments starting with `**Nightly triage` are treated as triage comments. Update both Phase 1c and Phase 2 if this prefix ever changes._

A follow-up comment (not a duplicate) is only posted when Phase 1c re-evaluation finds new information that narrows but does not fully resolve the question.

Output the prioritisation matrix:

```
| # | Title | Effort | Priority | Group | Defer? | Rationale |
|---|-------|--------|----------|-------|--------|-----------|
| 12 | Missing nonce check on settings save | Quick win | P0 | A | — | Single wp_verify_nonce call needed |
| 7  | Caching strategy for AI responses | High | P1 | — | DEFER | Needs decision: transient vs object cache |
```

---

## Phase 3 — Grouping

Group non-DEFER issues into PRs where issues share:
- The same file or module (e.g. all issues touching `class-ai-router.php`)
- The same feature type (e.g. all REST API validation issues)
- A logical dependency (issue B cannot be fixed without issue A)

Label each group A, B, C… and order by: P0 quick wins → P0 medium → P1 quick wins → P1 medium → P2/P3. High-effort P3 issues go last.

DEFER issues are listed separately and excluded from implementation.

---

## Phase 4 — Implementation planning (the HOW)

For each group, produce a concrete implementation plan before any review or code is written. This is the "how", not the "what" — the grouping matrix above is the "what".

For each group, document:

1. **Files to modify** — exact file paths, no guessing
2. **Change per file** — what function/hook/class is touched and what changes
3. **Conventional commit type and scope** — e.g. `fix(rest)`, `chore(build)`, `feat(admin)`, `test(proxy)`, `docs(readme)`
4. **Acceptance criteria** — how to verify the issue is resolved (not just "it's fixed")
5. **WP compliance checklist** — for every change, explicitly confirm presence of:
   - Output escaping (`esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()` as appropriate)
   - Input sanitisation (`sanitize_text_field()`, `absint()`, etc.)
   - Nonce verification where user input is processed
   - Capability check before privileged actions
   - `defined('ABSPATH') || exit;` at the top of any directly-accessible PHP file
6. **Design principles self-check** — explicitly answer each before finalising the plan for a group:
   - **DRY**: Does this plan duplicate logic already present in the codebase? If yes, extract or reuse instead.
   - **SRP**: Does each function or class being added or modified have a single, clear responsibility? If a change causes a class to own two concerns, split it.
   - **KISS**: Is the proposed implementation the simplest thing that could work? If an abstraction or indirection is introduced that current requirements do not justify, remove it.
   - **YAGNI**: Does the plan include anything not required by the stated issue? If yes, cut it.
   - Record the verdict for each (pass / flagged + what was adjusted) in the plan block.
7. **Test changes** — what tests need to be added or updated
8. **Risk** — what could break and how to detect it

Format per group:

```
### Group A — REST API nonce validation
Commit type: fix(rest)
Branch: fix/rest/nonce-validation

Files:
- includes/class-rest-api.php
  - Add wp_verify_nonce() to handle_settings_save() before processing $_POST
  - Add current_user_can('manage_options') check above nonce check
WP compliance:
  - [ ] Escaping: esc_attr() on output in response array (line ~84)
  - [ ] Sanitisation: sanitize_text_field() already present ✓
  - [ ] Nonce: wp_verify_nonce() — THIS is the fix
  - [ ] Capability: current_user_can() — THIS is the fix
  - [ ] ABSPATH guard: already present ✓
Design principles:
  - DRY: pass — nonce helper is not duplicated, uses wp_verify_nonce() directly
  - SRP: pass — change stays within the existing handler method
  - KISS: pass — two lines added, no new abstraction
  - YAGNI: pass — no scope beyond what the issue requires
Acceptance criteria:
  - Unauthenticated POST to endpoint returns 403
  - Valid nonce + capability allows save and returns 200
Tests: Update test-rest-api.php to assert 403 on missing nonce
Risk: None — purely additive security check
Closes: #12
```

Produce this plan block for every group, then write the complete plan to disk before proceeding to Phase 5:

```bash
mkdir -p .artifacts/reports
PLAN_FILE=".artifacts/reports/triage-plan-$(date +%Y-%m-%d).md"
# Write the full Phase 4 output (all group blocks) into $PLAN_FILE
```

Pass `$PLAN_FILE` as a path to both Phase 5 subagents. Do not inline the plan content — subagents read the file directly from disk.

---

## Phase 5 — Dual parallel review of the implementation plan

Dispatch two reviewer subagents **in parallel** using `/dispatching-parallel-agents`. Both receive the full implementation plan from Phase 4. Neither touches any code. Do not instruct the subagents on what to check — their skills define that. Give them context only.

### Reviewer 1 — Code quality and plan integrity (/requesting-code-review)

Dispatch using the `/requesting-code-review` skill. Provide:
- `WHAT_WAS_IMPLEMENTED`: Path to `$PLAN_FILE` — the subagent reads it from disk
- `PLAN_OR_REQUIREMENTS`: The issue numbers being addressed (e.g. `#12, #14, #22`) — the subagent fetches bodies via `gh issue view <n>`
- `BASE_SHA` / `HEAD_SHA`: Not applicable — note this is a pre-implementation plan review, no code has been written yet
- `DESCRIPTION`: A brief summary of the plugin and the goal of this triage run

### Reviewer 2 — WordPress standards validation (wordpress-standards-validator subagent)

Dispatch using the `wordpress-standards-validator` role. Provide:
- Path to `$PLAN_FILE` — the subagent reads it from disk
- The issue numbers being addressed — the subagent fetches bodies via `gh issue view <n>`
- Path to `CLAUDE.md` — the subagent reads it directly from disk

### Integrate findings

Apply any `NEEDS REVISION` findings from either reviewer. Reorder or revise plans as needed. Record the verdict from each reviewer (LGTM / NEEDS REVISION + summary of changes made) to include in the user-facing presentation.

---

## Phase 6 — Present the plan for approval

Present the final implementation plan to the user. Always. In every mode — interactive or nightly scheduled.

Include:
- The filter summary (Phase 1e)
- The prioritisation matrix (Phase 2/3)
- The per-group implementation plans (Phase 4, post-review revisions)
- Reviewer 1 verdict and adjustments made
- Reviewer 2 verdict and adjustments made
- The DEFER list with one-line rationale per item

**Do not begin Phase 7 until the user explicitly approves.** In nightly mode, pause and await a response before proceeding. This is non-negotiable.

---

## Phase 7 — Implementation loop (subagent-driven, sequential)

Follow the `/subagent-driven-development` pattern. Groups are worked **sequentially** — not in parallel — because groups may touch overlapping files and review gates must pass before the next group begins.

For each group, in order:

### 1. Branch

```bash
# Branch name mirrors the commit type and scope from the plan
git checkout -b <type>/<scope>/<short-description>
# e.g. fix/rest/nonce-validation
#      chore/build/remove-debug-output
#      feat/admin/usage-dashboard
#      test/proxy/rate-limit-coverage
#      docs/readme/update-requirements
```

### 2. Dispatch implementer subagent

Provide the subagent with — do not make the subagent read files itself:
- The full text of the relevant issue bodies
- The exact implementation plan for this group (from Phase 4)
- The relevant sections of CLAUDE.md
- The exact file paths to touch

The subagent implements, writes or updates tests, self-reviews, and returns.

### 3. Verify locally

```bash
# Use the commands defined in CLAUDE.md / package.json
npm run lint 2>&1 | tail -20
npm test 2>&1 | tail -30
```

Do not proceed to commit if either fails.

### 4. WP compliance verification (applies to every change, not just P0)

Before committing, verify that every modified PHP file explicitly contains — do not assume:

- Correct escaping function on every output (`esc_html()`, `esc_attr()`, `esc_url()`, `esc_textarea()`)
- Sanitisation on every user input
- Nonce verification before processing any form/AJAX/REST payload
- Capability check before any privileged action
- `defined('ABSPATH') || exit;` at the top of any directly-accessible PHP file

This checklist is not optional and is not scoped to P0 issues. It applies to every implementation.

### 5. Atomic commits

One logical change per commit. Conventional Commits format with scope:

```
<type>(<scope>): <imperative description> (closes #<issue>)
```

Valid types: `fix`, `feat`, `chore`, `docs`, `test`, `refactor`, `perf`, `style`

Valid scopes mirror the plugin's module structure: `rest`, `proxy`, `admin`, `core`, `tiers`, `payments`, `worker`, `tests`, `build`

Examples:
```
fix(rest): add nonce verification to settings endpoint (closes #14)
fix(proxy): sanitise rate-limit header before output (closes #22, #23)
chore(build): remove console.log statements left from debugging (closes #31)
feat(admin): add usage dashboard widget (closes #8)
test(proxy): add coverage for rate-limit edge cases (closes #19)
```

### 6. Spec compliance and design principles review

Before the code quality review, verify the implementation against the Phase 4 plan:

- **Spec compliance**: Does the implementation match the issue requirements and Phase 4 plan exactly? No more, no less.
- **YAGNI**: Is anything present in the diff that was not in the plan or required by the issue?
- **DRY**: Has any logic been duplicated that already exists elsewhere in the codebase?
- **SRP**: Do any modified functions or classes now own more than one concern as a result of this change?
- **KISS**: Has any unnecessary abstraction or indirection been introduced?

If any check fails, send back to the implementer subagent to correct before proceeding to code quality review.

### 7. Code quality review (/requesting-code-review)

Dispatch a code-reviewer subagent with the actual `BASE_SHA` and `HEAD_SHA` for this group's commits. Fix Critical and Important findings before proceeding to the next group.

### 8. Push and open PR

```bash
git push -u origin <type>/<scope>/<short-description>
gh pr create \
  --title "<type>(<scope>): <description>" \
  --body "Closes #N, #N.

## Summary
<brief summary of what was fixed and how>

## WP compliance verified
- [ ] Escaping
- [ ] Sanitisation
- [ ] Nonce verification
- [ ] Capability checks
- [ ] ABSPATH guards"
```

### 9. Report and continue

Print a one-line status per resolved issue, then move to the next group.

If a fix reveals a new issue mid-implementation, open a GitHub issue for it and include it in the next nightly run. Do not expand the current group's scope.

---

## Triage plan file

The plan written to `.artifacts/reports/triage-plan-<date>.md` in Phase 4 is an auditable artefact following the project's existing file hygiene conventions (see `.agents/_shared/file-hygiene.md`). It is intentionally kept after the run so you can inspect what was planned vs. what was implemented. Delete it when no longer needed — it has no runtime role.

---

## Constraints

- Never combine issues from different modules in one commit.
- If a fix requires touching the Cloudflare Worker (`src/cloudflare-worker/`), flag it in the PR description — it requires a manual `wrangler deploy` step that cannot be automated.
- Do not guess on ambiguous issues. Mark as DEFER with the question needed.

---

## End-of-run report

Output as tables, then update the deferred state file.

```
## Issue triage run — <YYYY-MM-DD>

### Filter summary
| Status | Issues |
|--------|--------|
| Skipped — covered by open PR | #663 (PR #670), #668 (PR #676) |
| Skipped — deferred, no update | #7, #18 |
| Re-evaluated — deferred with new activity | #22 |
| Working set | 17 issues |

### Resolved
| # | Title | Type | PR | How it was fixed |
|---|-------|------|----|-----------------|
| 14 | Missing nonce on settings save | ✅ fix(rest) | #42 | Added wp_verify_nonce() and current_user_can() before $_POST processing |
| 22 | Unsanitised rate-limit header | ✅ fix(proxy) | #43 | Wrapped header value in sanitize_text_field() before output |
| 31 | Debug console.log in production | ✅ chore(build) | #43 | Removed three leftover console.log calls from class-proxy.php |

### Deferred (awaiting decision)
| # | Title | Decision needed | Deferred since |
|---|-------|----------------|----------------|
| 7  | AI response caching | Choose between transient and object cache — impacts multisite behaviour | 2026-06-07 |
| 18 | Tier upgrade flow | Confirm whether upgrade redirects to Stripe or stays in-plugin | 2026-05-31 |

### Cloudflare Worker changes (manual deploy required)
| PR | Title |
|----|-------|
| #44 | fix(worker): sanitise forwarded headers |

### Review verdicts
| Reviewer | Verdict | Adjustments made |
|----------|---------|-----------------|
| Code quality | LGTM | Reordered Group C after Group B to avoid merge conflict on class-proxy.php |
| WP standards | NEEDS REVISION → LGTM | Added missing esc_attr() to Group A plan for response array output |
```

After outputting the report, update the deferred state file:

```bash
mkdir -p .artifacts/reports
cat > .artifacts/reports/triage-deferred.md << 'EOF'
# Triage deferred issues
# Performance cache only — GitHub issue comments are the authoritative source.
# If this file is deleted, Phase 1c reconstructs state from GitHub automatically.
# Do not edit manually.
# Format: issue number | deferred_at (ISO date) | decision needed

7 | 2026-06-07 | Choose between transient and object cache — impacts multisite behaviour
18 | 2026-05-31 | Confirm whether upgrade redirects to Stripe or stays in-plugin
EOF
```

Write only issues that remain unresolved and deferred. Remove any issue from this file that was resolved, covered by a PR, or re-evaluated and actioned in this run. This file accelerates Phase 1c skip logic — if it is absent, Phase 1c reconstructs the same state from GitHub comments.
