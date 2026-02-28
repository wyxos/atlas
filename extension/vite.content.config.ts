import path from 'node:path';
import { defineConfig } from 'vite';

const extensionRoot = path.resolve(__dirname);

export default defineConfig({
    root: extensionRoot,
    resolve: {
        alias: {
            vue: 'vue/dist/vue.runtime.esm-browser.prod.js',
        },
    },
    define: {
        'process.env.NODE_ENV': JSON.stringify('production'),
        'process.env': JSON.stringify({}),
        process: JSON.stringify({ env: {} }),
        __VUE_OPTIONS_API__: true,
        __VUE_PROD_DEVTOOLS__: false,
    },
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
