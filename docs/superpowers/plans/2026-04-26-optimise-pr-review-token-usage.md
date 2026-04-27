# PR Review Token Optimisation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce Claude Code token consumption across the automated PR review → issue creation → auto-fix pipeline by ~45–60 % without removing any functionality or weakening code quality.

**Architecture:** The dominant saving comes from collapsing N sequential auto-fix sessions (one per issue) into a single batch session per PR. Secondary savings come from tuning max-turns per severity tier, enriching issue bodies so fix sessions need fewer reads, and trimming unnecessary setup steps.

**Tech Stack:** GitHub Actions, `anthropics/claude-code-action` v1 (OAuth token / Claude.ai subscription), `gh` CLI, bash, YAML.

---

## Background — Current System

```
PR opened/synchronised
        │
        ▼
claude-code-review.yml  (1 session)
  ├─ reads diff + source files
  ├─ creates N issues (blocking / enhancement / nits)
  └─ applies "auto-fix" label to each issue via PAT
        │
        ├─ label event → auto-fix-review-issue.yml  (session 1)
        ├─ label event → auto-fix-review-issue.yml  (session 2)
        ├─ label event → auto-fix-review-issue.yml  (session 3)
        └─ label event → auto-fix-review-issue.yml  (session N)
```

Each auto-fix session performs a **full cold start**: `actions/checkout`, `npm ci`, `composer install`, CLAUDE.md load, source file reads — even for a one-line PHPDoc fix. The first 10–20 turns of every session duplicate the setup work of the previous session.

---

## Bottleneck Analysis

| # | Bottleneck | Estimated Token Cost | Frequency |
|---|------------|----------------------|-----------|
| B1 | N separate `claude-code-action` cold starts per PR | ~12–18k tokens × (N−1) | Every PR |
| B2 | `npm ci` + `composer install` run N times for N issues | No token cost, but ~90–120 s runner time per session | Every PR |
| B3 | CLAUDE.md re-loaded into context every session (~4–6k tokens) | ~4–6k tokens × N | Every PR |
| B4 | Fix sessions re-read source files already read by the review session | ~5–15k tokens per session for file traversal | Every fix |
| B5 | `--max-turns 25` on all issue types regardless of complexity | Up to 25 turns for a 3-line PHPDoc addition | Nit/enhancement issues |
| B6 | `fetch-depth: 0` on auto-fix runner (full git history) | No direct token cost but slow | Every fix |
| B7 | Review prompt creates issues then posts PR comment — two large tool-use turns | Minor | Every PR |

### Data from recent PRs

| PR | Files changed | Issues created | Auto-fix sessions | Approx sessions wasted |
|----|---------------|----------------|-------------------|-------------------------|
| #273 | 1 | 2 (1 enhancement + 1 nits) | 2 | 1 |
| #270 | ~8 | 4 | 4 | 3 |
| #271 | ~6 | 4 | 4 | 3 |
| #272 | ~5 | 3 | 3 | 2 |
| #269 | ~4 | 2 | 2 | 1 |
| #254 | ~10 | 4 | 4 | 3 |

Average: **3.2 excess sessions per PR**. With ~30–50k tokens per session, that is **96–160k tokens wasted per PR** before any fixes are written.

---

## Proposed Improvements (prioritised)

### P1 — Batch Auto-Fix: One Session per PR instead of N  ★★★ (Highest Impact)

**Replace the per-issue `auto-fix` label trigger with a single `repository_dispatch` event.**

Instead of labelling each issue individually (which fires N separate webhook events → N runners → N cold-start sessions), the review workflow fires **one `repository_dispatch`** event carrying all issue numbers as JSON. A new `batch-auto-fix.yml` workflow handles the dispatch, runs **one Claude session** that fixes all issues in sequence.

Benefits:
- Saves (N−1) full session cold-starts per PR
- Saves (N−1) `npm ci` + `composer install` runs (~90 s each)
- All fixes share the same warm file-system cache in one runner
- Issue tracking preserved (separate `closes #N` commits, individual issue close comments)

