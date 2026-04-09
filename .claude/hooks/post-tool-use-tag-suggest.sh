#!/usr/bin/env bash
# PostToolUse hook — fired after mcp__github__create_pull_request
#
# Reads the tool result JSON from stdin, extracts the PR title/number/base,
# calls claude-haiku to suggest a semantic tag, and prints it to stderr so
# the suggestion appears in the Claude Code terminal session.
#
# This hook is informational only — it does NOT apply the tag or label.
# The CI workflow tag-infer.yml handles the actual tagging automatically.
#
# Required env (at least one):
#   ANTHROPIC_API_KEY  — pay-as-you-go API key from console.anthropic.com

set -euo pipefail

# ── Read tool result from stdin ───────────────────────────────────────────────
INPUT=$(cat)

PR_TITLE=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    # Tool result may be nested under 'output' or at top level
    out = d.get('output') or d
    print(out.get('title', ''))
except Exception:
    print('')
" 2>/dev/null || echo "")

PR_NUMBER=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    out = d.get('output') or d
    print(out.get('number', ''))
except Exception:
    print('')
" 2>/dev/null || echo "")

PR_BASE=$(echo "$INPUT" | python3 -c "
import sys, json
try:
    d = json.load(sys.stdin)
    out = d.get('output') or d
    print(out.get('baseRefName', 'develop'))
except Exception:
    print('develop')
" 2>/dev/null || echo "develop")

# Nothing to do if we couldn't parse the PR title
[ -z "$PR_TITLE" ] && exit 0

# ── Check for API key ─────────────────────────────────────────────────────────
API_KEY="${ANTHROPIC_API_KEY:-}"
if [ -z "$API_KEY" ]; then
    exit 0
fi

# ── Gather existing semantic tags for context ─────────────────────────────────
EXISTING_TAGS=$(git tag -l 'feat/*' 'feat!/*' 'fix/*' 'hotfix/*' 2>/dev/null \
    | head -20 | tr '\n' ' ' || true)

# ── Build API payload ─────────────────────────────────────────────────────────
PAYLOAD=$(python3 -c "
import json, os

tags_raw = os.environ.get('EXISTING_TAGS', '').strip()
tags = [t for t in tags_raw.split() if t]
base  = os.environ.get('PR_BASE', 'develop')
title = os.environ.get('PR_TITLE', '')

content = '''You are a semantic tagger for a WordPress plugin repository.
Suggest the best semantic tag slug for this PR.

PR title: {title}
PR base branch: {base}
Existing semantic tags: {tags}

Rules:
- feat/      new feature → minor bump
- feat!/     breaking change → MAJOR bump
- fix/       bug fix → patch bump
- hotfix/    emergency fix targeting main → patch bump
  If base is main, prefix MUST be hotfix/
- Slug: lowercase, hyphens only, max 4 words
- Reuse an existing tag if this PR extends it
- Respond with ONLY valid JSON:
  {{"tag":"feat/example","action":"reuse","reasoning":"one sentence"}}'''.format(
    title=title, base=base,
    tags=' '.join(tags) if tags else '(none yet)'
)

data = {
    'model': 'claude-haiku-4-5-20251001',
    'max_tokens': 128,
    'messages': [{'role': 'user', 'content': content}]
}
print(json.dumps(data))
" PR_TITLE="$PR_TITLE" PR_BASE="$PR_BASE" EXISTING_TAGS="$EXISTING_TAGS")

# ── Call Anthropic API ────────────────────────────────────────────────────────
RESPONSE=$(curl -sf --max-time 10 https://api.anthropic.com/v1/messages \
    -H "x-api-key: ${API_KEY}" \
    -H "anthropic-version: 2023-06-01" \
    -H "content-type: application/json" \
    -d "$PAYLOAD" 2>/dev/null || echo "")

[ -z "$RESPONSE" ] && exit 0

SUGGESTION=$(echo "$RESPONSE" | python3 -c "
import sys, json
try:
    r = json.load(sys.stdin)
    t = json.loads(r['content'][0]['text'])
    print(t['tag'])
except Exception:
    print('')
" 2>/dev/null || echo "")

[ -z "$SUGGESTION" ] && exit 0

# ── Print suggestion to stderr (visible in Claude Code terminal) ──────────────
echo "" >&2
echo "  Semantic tag suggestion for PR #${PR_NUMBER}: ${SUGGESTION}" >&2
echo "  CI (tag-infer.yml) will apply this automatically. To override, edit the label on the PR." >&2
echo "" >&2
