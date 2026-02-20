import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

const manifestPath = resolve(process.cwd(), 'extension/atlas-downloader/manifest.json');

function parseArgs(argv) {
  let part = 'patch';
  let dryRun = false;

  for (const arg of argv) {
    if (arg === '--dry-run') {
      dryRun = true;
      continue;
    }

    if (arg === 'patch' || arg === 'minor' || arg === 'major') {
      part = arg;
      continue;
    }

    if (arg.startsWith('--part=')) {
      const value = arg.slice('--part='.length);
      if (value === 'patch' || value === 'minor' || value === 'major') {
        part = value;
        continue;
      }
    }

    throw new Error(`Unsupported argument: ${arg}`);
  }

  return { part, dryRun };
}

function bumpVersion(current, part) {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(current);
  if (!match) {
    throw new Error(`Unsupported extension version format: ${current}`);
  }

  const major = Number.parseInt(match[1], 10);
  const minor = Number.parseInt(match[2], 10);
  const patch = Number.parseInt(match[3], 10);

  if (part === 'major') {
    return `${major + 1}.0.0`;
  }

  if (part === 'minor') {
    return `${major}.${minor + 1}.0`;
  }

  return `${major}.${minor}.${patch + 1}`;
}

function main() {
  const { part, dryRun } = parseArgs(process.argv.slice(2));
  const raw = readFileSync(manifestPath, 'utf8');
  const manifest = JSON.parse(raw);

  if (!manifest || typeof manifest !== 'object' || typeof manifest.version !== 'string') {
    throw new Error('extension/atlas-downloader/manifest.json is missing a valid version field.');
  }

  const current = manifest.version;
  const next = bumpVersion(current, part);

  if (!dryRun) {
    manifest.version = next;
    writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
  }

  console.log(`Atlas extension version: ${current} -> ${next}${dryRun ? ' (dry run)' : ''}`);
}

main();
