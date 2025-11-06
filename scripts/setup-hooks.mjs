#!/usr/bin/env node

import { execSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const repoRoot = resolve(__dirname, '..');
const hooksDir = resolve(repoRoot, 'scripts', 'git-hooks');

function exitWith(message) {
    console.error(message);
    process.exit(1);
}

function run(command) {
    try {
        execSync(command, {
            cwd: repoRoot,
            stdio: 'inherit',
            shell: true,
        });
    } catch (error) {
        const reason = error.stderr?.toString().trim();
        if (reason) {
            console.error(reason);
        }
        process.exit(error.status ?? 1);
    }
}

if (!existsSync(resolve(repoRoot, '.git'))) {
    exitWith('This script must be run from within a Git repository.');
}

if (!existsSync(hooksDir)) {
    exitWith('Expected hook directory not found at scripts/git-hooks.');
}

try {
    execSync('git rev-parse --is-inside-work-tree', { cwd: repoRoot, stdio: 'ignore', shell: true });
} catch {
    exitWith('Unable to verify Git repository. Ensure Git is installed and you are within a repo.');
}

const hooksPath = relative(repoRoot, hooksDir).replace(/\\/g, '/');

console.log(`➡️  Setting Git hooks path to ${hooksPath}`);
run(`git config core.hooksPath "${hooksPath}"`);

if (process.platform !== 'win32') {
    console.log('➡️  Ensuring hook scripts are executable');
    run('chmod +x scripts/git-hooks/*');
}

console.log('✅ Git hooks configured. The pre-commit hook will now run project checks.');

