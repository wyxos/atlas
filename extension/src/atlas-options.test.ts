import { beforeEach, describe, expect, it, vi } from 'vitest';

const storageState: Record<string, unknown> = {};

type RemoteSettingsState = {
    version: 1;
    siteCustomizations: unknown[];
    closeTabAfterQueueByDomain: Record<string, unknown>;
    reactAllItemsInPostByDomain: Record<string, unknown>;
};

function emptyRemoteSettings(): RemoteSettingsState {
    return {
        version: 1,
        siteCustomizations: [],
        closeTabAfterQueueByDomain: {},
        reactAllItemsInPostByDomain: {},
    };
}

function cloneSettings(settings: RemoteSettingsState): RemoteSettingsState {
    return JSON.parse(JSON.stringify(settings)) as RemoteSettingsState;
}

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
        remove: (
            keys: string[] | string,
            callback: () => void,
        ) => {
            runtime.lastError = null;
            const removeKeys = Array.isArray(keys) ? keys : [keys];
            for (const key of removeKeys) {
                delete storageState[key];
            }

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

function installRemoteSettingsFetchMock(initialSettings: RemoteSettingsState = emptyRemoteSettings()) {
    let remoteSettings = cloneSettings(initialSettings);
    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
        if (init?.method === 'POST') {
            const body = JSON.parse(String(init.body ?? '{}')) as { settings?: RemoteSettingsState };
            remoteSettings = cloneSettings(body.settings ?? emptyRemoteSettings());
        }

        return new Response(JSON.stringify({ settings: remoteSettings }), { status: 200 });
    });

    vi.stubGlobal('fetch', fetchMock);

    return {
        fetchMock,
        getRemoteSettings: () => cloneSettings(remoteSettings),
    };
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
        installRemoteSettingsFetchMock();
    });

    it('ports legacy local settings into the connected Atlas domain without overriding remote domains', async () => {
        const { STORAGE_KEYS, getStoredOptions } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            siteCustomizations: [
                {
                    enabled: false,
                    domain: 'example.com',
                    matchRules: ['remote-rule'],
                    widget: {
                        minImageWidth: null,
                    },
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
        });

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

        const stored = await getStoredOptions();

        expect(stored.siteCustomizations).toEqual(expect.arrayContaining([
            expect.objectContaining({
                enabled: false,
                domain: 'example.com',
                matchRules: ['remote-rule'],
            }),
            expect.objectContaining({
                enabled: true,
                domain: 'sub.example.com',
                referrerCleaner: {
                    stripQueryParams: ['*'],
                },
            }),
        ]));
        expect(storageState[STORAGE_KEYS.settingsMigrationByDomain]).toEqual({
            'https://atlas.test': true,
        });
        expect(storageState[STORAGE_KEYS.matchRules]).toBeUndefined();
        expect(storageState[STORAGE_KEYS.referrerQueryParamsToStripByDomain]).toBeUndefined();
        expect(remote.fetchMock).toHaveBeenCalledTimes(2);
        expect(remote.getRemoteSettings().siteCustomizations).toEqual(stored.siteCustomizations);
    });

    it('stores normalized site customizations in Atlas and keeps only bootstrap connection in chrome storage', async () => {
        const { STORAGE_KEYS, getStoredOptions, saveStoredOptions } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock();
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await saveStoredOptions('https://atlas.test', 'token', [
            {
                enabled: false,
                domain: 'https://Example.com/path',
                matchRules: [' .*\\/gallery\\/.* ', ''],
                widget: {
                    minImageWidth: 120.8,
                },
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

        expect(storageState[STORAGE_KEYS.atlasDomain]).toBe('https://atlas.test');
        expect(storageState[STORAGE_KEYS.apiToken]).toBe('token');
        expect(storageState[STORAGE_KEYS.siteCustomizations]).toBeUndefined();
        expect(remote.getRemoteSettings().siteCustomizations).toEqual([
            {
                enabled: false,
                domain: 'example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                widget: {
                    minImageWidth: 120,
                },
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

        await expect(getStoredOptions()).resolves.toMatchObject({
            atlasDomain: 'https://atlas.test',
            apiToken: 'token',
            siteCustomizations: remote.getRemoteSettings().siteCustomizations,
        });
    });

    it('keeps the stored bootstrap connection visible when Atlas settings cannot be loaded', async () => {
        const { STORAGE_KEYS, getStoredOptions } = await import('./atlas-options');
        const fetchMock = vi.fn(async () => new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 }));
        vi.stubGlobal('fetch', fetchMock);

        storageState[STORAGE_KEYS.atlasDomain] = 'https://atlas.wyxos.com';
        storageState[STORAGE_KEYS.apiToken] = 'existing-token';
        storageState[STORAGE_KEYS.siteCustomizations] = [
            {
                enabled: true,
                domain: 'example.com',
                matchRules: ['.*\\/gallery\\/.*'],
                widget: {
                    minImageWidth: null,
                },
                referrerCleaner: {
                    stripQueryParams: [],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: [],
                },
            },
        ];

        await expect(getStoredOptions()).resolves.toMatchObject({
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'existing-token',
            siteCustomizations: [
                expect.objectContaining({
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                }),
            ],
        });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(storageState[STORAGE_KEYS.atlasDomain]).toBe('https://atlas.wyxos.com');
        expect(storageState[STORAGE_KEYS.apiToken]).toBe('existing-token');
    });

    it('coalesces and caches remote settings reads until settings storage changes', async () => {
        const { STORAGE_KEYS, getStoredOptions } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            siteCustomizations: [
                {
                    enabled: true,
                    domain: 'example.com',
                    matchRules: ['.*\\/gallery\\/.*'],
                    widget: {
                        minImageWidth: null,
                    },
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
        });
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        const [first, second] = await Promise.all([
            getStoredOptions(),
            getStoredOptions(),
        ]);

        expect(first.siteCustomizations).toEqual(second.siteCustomizations);
        expect(remote.fetchMock).toHaveBeenCalledTimes(1);

        await getStoredOptions();
        expect(remote.fetchMock).toHaveBeenCalledTimes(1);

        storageState[STORAGE_KEYS.settingsUpdatedAt] = 'remote-refresh';
        await getStoredOptions();
        expect(remote.fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not overwrite the bootstrap connection when remote settings save fails', async () => {
        const { STORAGE_KEYS, saveStoredOptions } = await import('./atlas-options');
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401 })));

        storageState[STORAGE_KEYS.atlasDomain] = 'https://atlas.wyxos.com';
        storageState[STORAGE_KEYS.apiToken] = 'existing-token';

        await expect(saveStoredOptions('https://atlas.test', '', [])).rejects.toThrow();

        expect(storageState[STORAGE_KEYS.atlasDomain]).toBe('https://atlas.wyxos.com');
        expect(storageState[STORAGE_KEYS.apiToken]).toBe('existing-token');
    });

    it('creates or updates a remote site customization when toggling a domain from the popup', async () => {
        const {
            STORAGE_KEYS,
            setSiteCustomizationEnabledForDomain,
        } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock();
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await setSiteCustomizationEnabledForDomain('www.example.com', true);
        await setSiteCustomizationEnabledForDomain('www.example.com', false);

        expect(storageState[STORAGE_KEYS.siteCustomizations]).toBeUndefined();
        expect(remote.getRemoteSettings().siteCustomizations).toEqual([
            {
                enabled: false,
                domain: 'www.example.com',
                matchRules: [],
                widget: {
                    minImageWidth: null,
                },
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

    it('stores and reads close-tab preferences from Atlas per hostname', async () => {
        const {
            STORAGE_KEYS,
            getCloseTabAfterQueuePreferenceForHostname,
            setCloseTabAfterQueuePreferenceForHostname,
        } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock();
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await setCloseTabAfterQueuePreferenceForHostname('WWW.Example.com', 'queued');

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toBeUndefined();
        expect(remote.getRemoteSettings().closeTabAfterQueueByDomain).toEqual({
            'www.example.com': 'queued',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('www.example.com')).resolves.toBe('queued');
    });

    it('removes remote close-tab preference when disabling the hostname option', async () => {
        const {
            STORAGE_KEYS,
            getCloseTabAfterQueuePreferenceForHostname,
            setCloseTabAfterQueuePreferenceForHostname,
        } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            closeTabAfterQueueByDomain: {
                'example.com': 'queued',
                'other.example': 'completed',
            },
        });
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await setCloseTabAfterQueuePreferenceForHostname('example.com', 'off');

        expect(remote.getRemoteSettings().closeTabAfterQueueByDomain).toEqual({
            'other.example': 'completed',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('example.com')).resolves.toBe('off');
    });

    it('sanitizes remote close-tab preferences to valid host keys and supported modes', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueueByDomain } = await import('./atlas-options');
        installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            closeTabAfterQueueByDomain: {
                '': true,
                'https://sub.example.com/path': true,
                '.valid.example.': 'completed',
                'ignored.example': 'yes',
                'disabled.example': false,
            },
        });
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await expect(getCloseTabAfterQueueByDomain()).resolves.toEqual({
            'sub.example.com': 'queued',
            'valid.example': 'completed',
        });
    });

    it('stores and reads react-all-items preferences from Atlas per hostname', async () => {
        const {
            STORAGE_KEYS,
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock();
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await setReactAllItemsInPostPreferenceForHostname('WWW.Example.com', true);

        expect(storageState[STORAGE_KEYS.reactAllItemsInPostByDomain]).toBeUndefined();
        expect(remote.getRemoteSettings().reactAllItemsInPostByDomain).toEqual({
            'www.example.com': true,
        });
        await expect(getReactAllItemsInPostPreferenceForHostname('www.example.com')).resolves.toBe(true);
    });

    it('keeps react-all-items disabled for other hostnames when one hostname is enabled', async () => {
        const {
            STORAGE_KEYS,
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.test': true,
        };

        await setReactAllItemsInPostPreferenceForHostname('deviantart.com', true);

        await expect(getReactAllItemsInPostPreferenceForHostname('deviantart.com')).resolves.toBe(true);
        await expect(getReactAllItemsInPostPreferenceForHostname('x.com')).resolves.toBe(false);
    });
});
