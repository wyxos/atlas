import js from '@eslint/js';
import tseslint from 'typescript-eslint';
import vue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';
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
            '@typescript-eslint/no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
            '@typescript-eslint/no-explicit-any': 'warn',
            '@typescript-eslint/require-await': 'off',
            '@typescript-eslint/no-unused-expressions': 'off',
            'vue/no-unused-vars': 'warn',
            'vue/use-v-on-exact': 'off',
        },
    },
    {
        files: [
            '**/*.test.{js,jsx,ts,tsx}',
            '**/*.spec.{js,jsx,ts,tsx}',
            '**/*.test.utils.{js,ts}',
            'resources/js/test/**/*.{js,ts,tsx}',
        ],
        rules: {
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    {
        files: ['**/*.vue'],
        languageOptions: {
            globals: {
                ...globals.browser,
            },
            parser: vueParser,
            parserOptions: {
                parser: tseslint.parser,
                ecmaVersion: 2020,
                sourceType: 'module',
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
            'resources/js/actions/**',
        ],
    }
);
