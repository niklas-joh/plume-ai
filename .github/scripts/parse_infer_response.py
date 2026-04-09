#!/usr/bin/env python3
"""
Parse the Anthropic API response for semantic tag inference.

Reads the API response JSON from stdin and prints three lines to stdout:
  line 1 — tag     (e.g. feat/ai-chat)
  line 2 — action  (reuse | create)
  line 3 — reasoning (one sentence)

Called by tag-infer.yml after the curl step.
Exits with code 1 if parsing fails so the shell step can detect the error.
"""
import json
import sys

try:
    response = json.load(sys.stdin)
    parsed = json.loads(response['content'][0]['text'])
    print(parsed['tag'])
    print(parsed['action'])
    print(parsed.get('reasoning', ''))
except Exception as e:
    print(f'parse error: {e}', file=sys.stderr)
    sys.exit(1)