Tradeoffs:
- One complex session instead of N simple sessions → slightly higher risk of early failure
  - Mitigated: prompt instructs Claude to continue to next issue even if one fix is skipped
- If the batch session hits max-turns, some issues may not be fixed
  - Mitigated: max-turns set proportionally to the number of issues

### P2 — Tiered `--max-turns` by Issue Severity  ★★ (Medium Impact)

| Label | Current max-turns | Proposed | Rationale |
|-------|------------------|----------|-----------|
| `nits` | 25 | 8 | Nit fixes are 1–5 line changes; 8 turns is generous |
| `enhancement` | 25 | 15 | Moderate complexity |
| `blocking` | 25 | 22 | Keep headroom for complex logic fixes |
| Batch (all issues) | 25 | `(num_issues × 8) + 10` | Scale with workload |

### P3 — Embed Source Snippets in Issue Bodies  ★★ (Medium Impact)

The review session already read every relevant file. Currently it writes a description + fix suggestion in the issue body, but not the surrounding code. Auto-fix sessions must re-read those files to locate the exact lines.

Add a **"Context" section to each issue body** with the relevant code excerpt (≤ 30 lines). The fix session can then skip the file-location phase and jump directly to editing.

### P4 — Shallow Clone on Open PRs  ★ (Low Impact, Free Win)

For open PRs, `fetch-depth: 0` (full history) is not needed — we just push new commits to the existing branch. Changing to `fetch-depth: 1` when the PR is open saves clone time and some I/O, freeing runner resources for the Claude session sooner.

### P5 — Skip npm ci on PHP-only fixes  ★ (Low Impact, Worth Having)

Auto-fix currently runs `npm ci` unconditionally (~40–60 s). For PHP-only fixes (PHPDoc, test stubs, PHP logic), JS assets don't need to be built. Add a label-based detection: if the issue title/labels suggest a PHP-only fix, skip `npm ci`.

This is heuristic and approximate, so it is a lower priority. In the batch approach, npm ci runs once anyway, making this moot.

---

## Files to Create / Modify

| File | Action | Purpose |
|------|--------|---------|
| `.github/workflows/claude-code-review.yml` | Modify | Replace per-issue label loop with single `repository_dispatch` |
| `.github/workflows/auto-fix-review-issue.yml` | Modify | Lower to only handle manual re-triggers (fallback) |
| `.github/workflows/batch-auto-fix.yml` | Create | New: one Claude session per `repository_dispatch` event |
| `.github/workflows/auto-fix-ci.yml` | Modify | Tune max-turns to 12 (current 15 is fine, minor tweak) |

---

## Task 1: Modify `claude-code-review.yml` — Replace Label Loop with `repository_dispatch`

**Files:**
- Modify: `.github/workflows/claude-code-review.yml`

The "Apply auto-fix labels via PAT" step currently reads `/tmp/auto-fix-issues.txt` and calls `gh issue edit --add-label "auto-fix"` for each issue. Replace this step with a single `gh api` call that fires a `repository_dispatch` event.

- [ ] **Step 1.1: Read the current "Apply auto-fix labels" step**

```bash
# Lines 149–167 of claude-code-review.yml
```

- [ ] **Step 1.2: Replace the labelling loop with a dispatch step**

Replace the `Apply auto-fix labels via PAT` step with two steps:
1. A step that reads the issue numbers into a JSON array
2. A step that fires `repository_dispatch`

