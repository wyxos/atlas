#!/usr/bin/env node

import { execSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';

function run(command) {
  try {
    return execSync(command, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
  } catch {
    return '';
  }
}

function listFiles(command) {
  const output = run(command);
  if (!output) {
    return [];
  }

  return output
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line.endsWith('.php'));
}

const changed = new Set();

for (const file of listFiles('git diff --name-only --diff-filter=AM -- database/migrations')) {
  changed.add(file);
}

for (const file of listFiles('git diff --name-only --cached --diff-filter=AM -- database/migrations')) {
  changed.add(file);
}

const hasOriginMain = run('git rev-parse --verify origin/main');
if (hasOriginMain) {
  for (const file of listFiles('git diff --name-only --diff-filter=AM origin/main...HEAD -- database/migrations')) {
    changed.add(file);
  }
}

if (changed.size === 0) {
  process.exit(0);
}

const filesTablePatterns = [
  /Schema::table\(\s*['"]files['"]/i,
  /Schema::create\(\s*['"]files['"]/i,
  /ALTER\s+TABLE\s+`?files`?/i,
  /rename\(\s*['"]files['"]/i,
];

const offenders = [];

for (const file of changed) {
  if (!existsSync(file)) {
    continue;
  }

  const content = readFileSync(file, 'utf8');
  if (filesTablePatterns.some((pattern) => pattern.test(content))) {
    offenders.push(file);
  }
}

if (offenders.length === 0) {
  process.exit(0);
}

console.error('\n[guard-files-table-migrations] Blocking release/check.');
console.error('Changed migration(s) touching `files` table were detected:\n');
for (const file of offenders) {
  console.error(`- ${file}`);
}
console.error('\nRule: do not run `files` table migrations inline in release.');
console.error('Use queued/post-deploy operational commands instead, then remove table-changing SQL from migration.\n');
process.exit(1);
