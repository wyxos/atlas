import fs from 'node:fs';
import path from 'node:path';
import { defineConfig, type Plugin } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

const extensionRoot = path.resolve(__dirname);
const manifestPath = path.resolve(extensionRoot, 'manifest.json');
const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8')) as { version?: string };
const extensionVersion = manifest.version ?? '0.0.0';

function copyExtensionManifest(): Plugin {
    return {
        name: 'copy-extension-manifest',
        closeBundle() {
            const outputManifestPath = path.resolve(extensionRoot, 'dist', 'manifest.json');
            fs.copyFileSync(manifestPath, outputManifestPath);
        },
    };
}

export default defineConfig({
    root: extensionRoot,
    plugins: [vue(), tailwindcss(), copyExtensionManifest()],
    resolve: {
        alias: {
            '@': path.resolve(extensionRoot, '../resources/js'),
        },
    },
    define: {
        __ATLAS_EXTENSION_VERSION__: JSON.stringify(extensionVersion),
    },
    build: {
        outDir: path.resolve(extensionRoot, 'dist'),
        emptyOutDir: true,
        rollupOptions: {
            input: {
                popup: path.resolve(extensionRoot, 'popup.html'),
                options: path.resolve(extensionRoot, 'options.html'),
                background: path.resolve(extensionRoot, 'src/background.ts'),
            },
            output: {
                entryFileNames: '[name].js',
            },
        },
    },
});