```yaml
      - name: Collect auto-fix issue numbers
        id: collect
        if: steps.guard.outputs.skip != 'true'
        run: |
          if [[ ! -f /tmp/auto-fix-issues.txt ]]; then
            echo "issues=[]" >> "$GITHUB_OUTPUT"
            exit 0
          fi
          # Build a compact JSON array: [274, 275]
          ISSUES=$(grep -v '^$' /tmp/auto-fix-issues.txt \
            | jq -R 'tonumber' | jq -s -c '.')
          echo "issues=${ISSUES}" >> "$GITHUB_OUTPUT"
          echo "Collected issues: ${ISSUES}"

      - name: Trigger batch auto-fix via repository_dispatch
        if: |
          steps.guard.outputs.skip != 'true' &&
          steps.collect.outputs.issues != '[]' &&
          steps.collect.outputs.issues != ''
        env:
          GH_TOKEN: ${{ secrets.GH_PAT }}
        run: |
          gh api \
            "repos/${{ github.repository }}/dispatches" \
            -f event_type="batch-auto-fix" \
            -f client_payload='{"issue_numbers": ${{ steps.collect.outputs.issues }}, "pr_number": "${{ github.event.pull_request.number }}", "pr_url": "${{ github.event.pull_request.html_url }}", "base_branch": "${{ github.event.pull_request.base.ref }}", "head_branch": "${{ github.event.pull_request.head.ref }}", "pr_state": "OPEN"}'
          echo "Dispatched batch-auto-fix for issues: ${{ steps.collect.outputs.issues }}"
```

- [ ] **Step 1.3: Keep the old label loop as a fallback comment**

The old per-issue `auto-fix` label path in `auto-fix-review-issue.yml` should still work as a manual fallback (e.g. when a human re-labels an issue). It does not need to be removed.

- [ ] **Step 1.4: Commit**

```bash
git add .github/workflows/claude-code-review.yml
git commit -m "refactor(ci): replace per-issue auto-fix labels with repository_dispatch batch trigger"
```

---

## Task 2: Create `batch-auto-fix.yml`

**Files:**
- Create: `.github/workflows/batch-auto-fix.yml`

This is the new heart of the auto-fix system. It:
1. Receives the `repository_dispatch` payload with all issue numbers
2. Checks out the PR head branch
3. Installs dependencies once
4. Runs ONE Claude session that fixes all issues
5. Claude commits each fix separately (`closes #N`) and closes each issue

- [ ] **Step 2.1: Create the workflow skeleton**

```yaml
name: Batch Auto-fix Code Review Issues

on:
  repository_dispatch:
    types: [batch-auto-fix]

jobs:
  batch-autofix:
    name: Claude Batch Auto-fix
    runs-on: ubuntu-latest
    timeout-minutes: 40
    permissions:
      contents: write
      pull-requests: write
      issues: write
      id-token: write

    steps:
      - uses: actions/checkout@11bd71901bbe5b1630ceea73d27597364c9af683  # v4
        with:
          fetch-depth: 1   # open PRs only need latest commit

      - name: Fetch and checkout PR head branch
        run: |
          git fetch origin ${{ github.event.client_payload.head_branch }}
          git checkout ${{ github.event.client_payload.head_branch }}
        env:
          GH_TOKEN: ${{ secrets.GH_PAT }}

      - name: Install dependencies
        run: |
          npm ci
          composer install --no-interaction --prefer-dist

      - name: Write issue bodies to files
        run: |
          ISSUES='${{ toJson(github.event.client_payload.issue_numbers) }}'
          for issue_number in $(echo "$ISSUES" | jq -r '.[]'); do
            BODY=$(gh issue view "$issue_number" \
              --repo "${{ github.repository }}" \
              --json body --jq '.body')
            printf '%s' "$BODY" > "/tmp/issue-${issue_number}-body.txt"
          done
        env:
          GH_TOKEN: ${{ secrets.GH_PAT }}
```

- [ ] **Step 2.2: Add the Claude action step with the batch prompt**

The key insight for the batch prompt: give Claude a numbered list of issues to fix in order, with instructions to commit each fix separately and continue even if one issue is ambiguous.

