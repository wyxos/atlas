import { execSync } from 'node:child_process';
import {
  cpSync,
  existsSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { resolve } from 'node:path';

const rootDir = process.cwd();
const extensionDir = resolve(rootDir, 'extension/atlas-downloader');
const manifestPath = resolve(extensionDir, 'manifest.json');
const distDir = resolve(extensionDir, 'dist');
const envPath = resolve(rootDir, '.env');

function run(command) {
  console.log(`> ${command}`);
  execSync(command, { cwd: rootDir, stdio: 'inherit' });
}

function parseArgs(argv) {
  const allowed = new Set(['patch', 'minor', 'major']);
  let part = 'patch';

  for (const arg of argv) {
    if (!allowed.has(arg)) {
      throw new Error(`Unsupported argument "${arg}". Use patch, minor, or major.`);
    }
    part = arg;
  }

  return { part };
}

function bumpVersion(currentVersion, part) {
  const match = /^(\d+)\.(\d+)\.(\d+)$/.exec(currentVersion);
  if (!match) {
    throw new Error(`Unsupported extension version format: ${currentVersion}`);
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

function readEnvValue(key) {
  const shellValue = process.env[key]?.trim();
  if (shellValue) {
    return shellValue;
  }

  if (!existsSync(envPath)) {
    return '';
  }

  const raw = readFileSync(envPath, 'utf8');
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const separator = trimmed.indexOf('=');
    if (separator === -1) {
      continue;
    }

    const envKey = trimmed.slice(0, separator).trim();
    if (envKey !== key) {
      continue;
    }

    let value = trimmed.slice(separator + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }

    return value.trim();
  }

  return '';
}

function syncExtension(syncDir) {
  const resolvedSyncDir = resolve(rootDir, syncDir);
  const normalizedSyncDir = resolvedSyncDir.replace(/[\\/]+$/, '');
  const normalizedExtensionDir = extensionDir.replace(/[\\/]+$/, '');

  if (normalizedSyncDir === normalizedExtensionDir) {
    throw new Error('ATLAS_EXTENSION_SYNC_DIR cannot point to extension/atlas-downloader.');
  }

  if (existsSync(resolvedSyncDir)) {
    const stat = statSync(resolvedSyncDir);
    if (!stat.isDirectory()) {
      throw new Error(`ATLAS_EXTENSION_SYNC_DIR is not a directory: ${resolvedSyncDir}`);
    }
  } else {
    mkdirSync(resolvedSyncDir, { recursive: true });
  }

  for (const entry of readdirSync(resolvedSyncDir)) {
    rmSync(resolve(resolvedSyncDir, entry), { recursive: true, force: true });
  }

  for (const entry of readdirSync(extensionDir)) {
    cpSync(resolve(extensionDir, entry), resolve(resolvedSyncDir, entry), {
      recursive: true,
      force: true,
    });
  }

  return resolvedSyncDir;
}

function main() {
  const { part } = parseArgs(process.argv.slice(2));

  const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
  if (!manifest || typeof manifest.version !== 'string') {
    throw new Error('extension/atlas-downloader/manifest.json is missing a valid version field.');
  }

  const previousVersion = manifest.version;
  const nextVersion = bumpVersion(previousVersion, part);
  manifest.version = nextVersion;
  writeFileSync(manifestPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
  console.log(`Atlas extension version: ${previousVersion} -> ${nextVersion}`);

  rmSync(distDir, { recursive: true, force: true });
  run('vite build -c extension/atlas-downloader/vite.options.config.ts');
  run('vite build -c extension/atlas-downloader/vite.background.config.ts');
  run('vite build -c extension/atlas-downloader/vite.content.config.ts');

  run('git add -A');
  run(`git commit -m "${nextVersion}"`);

  const syncDir = readEnvValue('ATLAS_EXTENSION_SYNC_DIR');
  if (!syncDir) {
    throw new Error('ATLAS_EXTENSION_SYNC_DIR is not set in shell env or .env.');
  }

  const syncedTo = syncExtension(syncDir);
  console.log(`Extension synced to ${syncedTo}`);
}

main();
