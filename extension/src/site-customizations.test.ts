import { describe, expect, it } from 'vitest';
import {
    exportSiteCustomizationsPayload,
    getDefaultSiteCustomizations,
    parseSiteCustomizationsImportJson,
    resolveSiteCustomizationForHostname,
} from './site-customizations';

describe('site-customizations', () => {
    it('resolves the most specific customization for the active page hostname', () => {
        const customization = resolveSiteCustomizationForHostname([
            {
                enabled: true,
                domain: 'example.com',
                matchRules: [],
                referrerCleaner: {
                    stripQueryParams: ['tag'],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: [],
                },
            },
            {
                enabled: true,
                domain: 'sub.example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                referrerCleaner: {
                    stripQueryParams: ['foo'],
                },
                mediaCleaner: {
                    stripQueryParams: ['quality'],
                    rewriteRules: [],
                    strategies: [],
                },
            },
        ], 'deep.sub.example.com');

        expect(customization).toEqual({
            enabled: true,
            domain: 'sub.example.com',
            matchRules: ['.*\\/gallery\\/.*'],
            referrerCleaner: {
                stripQueryParams: ['foo'],
            },
            mediaCleaner: {
                stripQueryParams: ['quality'],
                rewriteRules: [],
                strategies: [],
            },
        });
    });

    it('ignores disabled customizations when resolving the active page hostname', () => {
        const customization = resolveSiteCustomizationForHostname([
            {
                enabled: false,
                domain: 'example.com',
                matchRules: [],
                referrerCleaner: {
                    stripQueryParams: [],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: [],
                },
            },
        ], 'example.com');

        expect(customization).toBeNull();
    });

    it('includes civitai.red in the built-in customization set', () => {
        expect(getDefaultSiteCustomizations()).toEqual(expect.arrayContaining([
            expect.objectContaining({
                domain: 'civitai.com',
                mediaCleaner: expect.objectContaining({
                    strategies: ['civitaiCanonical'],
                }),
            }),
            expect.objectContaining({
                domain: 'civitai.red',
                mediaCleaner: expect.objectContaining({
                    strategies: ['civitaiCanonical'],
                }),
            }),
        ]));
    });

    it('falls back to the civitai.com profile on civitai.red pages', () => {
        const customization = resolveSiteCustomizationForHostname([
            {
                enabled: true,
                domain: 'civitai.com',
                matchRules: [],
                referrerCleaner: {
                    stripQueryParams: [],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: ['civitaiCanonical'],
                },
            },
        ], 'www.civitai.red');

        expect(customization).toEqual({
            enabled: true,
            domain: 'civitai.com',
            matchRules: [],
            referrerCleaner: {
                stripQueryParams: [],
            },
            mediaCleaner: {
                stripQueryParams: [],
                rewriteRules: [],
                strategies: ['civitaiCanonical'],
            },
        });
    });

    it('prefers the direct civitai.red profile over the civitai.com fallback', () => {
        const customization = resolveSiteCustomizationForHostname([
            {
                enabled: true,
                domain: 'civitai.com',
                matchRules: [],
                referrerCleaner: {
                    stripQueryParams: [],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: ['civitaiCanonical'],
                },
            },
            {
                enabled: true,
                domain: 'civitai.red',
                matchRules: ['.*\\/models\\/.*'],
                referrerCleaner: {
                    stripQueryParams: ['modelVersionId'],
                },
                mediaCleaner: {
                    stripQueryParams: ['width'],
                    rewriteRules: [],
                    strategies: ['civitaiCanonical'],
                },
            },
        ], 'www.civitai.red');

        expect(customization).toEqual({
            enabled: true,
            domain: 'civitai.red',
            matchRules: ['.*\\/models\\/.*'],
            referrerCleaner: {
                stripQueryParams: ['modelVersionId'],
            },
            mediaCleaner: {
                stripQueryParams: ['width'],
                rewriteRules: [],
                strategies: ['civitaiCanonical'],
            },
        });
    });

    it('exports and re-imports site customizations without unrelated settings', () => {
        const exported = exportSiteCustomizationsPayload([
            {
                enabled: false,
                domain: 'Example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                referrerCleaner: {
                    stripQueryParams: ['Tag'],
                },
                mediaCleaner: {
                    stripQueryParams: ['quality'],
                    rewriteRules: [
                        {
                            pattern: '/foo/',
                            replace: 'bar',
                        },
                    ],
                    strategies: [],
                },
            },
        ]);

        expect(exported).toEqual({
            version: 1,
            siteCustomizations: [
                {
                    enabled: false,
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                    referrerCleaner: {
                        stripQueryParams: ['tag'],
                    },
                    mediaCleaner: {
                        stripQueryParams: ['quality'],
                        rewriteRules: [
                            {
                                pattern: '/foo/',
                                replace: 'bar',
                            },
                        ],
                        strategies: [],
                    },
                },
            ],
        });

        expect(parseSiteCustomizationsImportJson(JSON.stringify(exported))).toEqual(exported.siteCustomizations);
    });

    it('rejects invalid customization import json', () => {
        expect(() => parseSiteCustomizationsImportJson('{')).toThrow('Invalid customization JSON.');
        expect(() => parseSiteCustomizationsImportJson(JSON.stringify({
            version: 1,
            siteCustomizations: [
                {
                    enabled: true,
                    domain: 'example.com',
                    mediaCleaner: {
                        rewriteRules: [
                            {
                                pattern: 123,
                                replace: 'bar',
                            },
                        ],
                    },
                },
            ],
        }))).toThrow('Domain "example.com" has an invalid media rewrite rule.');
    });

    it('rejects duplicate normalized domains in imports', () => {
        expect(() => parseSiteCustomizationsImportJson(JSON.stringify({
            version: 1,
            siteCustomizations: [
                {
                    enabled: true,
                    domain: 'Example.com',
                    matchRules: [],
                    referrerCleaner: {
                        stripQueryParams: [],
                    },
                    mediaCleaner: {
                        stripQueryParams: [],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
                {
                    enabled: true,
                    domain: 'https://example.com/path',
                    matchRules: [],
                    referrerCleaner: {
                        stripQueryParams: [],
                    },
                    mediaCleaner: {
                        stripQueryParams: [],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
            ],
        }))).toThrow('Domain "example.com" already exists.');
    });
});