```yaml
      - uses: anthropics/claude-code-action@aee99972d0cfa0c47a4563e6fca42d7a5a0cb9bd  # v1
        id: claude
        with:
          claude_code_oauth_token: ${{ secrets.CLAUDE_CODE_OAUTH_TOKEN }}

          prompt: |
            You are fixing a batch of code-review issues found in PR #${{ github.event.client_payload.pr_number }}.

            **Already done — no further lookups needed:**
            - Source PR: #${{ github.event.client_payload.pr_number }} (${{ github.event.client_payload.pr_url }})
            - PR state: OPEN
            - Head branch: `${{ github.event.client_payload.head_branch }}` is already checked out
            - Base branch: `${{ github.event.client_payload.base_branch }}`
            - `node_modules/` and PHP vendor dependencies are already installed

            **Issues to fix (in order):**
            ${{ join(github.event.client_payload.issue_numbers, ', ') }}

            ---

            ## Your task

            For EACH issue number listed above, in order:

            ### A — Read the issue body
            ```
            cat /tmp/issue-<N>-body.txt
            ```

            ### B — Decide whether to fix or skip
            - If the fix is clear from the codebase alone → fix it.
            - If it requires an external value (API key, business decision, secret) → post a comment explaining what is needed, then move on to the next issue. Do NOT skip silently.
            - If the issue has the `nits` label → apply all listed nit items in one commit.

            ### C — Apply the minimal fix
            Read only the files mentioned in the issue. Make the smallest correct change.

            ### D — Commit with a descriptive message
            ```bash
            git commit -m "fix: <concise description> (closes #<N>)"
            ```

            ### E — Close the issue with a summary comment
            ```bash
            gh issue close <N> \
              --comment "✅ Fixed in <commit SHA>. <One sentence summary.>" \
              --repo ${{ github.repository }}
            ```

            ### F — Continue to the next issue
            Do NOT stop if one issue was skipped. Process all issues in the list before pushing.

            ---

            ## Final step — push all commits at once
            After all issues have been processed:
            ```bash
            git push origin ${{ github.event.client_payload.head_branch }}
            ```
            Then post a single summary comment on the PR:
            ```bash
            gh pr comment ${{ github.event.client_payload.pr_number }} \
              --body "🔧 Batch auto-fix complete. Fixed: <comma-separated #N list>." \
              --repo ${{ github.repository }}
            ```

            ---

            ## Constraints
            - PHP: follow WordPress Coding Standards. Prefix functions `nj_` or use `WP_AI_Mind\` namespace.
            - JS/CSS: fix only what is reported.
            - PHPUnit: fix the code under test, not the tests, unless the assertion is clearly wrong.
            - Do NOT bump versions, modify `composer.lock`, or change `package-lock.json`.
            - Do NOT open new PRs — push all fixes directly to the head branch.

          # Scale max-turns: 8 base + 7 per issue, capped at 50
          # For 2 issues: 22 turns. For 4 issues: 36 turns. For 6 issues: 50 turns.
          claude_args: "--max-turns ${{ min(50, add(8, mul(7, length(github.event.client_payload.issue_numbers)))) }} --allowedTools Bash(git:*),Bash(gh:*),Bash(composer:*),Bash(npm:*),Bash(npx:*),Bash(./vendor/bin/phpcs:*),Bash(./vendor/bin/phpunit:*),Read,Edit,MultiEdit,Write,Glob,Grep,LS"
```

**Note on the `max-turns` expression**: GitHub Actions expressions don't support `min()`, `mul()`, or `length()` on arrays natively. Use a pre-step to calculate the value:

```yaml
      - name: Calculate max turns
        id: turns
        run: |
          NUM=$(echo '${{ toJson(github.event.client_payload.issue_numbers) }}' | jq 'length')
          TURNS=$(( 8 + NUM * 7 ))
          [[ $TURNS -gt 50 ]] && TURNS=50
          echo "value=$TURNS" >> "$GITHUB_OUTPUT"
```

Then reference `${{ steps.turns.outputs.value }}` in `claude_args`.

- [ ] **Step 2.3: Add the failure reporting step**

