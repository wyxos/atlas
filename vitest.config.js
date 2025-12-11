import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { resolve } from 'path';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': resolve(__dirname, './resources/js'),
        },
    },
    test: {
        globals: true,
        environment: 'jsdom',
        include: ['resources/js/**/*.{test,spec}.{js,mjs,cjs,ts,mts,cts,jsx,tsx}'],
        coverage: {
            provider: 'v8',
            reporter: ['text', 'json', 'html'],
        },
        setupFiles: ['./resources/js/test-setup.ts'],
        // Watch mode configuration
        // Vitest automatically uses dependency graph analysis to only run tests
        // affected by changed files. If a file is imported by many tests,
        // all those tests will run (this is correct behavior).
        watchExclude: [
            '**/node_modules/**',
            '**/dist/**',
            '**/.git/**',
            '**/coverage/**',
        ],
    },
});


