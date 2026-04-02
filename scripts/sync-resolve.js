#!/usr/bin/env node
'use strict';

/**
 * Nightly sync conflict resolver.
 *
 * Reads files that have Git conflict markers after a failed `git merge`,
 * sends them to Claude, and writes resolved content back to disk if the
 * conflicts are deemed "easy" (unambiguous).
 *
 * Exit codes:
 *   0 — all conflicts resolved and files written (caller must `git add` + commit)
 *   2 — conflicts are too complex; human review required
 *   1 — unexpected error
 */

const { execSync } = require('child_process');
const fs = require('fs');
const Anthropic = require('@anthropic-ai/sdk');

const MAX_BYTES_PER_FILE = 80_000; // ~20k tokens — skip ludicrously large files

async function main() {
  const conflictedFiles = execSync('git diff --name-only --diff-filter=U')
    .toString()
    .trim()
    .split('\n')
    .filter(Boolean);

  if (conflictedFiles.length === 0) {
    console.log('No conflicted files detected.');
    process.exit(0);
  }

  console.log(`Conflicted files (${conflictedFiles.length}): ${conflictedFiles.join(', ')}`);

  // Read file contents, skipping binary / oversized files
  const fileContents = {};
  for (const file of conflictedFiles) {
    const size = fs.statSync(file).size;
    if (size > MAX_BYTES_PER_FILE) {
      console.warn(`  Skipping ${file} — too large (${size} bytes). Marking as complex.`);
      process.exit(2);
    }
    const content = fs.readFileSync(file, 'utf8');
    if (!content.includes('<<<<<<<')) {
      console.warn(`  ${file} has no conflict markers — skipping.`);
      continue;
    }
    fileContents[file] = content;
  }

  if (Object.keys(fileContents).length === 0) {
    console.log('No files with conflict markers remain.');
    process.exit(0);
  }

  const conflictBlocks = Object.entries(fileContents)
    .map(([f, c]) => `### ${f}\n\`\`\`\n${c}\n\`\`\``)
    .join('\n\n');

  const prompt = `You are a code reviewer analyzing Git merge conflicts produced by a nightly sync that merges \`main\` into \`develop\`.

Your job is to determine whether each conflict is **easy** (safe to auto-resolve without ambiguity) or **complex** (requires human judgment).

**Easy** conflicts are:
- One side added lines in a region the other side did not touch
- Both sides added different imports/constants that don't interact
- Version number or sequential counter increments
- Whitespace or comment-only differences
- One side removed a line the other side also removed

**Complex** conflicts are:
- Both sides changed the same logic/function differently
- Structural changes (e.g. a function was renamed or moved on one side and modified on the other)
- Semantic incompatibilities where choosing either version would break the other's intent
- Any conflict in lock files (package-lock.json, composer.lock) with non-trivial content differences

Here are the conflicted files:

${conflictBlocks}

Respond with **JSON only** (no markdown fences, no explanation outside the JSON):

{
  "assessment": "easy" | "complex",
  "reason": "<one-sentence explanation>",
  "resolutions": {
    "<filename>": "<complete resolved file content — no conflict markers>"
  }
}

If \`assessment\` is "complex", set \`resolutions\` to \`{}\`.
If \`assessment\` is "easy", every file listed above must appear in \`resolutions\` with its full resolved content.`;

  console.log('Sending conflicts to Claude for assessment…');

  const client = new Anthropic();
  const message = await client.messages.create({
    model: 'claude-sonnet-4-6',
    max_tokens: 8096,
    messages: [{ role: 'user', content: prompt }],
  });

  let raw = message.content[0].text.trim();
  // Strip accidental markdown fences
  raw = raw.replace(/^```(?:json)?\n?/, '').replace(/\n?```$/, '');

  let result;
  try {
    result = JSON.parse(raw);
  } catch {
    console.error('Failed to parse Claude response:\n', raw);
    process.exit(1);
  }

  console.log(`Claude assessment : ${result.assessment}`);
  console.log(`Reason            : ${result.reason}`);

  if (result.assessment === 'complex') {
    process.exit(2);
  }

  // Validate and write resolved files
  const resolutions = result.resolutions ?? {};
  for (const file of Object.keys(fileContents)) {
    if (!(file in resolutions)) {
      console.error(`Claude did not provide a resolution for ${file}.`);
      process.exit(1);
    }
    const resolved = resolutions[file];
    if (resolved.includes('<<<<<<<') || resolved.includes('>>>>>>>')) {
      console.error(`Resolved content for ${file} still contains conflict markers.`);
      process.exit(1);
    }
    fs.writeFileSync(file, resolved, 'utf8');
    console.log(`  ✓ ${file}`);
  }

  process.exit(0);
}

main().catch((err) => {
  console.error('Unexpected error:', err.message);
  process.exit(1);
});
