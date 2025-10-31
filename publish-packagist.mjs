#!/usr/bin/env node
/**
 * Publish local repositories to Packagist for the first time.
 *
 * - Reads PACKAGIST_TOKEN from each package's .env file (do not log tokens)
 * - Detects the repository remote URL via Git and normalizes it to HTTPS
 * - Derives Packagist username from composer.json vendor (or git owner) unless PACKAGIST_USERNAME is set
 * - Calls Packagist "create package" API using proper Bearer auth
 *
 * Usage:
 *   node publish-packagist.mjs
 */

import { readFile } from 'node:fs/promises';
import { access } from 'node:fs/promises';
import { constants as fsConstants } from 'node:fs';
import { execFile as execFileCallback } from 'node:child_process';
import { promisify } from 'node:util';
import path from 'node:path';

const execFile = promisify(execFileCallback);

const packageDirectories = [
  'D:\\code\\wyxos\\php\\atlas-plugin-contracts',
  'D:\\code\\wyxos\\php\\atlas-plugin-wallhaven',
];

const packagistCreateEndpoint = 'https://packagist.org/api/create-package';

async function fileExists(filePath) {
  try {
    await access(filePath, fsConstants.F_OK);
    return true;
  } catch {
    return false;
  }
}

function parseDotEnv(envFileContent) {
  const environment = {};
  const lines = envFileContent.split(/\r?\n/);
  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const equalsIndex = line.indexOf('=');
    if (equalsIndex === -1) continue;
    const rawKey = line.slice(0, equalsIndex).trim().replace(/^export\s+/, '');
    let value = line.slice(equalsIndex + 1).trim();
    if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
      value = value.slice(1, -1);
    }
    environment[rawKey] = value;
  }
  return environment;
}

async function readEnvMap(packageDirectory) {
  const envPath = path.join(packageDirectory, '.env');
  if (!(await fileExists(envPath))) {
    return {};
  }
  const envContent = await readFile(envPath, 'utf8');
  return parseDotEnv(envContent);
}

async function readPackagistTokenFromEnv(packageDirectory) {
  const envPath = path.join(packageDirectory, '.env');
  if (!(await fileExists(envPath))) {
    throw new Error(`Missing .env file at ${envPath}`);
  }
  const envContent = await readFile(envPath, 'utf8');
  const envMap = parseDotEnv(envContent);
  const token = envMap.PACKAGIST_TOKEN || process.env.PACKAGIST_TOKEN;
  if (!token) {
    throw new Error(`PACKAGIST_TOKEN not found in ${envPath} (or process.env)`);
  }
  return token.trim();
}

async function getGitRemoteUrl(packageDirectory) {
  // Prefer: git config --get remote.origin.url
  try {
    const { stdout } = await execFile('git', ['config', '--get', 'remote.origin.url'], { cwd: packageDirectory });
    const url = stdout.trim();
    if (url) return url;
  } catch {}

  // Fallback: git remote get-url origin
  try {
    const { stdout } = await execFile('git', ['remote', 'get-url', 'origin'], { cwd: packageDirectory });
    const url = stdout.trim();
    if (url) return url;
  } catch {}

  throw new Error('Unable to determine Git origin URL. Ensure a remote named "origin" exists.');
}