```yaml
      - name: Report execution failure
        if: failure() && steps.claude.outcome == 'failure'
        env:
          GH_TOKEN: ${{ secrets.GH_PAT }}
        run: |
          ISSUES='${{ toJson(github.event.client_payload.issue_numbers) }}'
          MSG="❌ Batch auto-fix failed for issues ${ISSUES}. Please fix manually or re-trigger per-issue auto-fix by re-applying the \`auto-fix\` label to each issue."
          gh pr comment ${{ github.event.client_payload.pr_number }} \
            --body "$MSG" \
            --repo ${{ github.repository }}
```

- [ ] **Step 2.4: Commit**

```bash
git add .github/workflows/batch-auto-fix.yml
git commit -m "feat(ci): add batch-auto-fix workflow — one Claude session per PR instead of N"
```

---

## Task 3: Add Context Snippets to Issue Bodies in the Review Prompt

**Files:**
- Modify: `.github/workflows/claude-code-review.yml` (the `prompt:` block)

This enriches the auto-fix sessions (both the new batch session and the legacy per-issue fallback) by embedding the relevant code excerpt in the issue body at creation time.

- [ ] **Step 3.1: Update the review prompt's issue creation instructions**

In the `Step 1 — Create issues` section of the review prompt, add the following instruction after the `--body` template:

