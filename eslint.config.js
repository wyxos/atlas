import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';
import globals from 'globals';

export default tseslint.config(
    js.configs.recommended,
    ...tseslint.configs.recommended,
    ...vue.configs['flat/essential'],
    {
        files: ['**/*.{js,mjs,cjs,ts,tsx}'],
        languageOptions: {
            globals: {
                ...globals.node,
                ...globals.browser,
            },
            parserOptions: {
                ecmaVersion: 2020,
                sourceType: 'module',
            },
        },
        rules: {
            '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/require-await': 'off',
        },
    },
    {
        files: ['**/*.vue'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        files: ['scripts/**/*.mjs'],
        languageOptions: {
            globals: {
                ...globals.node,
            },
        },
    },
    {
        files: ['resources/js/**/*.{js,ts}'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
        },
    },
    {
        ignores: [
            'node_modules/**',
            'vendor/**',
            'public/**',
            'storage/**',
            'bootstrap/cache/**',
            'eslint.config.js',
        ],
    }
);

