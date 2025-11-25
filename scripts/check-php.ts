#!/usr/bin/env node

import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const projectRoot = join(__dirname, '..');

type ColorKey = 'reset' | 'bright' | 'red' | 'green' | 'yellow' | 'blue' | 'cyan';

const colors: Record<ColorKey, string> = {
    reset: '\x1b[0m',
    bright: '\x1b[1m',
    red: '\x1b[31m',
    green: '\x1b[32m',
    yellow: '\x1b[33m',
    blue: '\x1b[34m',
    cyan: '\x1b[36m',
};

function log(message: string, color: ColorKey = 'reset'): void {
    console.log(`${colors[color]}${message}${colors.reset}`);
}

function runCommand(command: string, description: string, cwd: string = projectRoot): boolean {
    log(`\n${colors.bright}${description}${colors.reset}`, 'cyan');
    log(`Running: ${command}`, 'blue');
    
    try {
        execSync(command, {
            cwd,
            stdio: 'inherit',
            encoding: 'utf-8',
        });
        log(`✓ ${description} passed`, 'green');
        return true;
    } catch {
        log(`✗ ${description} failed`, 'red');
        return false;
    }
}

async function main(): Promise<void> {
    log('\n' + '='.repeat(60), 'bright');
    log('Running PHP Quality Checks', 'bright');
    log('='.repeat(60), 'bright');

    const results = {
        phpLint: false,
        phpTest: false,
    };

    // 1. PHP Linting (Laravel Pint)
    // Use php to run pint on Windows
    const isWindows = process.platform === 'win32';
    const pintPath = isWindows ? 'vendor\\bin\\pint' : 'vendor/bin/pint';
    
    // First, auto-fix issues (Pint fixes by default when run without --test)
    log('\nAttempting to auto-fix PHP issues with Pint...', 'blue');
    try {
        execSync(`php ${pintPath}`, {
            cwd: projectRoot,
            stdio: 'inherit',
            encoding: 'utf-8',
        });
    } catch {
        // Continue to check even if fix had issues
    }
    
    // Then check for remaining issues
    results.phpLint = runCommand(
        `php ${pintPath} --test`,
        'PHP Linting (Laravel Pint)'
    );

    // 2. PHP Tests
    results.phpTest = runCommand(
        'php artisan test',
        'PHP Tests (Pest)'
    );

    // Summary
    log('\n' + '='.repeat(60), 'bright');
    log('Summary', 'bright');
    log('='.repeat(60), 'bright');
    
    const allPassed = Object.values(results).every(result => result === true);
    
    log(`PHP Linting:        ${results.phpLint ? '✓' : '✗'}`, results.phpLint ? 'green' : 'red');
    log(`PHP Tests:          ${results.phpTest ? '✓' : '✗'}`, results.phpTest ? 'green' : 'red');
    
    log('\n' + '='.repeat(60), 'bright');
    
    if (allPassed) {
        log('All PHP checks passed! ✓', 'green');
        process.exit(0);
    } else {
        log('Some PHP checks failed. Please fix the errors above.', 'red');
        process.exit(1);
    }
}

main().catch((error: Error) => {
    log(`\n✗ Unexpected error: ${error.message}`, 'red');
    process.exit(1);
});

