import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const manifestPath = path.resolve(__dirname, 'manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) as { version?: string };
const extensionVersion = manifest.version ?? '0.0.0';

export default defineConfig({
    plugins: [vue(), tailwindcss()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, '../resources/js'),
        },
    },
    define: {
        __ATLAS_EXTENSION_VERSION__: JSON.stringify(extensionVersion),
    },
    build: {
        outDir: path.resolve(__dirname, 'dist'),
        emptyOutDir: true,
        rollupOptions: {
            input: {
                popup: path.resolve(__dirname, 'popup.html'),
            },
        },
    },
});
