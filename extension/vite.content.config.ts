import path from 'node:path';
import { defineConfig } from 'vite';

const extensionRoot = path.resolve(__dirname);

export default defineConfig({
    root: extensionRoot,
    build: {
        outDir: path.resolve(extensionRoot, 'dist'),
        emptyOutDir: false,
        lib: {
            entry: path.resolve(extensionRoot, 'src/content-main.ts'),
            formats: ['iife'],
            name: 'AtlasContentScript',
            fileName: () => 'content.js',
        },
        rollupOptions: {
            output: {
                inlineDynamicImports: true,
            },
        },
    },
});
