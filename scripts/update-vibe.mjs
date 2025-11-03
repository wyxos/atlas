#!/usr/bin/env node

import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const VIBE_PATH = join(__dirname, '..', '..', '..', 'vue', 'vibe');
const ATLAS_PATH = join(__dirname, '..');

function run(command, cwd) {
    try {
        execSync(command, { 
            cwd, 
            stdio: 'inherit',
            shell: true 
        });
        return true;
    } catch {
        return false;
    }
}

console.log('🔨 Building Vibe library...');
if (!run('npm run build:lib', VIBE_PATH)) {
    console.error('❌ Vibe build failed!');
    process.exit(1);
}
console.log('✅ Vibe built successfully!\n');

console.log('📦 Installing updated Vibe in Atlas...');
if (!run('npm install', ATLAS_PATH)) {
    console.error('❌ npm install failed!');
    process.exit(1);
}
console.log('✅ Vibe installed successfully!\n');

console.log('🔨 Building Atlas...');
if (!run('npm run build', ATLAS_PATH)) {
    console.error('❌ Atlas build failed!');
    process.exit(1);
}
console.log('✅ Atlas built successfully!\n');

console.log('🎉 All done! Reload your browser with Ctrl+Shift+R');
