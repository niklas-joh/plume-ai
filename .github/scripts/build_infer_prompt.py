#!/usr/bin/env python3
"""
Build the prompt for semantic tag inference via Claude Code CLI.

Reads context from environment variables and prints the prompt text to stdout.
Called by tag-infer.yml before the claude CLI step.

Env vars consumed:
  EXISTING_TAGS   space-separated list of existing semantic git tags
  COMMITS         newline-separated list of recent commit headlines
  PR_BASE_BRANCH  base branch of the PR (develop or main)
  PR_TITLE        PR title
  PR_BRANCH       PR head branch name
"""
import os

tags_raw = os.environ.get('EXISTING_TAGS', '').strip()
tags = [t for t in tags_raw.split() if t]
commits = os.environ.get('COMMITS', '').strip()
base = os.environ.get('PR_BASE_BRANCH', 'develop')
title = os.environ.get('PR_TITLE', '')
branch = os.environ.get('PR_BRANCH', '')

print((
    "You are a semantic tagger for a WordPress plugin repository.\n\n"
    "Given this PR, choose the best semantic tag slug.\n\n"
    "PR title: {title}\n"
    "PR branch: {branch}\n"
    "PR base branch: {base}\n"
    "Recent commits:\n{commits}\n\n"
    "Existing semantic tags (reuse if this PR clearly extends one of them):\n{tags}\n\n"
    "Rules:\n"
    "- Prefix options:\n"
    "    feat/      new feature (targets develop) - minor version bump\n"
    "    feat!/     breaking change (targets develop) - MAJOR version bump\n"
    "    fix/       bug fix (targets develop) - patch version bump\n"
    "    hotfix/    emergency fix targeting main - patch version bump\n"
    '  If PR base branch is "main", the prefix MUST be hotfix/\n'
    '  If the PR title or commits contain "!" or "BREAKING CHANGE", use feat!/\n'
    "- Slug: lowercase, hyphens only, max 4 words\n"
    "  Examples: feat/ai-chat, fix/api-key-validation, feat!/remove-legacy-api\n"
    "- Reuse an existing tag slug if this PR clearly extends that work\n"
    "- Otherwise create a new descriptive slug\n"
    '- Respond with ONLY valid JSON, no markdown fences:\n'
    '  {{"tag":"feat/example","action":"reuse","reasoning":"one sentence"}}\n'
    '  where action is "reuse" or "create"'
).format(
    title=title,
    branch=branch,
    base=base,
    commits=commits or '(no commits yet)',
    tags='\n'.join(tags) if tags else '(none yet)',
))