function normalizeGitUrlToHttps(remoteUrl) {
  let normalized = remoteUrl.trim();

  // ssh format: git@github.com:owner/repo.git
  const sshMatch = normalized.match(/^git@([^:]+):(.+)$/);
  if (sshMatch) {
    const host = sshMatch[1];
    let repoPath = sshMatch[2];
    if (repoPath.endsWith('.git')) repoPath = repoPath.slice(0, -4);
    return `https://${host}/${repoPath}`;
  }

  // ssh protocol: ssh://git@github.com/owner/repo.git
  const sshProtocolMatch = normalized.match(/^ssh:\/\/git@([^/]+)\/(.+)$/);
  if (sshProtocolMatch) {
    const host = sshProtocolMatch[1];
    let repoPath = sshProtocolMatch[2];
    if (repoPath.endsWith('.git')) repoPath = repoPath.slice(0, -4);
    return `https://${host}/${repoPath}`;
  }

  // https format: https://github.com/owner/repo(.git)
  if (/^https?:\/\//i.test(normalized)) {
    if (normalized.endsWith('.git')) normalized = normalized.slice(0, -4);
    return normalized;
  }

  // git protocol: git://github.com/owner/repo.git
  if (/^git:\/\//i.test(normalized)) {
    normalized = normalized.replace(/^git:\/\//i, 'https://');
    if (normalized.endsWith('.git')) normalized = normalized.slice(0, -4);
    return normalized;
  }

  // If none matched, return as-is (Packagist may still accept)
  return normalized;
}

function extractOwnerFromGitUrl(remoteUrl) {
  const url = remoteUrl.trim();

  // git@github.com:owner/repo(.git)
  let match = url.match(/^git@[^:]+:([^/]+)\/(.+)$/);
  if (match) return match[1];

  // ssh://git@github.com/owner/repo(.git)
  match = url.match(/^ssh:\/\/git@[^/]+\/([^/]+)\/(.+)$/);
  if (match) return match[1];

  // https://github.com/owner/repo(.git)
  match = url.match(/^https?:\/\/[^/]+\/([^/]+)\/(.+)$/i);
  if (match) return match[1];

  // git://github.com/owner/repo(.git)
  match = url.match(/^git:\/\/[^/]+\/([^/]+)\/(.+)$/i);
  if (match) return match[1];

  return null;
}

async function readPackagistUsername(packageDirectory, gitRemoteUrl) {
  // 1) .env override
  const envMap = await readEnvMap(packageDirectory);
  const fromEnv = envMap.PACKAGIST_USERNAME || process.env.PACKAGIST_USERNAME;
  if (fromEnv && fromEnv.trim()) return fromEnv.trim();

  // 2) composer.json "name" vendor part
  const composerPath = path.join(packageDirectory, 'composer.json');
  if (await fileExists(composerPath)) {
    try {
      const composerRaw = await readFile(composerPath, 'utf8');
      const composerJson = JSON.parse(composerRaw);
      if (typeof composerJson.name === 'string' && composerJson.name.includes('/')) {
        const vendor = composerJson.name.split('/')[0].trim();
        if (vendor) return vendor;
      }
    } catch {}
  }

  // 3) Git owner as best-effort fallback
  const owner = extractOwnerFromGitUrl(gitRemoteUrl);
  if (owner) return owner;

  throw new Error('Unable to determine Packagist username. Set PACKAGIST_USERNAME in the package .env or ensure composer.json contains a "name" (vendor/package).');
}

async function createPackagistPackage(packagistUsername, packagistToken, repositoryUrl, userAgent) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 60_000);
  try {
    const response = await fetch(packagistCreateEndpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'Authorization': `Bearer ${packagistUsername}:${packagistToken}`,
        'User-Agent': userAgent || `wyxos-atlas-packagist-publisher/1.0 (${packagistUsername})`,
      },
      body: JSON.stringify({ repository: repositoryUrl }),
      signal: controller.signal,
    });

    const text = await response.text();
    let data;
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      data = { raw: text };
    }

    if (!response.ok) {
      const message = data && (data.error || data.message) ? (data.error || data.message) : response.statusText;
      throw new Error(`Packagist API error (${response.status}): ${message}`);
    }

    return data;
  } finally {
    clearTimeout(timeout);
  }
}

async function buildUserAgent(packageDirectory, packagistUsername) {
  const envMap = await readEnvMap(packageDirectory);
  const contact = envMap.PACKAGIST_CONTACT_EMAIL || envMap.CONTACT_EMAIL || process.env.PACKAGIST_CONTACT_EMAIL || process.env.CONTACT_EMAIL || '';
  const parts = [
    'wyxos-atlas-packagist-publisher/1.0',
    `(vendor:${packagistUsername})`,
  ];
  if (contact) parts.push(`(mailto:${contact})`);
  return parts.join(' ');
}

async function publishDirectoryToPackagist(packageDirectory) {
  console.log('────────────────────────────────────────────────────────');
  console.log(`Processing package directory: ${packageDirectory}`);

  const packagistToken = await readPackagistTokenFromEnv(packageDirectory);
  const gitRemoteUrl = await getGitRemoteUrl(packageDirectory);
  const httpsRepositoryUrl = normalizeGitUrlToHttps(gitRemoteUrl);
  const packagistUsername = await readPackagistUsername(packageDirectory, gitRemoteUrl);
  const userAgent = await buildUserAgent(packageDirectory, packagistUsername);

  console.log(`Detected Git origin URL: ${gitRemoteUrl}`);
  console.log(`Normalized repository URL: ${httpsRepositoryUrl}`);
  console.log(`Using Packagist username: ${packagistUsername}`);

  const result = await createPackagistPackage(packagistUsername, packagistToken, httpsRepositoryUrl, userAgent);

  // Common successful responses include keys like: status, message, package, url, job_id, etc.
  console.log('Packagist create-package response:');
  console.log(JSON.stringify(result, null, 2));
}

async function main() {
  let hadErrors = false;
  for (const directory of packageDirectories) {
    try {
      await publishDirectoryToPackagist(directory);
    } catch (error) {
      hadErrors = true;
      console.error(`Failed to publish ${directory}`);
      console.error(String(error?.message || error));
    }
  }
  if (hadErrors) process.exitCode = 1;
}

await main();

