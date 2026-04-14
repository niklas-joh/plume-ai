#!/bin/bash
# PreToolUse hook: validate mcp__github__create_pull_request base branch.
# All PRs (feat/fix/chore) target 'main' directly. There is no develop branch.

input=$(cat)
tool_name=$(echo "$input" | jq -r '.tool_name // empty')

if [[ "$tool_name" != "mcp__github__create_pull_request" ]]; then
  exit 0
fi

base=$(echo "$input" | jq -r '.tool_input.base // empty')

if [[ "$base" != "main" ]]; then
  echo "BLOCKED: All PRs must target 'main', not '$base'." >&2
  echo "Change the 'base' parameter to 'main' before creating this PR." >&2
  exit 2
fi

exit 0
