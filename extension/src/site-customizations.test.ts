import { describe, expect, it } from 'vitest';
import {
    exportSiteCustomizationsPayload,
    parseSiteCustomizationsImportJson,
    resolveSiteCustomizationForHostname,
} from './site-customizations';

describe('site-customizations', () => {
    it('resolves the most specific customization for the active page hostname', () => {
        const customization = resolveSiteCustomizationForHostname([
            {
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

    it('exports and re-imports site customizations without unrelated settings', () => {
        const exported = exportSiteCustomizationsPayload([
            {
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
