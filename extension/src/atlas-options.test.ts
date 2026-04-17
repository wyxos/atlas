import { beforeEach, describe, expect, it, vi } from 'vitest';

const storageState: Record<string, unknown> = {};

function installChromeStorageMock(): void {
    const runtime = {
        lastError: null as { message: string } | null,
    };

    const local = {
        get: (
            keys: string[] | string,
            callback: (items: Record<string, unknown>) => void,
        ) => {
            runtime.lastError = null;

            const requestedKeys = Array.isArray(keys) ? keys : [keys];
            const result: Record<string, unknown> = {};
            for (const key of requestedKeys) {
                result[key] = storageState[key];
            }

            callback(result);
        },
        set: (
            items: Record<string, unknown>,
            callback: () => void,
        ) => {
            runtime.lastError = null;
            Object.assign(storageState, items);
            callback();
        },
    };

    vi.stubGlobal('chrome', {
        runtime,
        storage: {
            local,
        },
    });
}

describe('atlas-options', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();

        for (const key of Object.keys(storageState)) {
            delete storageState[key];
        }

        installChromeStorageMock();
    });

    it('migrates legacy match rules and referrer cleanup into site customizations when the new key is absent', async () => {
        const { STORAGE_KEYS, getStoredOptions } = await import('./atlas-options');

        storageState[STORAGE_KEYS.matchRules] = [
            {
                domain: 'example.com',
                regexes: ['.*\\/gallery\\/.*'],
            },
        ];
        storageState[STORAGE_KEYS.referrerQueryParamsToStripByDomain] = {
            'https://example.com/path': ['tag', 'tags'],
            '.sub.example.com.': ['*', 'filter'],
            'invalid.example.com': [],
        };

        await expect(getStoredOptions()).resolves.toMatchObject({
            siteCustomizations: expect.arrayContaining([
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
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                    referrerCleaner: {
                        stripQueryParams: ['tag', 'tags'],
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
                    matchRules: [],
                    referrerCleaner: {
                        stripQueryParams: ['*'],
                    },
                    mediaCleaner: {
                        stripQueryParams: [],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
            ]),
        });
    });

    it('stores and reads normalized site customizations under the new storage key', async () => {
        const { STORAGE_KEYS, getStoredOptions, saveStoredOptions } = await import('./atlas-options');

        await saveStoredOptions('https://atlas.test', 'token', [
            {
                enabled: false,
                domain: 'https://Example.com/path',
                matchRules: [' .*\\/gallery\\/.* ', ''],
                referrerCleaner: {
                    stripQueryParams: ['Tag', 'tag'],
                },
                mediaCleaner: {
                    stripQueryParams: ['Width', 'width'],
                    rewriteRules: [
                        {
                            pattern: ' /foo/ ',
                            replace: 'bar',
                        },
                        {
                            pattern: ' /foo/ ',
                            replace: 'bar',
                        },
                    ],
                    strategies: ['civitaiCanonical', 'civitaiCanonical'],
                },
            },
        ]);

        expect(storageState[STORAGE_KEYS.siteCustomizations]).toEqual([
            {
                enabled: false,
                domain: 'example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                referrerCleaner: {
                    stripQueryParams: ['tag'],
                },
                mediaCleaner: {
                    stripQueryParams: ['width'],
                    rewriteRules: [
                        {
                            pattern: '/foo/',
                            replace: 'bar',
                        },
                    ],
                    strategies: ['civitaiCanonical'],
                },
            },
        ]);

        await expect(getStoredOptions()).resolves.toEqual({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: [
                {
                    enabled: false,
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                    referrerCleaner: {
                        stripQueryParams: ['tag'],
                    },
                    mediaCleaner: {
                        stripQueryParams: ['width'],
                        rewriteRules: [
                            {
                                pattern: '/foo/',
                                replace: 'bar',
                            },
                        ],
                        strategies: ['civitaiCanonical'],
                    },
                },
            ],
        });
    });

    it('creates or updates a stored site customization when toggling a domain from the popup', async () => {
        const {
            STORAGE_KEYS,
            setSiteCustomizationEnabledForDomain,
        } = await import('./atlas-options');

        storageState[STORAGE_KEYS.siteCustomizations] = [];
        await setSiteCustomizationEnabledForDomain('www.example.com', true);
        await setSiteCustomizationEnabledForDomain('www.example.com', false);

        expect(storageState[STORAGE_KEYS.siteCustomizations]).toEqual([
            {
                enabled: false,
                domain: 'www.example.com',
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
        ]);
    });

    it('stores and reads enabled close-tab preference per hostname', async () => {
        const {
            STORAGE_KEYS,
            getCloseTabAfterQueuePreferenceForHostname,
            setCloseTabAfterQueuePreferenceForHostname,
        } = await import('./atlas-options');

        await setCloseTabAfterQueuePreferenceForHostname('WWW.Example.com', 'queued');

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'www.example.com': 'queued',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('www.example.com')).resolves.toBe('queued');
    });

    it('removes stored close-tab preference when disabling the hostname option', async () => {
        const {
            STORAGE_KEYS,
            getCloseTabAfterQueuePreferenceForHostname,
            setCloseTabAfterQueuePreferenceForHostname,
        } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            'example.com': 'queued',
            'other.example': 'completed',
        };

        await setCloseTabAfterQueuePreferenceForHostname('example.com', 'off');

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'other.example': 'completed',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('example.com')).resolves.toBe('off');
    });

    it('sanitizes stored close-tab preferences to valid host keys and supported modes', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueueByDomain } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            '': true,
            'https://sub.example.com/path': true,
            '.valid.example.': 'completed',
            'ignored.example': 'yes',
            'disabled.example': false,
        };

        await expect(getCloseTabAfterQueueByDomain()).resolves.toEqual({
            'sub.example.com': 'queued',
            'valid.example': 'completed',
        });
    });

    it('stores and reads the react-all-items preference per hostname', async () => {
        const {
            STORAGE_KEYS,
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');

        await setReactAllItemsInPostPreferenceForHostname('WWW.Example.com', true);

        expect(storageState[STORAGE_KEYS.reactAllItemsInPostByDomain]).toEqual({
            'www.example.com': true,
        });
        await expect(getReactAllItemsInPostPreferenceForHostname('www.example.com')).resolves.toBe(true);
    });

    it('keeps react-all-items disabled for other hostnames when one hostname is enabled', async () => {
        const {
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');

        await setReactAllItemsInPostPreferenceForHostname('deviantart.com', true);

        await expect(getReactAllItemsInPostPreferenceForHostname('deviantart.com')).resolves.toBe(true);
        await expect(getReactAllItemsInPostPreferenceForHostname('x.com')).resolves.toBe(false);
    });
});