```
When composing the issue body, add a **Context** section after the Description that includes the exact lines from the source file being discussed. Use a fenced code block with the language identifier. Limit to 25 lines maximum. Example:

  ## Context

  **File:** `includes/Modules/Images/ImagesModuleTest.php`, lines 85–102

  ```php
  Functions\when( 'get_user_meta' )->alias(
      function( $user_id, $key, $single ) use ( $month_key ) {
          if ( 'wp_ai_mind_tier' === $key ) {
              return 'free'; // ← this is the problem
          }
  ```

This allows the auto-fix session to locate and edit the code without a separate file-read turn.
```

- [ ] **Step 3.2: Verify on the next real PR that issue bodies include the Context section**

Check the body of the next auto-generated issue. If the context snippet is missing, tighten the instruction.

- [ ] **Step 3.3: Commit**

```bash
git add .github/workflows/claude-code-review.yml
git commit -m "feat(ci): include source context snippets in review issue bodies to reduce fix-session reads"
```

---

## Task 4: Tune `auto-fix-review-issue.yml` as Manual Fallback

**Files:**
- Modify: `.github/workflows/auto-fix-review-issue.yml`

The per-issue workflow should remain as a manual escape hatch (a human can re-apply the `auto-fix` label to a single issue to trigger it). But its `--max-turns` should be tiered by label, replacing the flat `25`.

- [ ] **Step 4.1: Add a pre-step that reads the issue labels and sets max-turns**

```yaml
      - name: Set max turns based on issue severity
        id: turns
        if: steps.guard.outputs.skip != 'true' && steps.parse.outputs.pr_number != ''
        env:
          GH_TOKEN: ${{ secrets.GH_PAT }}
        run: |
          LABELS=$(gh issue view ${{ github.event.issue.number }} \
            --repo "${{ github.repository }}" \
            --json labels --jq '[.labels[].name] | join(",")' )
          if echo "$LABELS" | grep -q "nits"; then
            echo "value=8" >> "$GITHUB_OUTPUT"
          elif echo "$LABELS" | grep -q "blocking"; then
            echo "value=22" >> "$GITHUB_OUTPUT"
          else
            echo "value=15" >> "$GITHUB_OUTPUT"
          fi
```

- [ ] **Step 4.2: Replace the hardcoded `--max-turns 25` with `${{ steps.turns.outputs.value }}`**

In `claude_args`, change:
```
--max-turns 25
```
to:
```
--max-turns ${{ steps.turns.outputs.value }}
```

- [ ] **Step 4.3: Commit**

```bash
git add .github/workflows/auto-fix-review-issue.yml
git commit -m "fix(ci): tier max-turns by label severity in per-issue auto-fix fallback"
```

---

## Task 5: Optimise Clone Depth in Batch Auto-Fix

**Files:**
- Modify: `.github/workflows/batch-auto-fix.yml`

Already addressed in Task 2 (`fetch-depth: 1`). This task verifies the setting and adds a comment explaining the rationale.

- [ ] **Step 5.1: Verify `fetch-depth: 1` is set in the checkout step of `batch-auto-fix.yml`**

Open PRs only need the latest commit; `fetch-depth: 0` (full history) is only required when creating a new fix branch from a closed PR. The new batch workflow targets open PRs exclusively (the `repository_dispatch` payload only fires while the PR is open). Document this in a comment above the checkout step.

- [ ] **Step 5.2: If the batch workflow later needs to handle closed/merged PRs, revisit**

For now, closed-PR fixes remain handled by the per-issue fallback (`auto-fix-review-issue.yml`), which uses `fetch-depth: 0` and is appropriate.

---

## Task 6: Verify and Test End-to-End

- [ ] **Step 6.1: Open a test PR with at least 2 intentional issues**

Create a branch with a deliberate PHPDoc omission and a minor nit (e.g. wrong return type in a docblock). Open a PR targeting `feat/api-overhaul` or another feature branch.

- [ ] **Step 6.2: Confirm the review workflow creates 2 issues and fires ONE `repository_dispatch`**

In the Actions tab, check that:
- `Claude Code Review` completes and no `auto-fix` labels appear on individual issues
- A `Batch Auto-fix Code Review Issues` run starts

- [ ] **Step 6.3: Confirm the batch auto-fix commits 2 separate commits and closes both issues**

Check the PR commits: there should be 2 commits, each with `closes #N`.
Check both issues: they should be closed with a summary comment.

- [ ] **Step 6.4: Verify the manual fallback still works**

Manually apply the `auto-fix` label to a fresh open issue. Confirm that `auto-fix-review-issue.yml` fires as expected (it was not modified to be disabled, only supplemented).

---

## Task 7: Push All Changes to Feature Branch

- [ ] **Step 7.1: Push to `claude/enhance-pr-review-system-B57yw`**

```bash
git push -u origin claude/enhance-pr-review-system-B57yw
```

---

## Expected Savings Summary

| Improvement | Tokens saved per PR | Sessions saved per PR |
|-------------|--------------------|-----------------------|
| P1 — Batch auto-fix (N=4) | ~90–150k | 3 |
| P2 — Tiered max-turns | ~15–30k | 0 (turn savings) |
| P3 — Context snippets in issues | ~8–20k | 0 (turn savings) |
| P4 — Shallow clone | 0 (time only) | — |
| **Total (N=4 issues)** | **~113–200k tokens** | **3 sessions** |

At ~200k tokens per PR saved, and assuming 2–3 PRs per day hitting the 5-hour limit, this should reduce session exhaustion significantly.

---

## What Was Deliberately NOT Changed

- **Separate issues for each finding** — preserved. Issues remain the unit of tracking and human review. The batch approach fixes them all but still creates, references, and closes each one individually.
- **Nits consolidation** — already optimal (one nits issue per PR). Kept as-is.
- **Review quality** — the review prompt is not shortened. The review session's token cost is already low relative to the N fix sessions it spawns.
- **Claude.ai subscription (no Anthropic API)** — all workflows continue to use `claude_code_oauth_token`.
- **Separate issues for blocking vs enhancement vs nits** — still separate for human triage and label filtering.

---

## Open Questions for Human Decision

1. **Should the batch session push immediately or wait for human approval?**
   Current proposal: push immediately to the open PR branch (same behaviour as the per-issue workflow). If you want a review gate, the batch session could open a sub-PR instead — but this adds overhead and another session.

2. **Should closed/merged PRs be included in the batch?**
   The current design restricts `repository_dispatch` to open PRs. Closed-PR fixes continue to use the per-issue fallback. This keeps the batch workflow simple, but means the savings don't apply when a PR is merged before auto-fix fires.

3. **Maximum batch size?**
   Proposed: cap at 50 max-turns regardless of issue count. If a PR has 8+ issues, some fixes may not complete in one session. A reasonable cap is 6 issues per batch; if more are found, the review prompt should be tuned to be more selective.
