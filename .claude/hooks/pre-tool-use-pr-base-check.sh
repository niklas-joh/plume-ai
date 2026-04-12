#!/bin/bash
# PreToolUse hook: block mcp__github__create_pull_request if base=main for non-release branches.
# Feature/fix/chore PRs must target 'develop'. Only release/* and hotfix branches go to main.

input=$(cat)
tool_name=$(echo "$input" | jq -r '.tool_name // empty')

if [[ "$tool_name" != "mcp__github__create_pull_request" ]]; then
  exit 0
fi

base=$(echo "$input" | jq -r '.tool_input.base // empty')
head=$(echo "$input" | jq -r '.tool_input.head // empty')

if [[ "$base" == "main" && ! "$head" =~ ^release/ && ! "$head" =~ ^hotfix/ ]]; then
  echo "BLOCKED: PRs for feature/fix/chore branches must target 'develop', not 'main'." >&2
  echo "Change the 'base' parameter to 'develop' before creating this PR." >&2
  echo "Only 'release/vX.Y.Z' branches (and emergency hotfixes) target 'main' directly." >&2
  exit 2
fi

exit 0
