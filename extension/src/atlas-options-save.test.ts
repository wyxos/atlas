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

    vi.stubGlobal('chrome', {
        runtime,
        storage: {
            local: {
                get: (keys: string[] | string, callback: (items: Record<string, unknown>) => void) => {
                    runtime.lastError = null;
                    const result: Record<string, unknown> = {};
                    for (const key of Array.isArray(keys) ? keys : [keys]) {
                        result[key] = storageState[key];
                    }
                    callback(result);
                },
                set: (items: Record<string, unknown>, callback: () => void) => {
                    runtime.lastError = null;
                    Object.assign(storageState, items);
                    callback();
                },
                remove: (keys: string[] | string, callback: () => void) => {
                    runtime.lastError = null;
                    for (const key of Array.isArray(keys) ? keys : [keys]) {
                        delete storageState[key];
                    }
                    callback();
                },
            },
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

describe('atlas-options save boundaries', () => {
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

    it('saves a fresh production connection without posting local default profiles over remote profiles', async () => {
        const { STORAGE_KEYS, saveStoredConnectionOptions } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            siteCustomizations: [
                {
                    enabled: false,
                    domain: 'production.example.com',
                    matchRules: ['remote-rule'],
                    widget: {
                        minImageWidth: 220,
                    },
                    referrerCleaner: {
                        stripQueryParams: ['token'],
                    },
                    mediaCleaner: {
                        stripQueryParams: ['quality'],
                        rewriteRules: [],
                        strategies: [],
                    },
                },
            ],
        });

        const stored = await saveStoredConnectionOptions('https://atlas.wyxos.com///', ' production-token ');

        expect(stored.atlasDomain).toBe('https://atlas.wyxos.com');
        expect(stored.apiToken).toBe('production-token');
        expect(stored.siteCustomizations).toEqual([
            expect.objectContaining({
                domain: 'production.example.com',
                matchRules: ['remote-rule'],
            }),
        ]);
        expect(storageState[STORAGE_KEYS.atlasDomain]).toBe('https://atlas.wyxos.com');
        expect(storageState[STORAGE_KEYS.apiToken]).toBe('production-token');
        expect(remote.fetchMock).toHaveBeenCalledTimes(1);
        expect(remote.fetchMock.mock.calls[0]?.[1]).toMatchObject({
            method: 'GET',
        });
        expect(remote.getRemoteSettings().siteCustomizations).toEqual(stored.siteCustomizations);
    });

    it('saves profile changes for the current connection while preserving other remote settings', async () => {
        const { STORAGE_KEYS, saveSiteCustomizationsForCurrentConnection } = await import('./atlas-options');
        const remote = installRemoteSettingsFetchMock({
            ...emptyRemoteSettings(),
            siteCustomizations: [
                {
                    enabled: true,
                    domain: 'existing.example.com',
                    matchRules: ['old-rule'],
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
            closeTabAfterQueueByDomain: {
                'existing.example.com': 'completed',
            },
            reactAllItemsInPostByDomain: {
                'existing.example.com': true,
            },
        });
        storageState[STORAGE_KEYS.atlasDomain] = 'https://atlas.wyxos.com';
        storageState[STORAGE_KEYS.apiToken] = 'production-token';
        storageState[STORAGE_KEYS.settingsMigrationByDomain] = {
            'https://atlas.wyxos.com': true,
        };

        const stored = await saveSiteCustomizationsForCurrentConnection([
            {
                enabled: false,
                domain: 'https://Updated.example.com/path',
                matchRules: [' new-rule '],
                widget: {
                    minImageWidth: 120,
                },
                referrerCleaner: {
                    stripQueryParams: ['Tag'],
                },
                mediaCleaner: {
                    stripQueryParams: [],
                    rewriteRules: [],
                    strategies: [],
                },
            },
        ]);

        expect(stored.siteCustomizations).toEqual([
            expect.objectContaining({
                enabled: false,
                domain: 'updated.example.com',
                matchRules: ['new-rule'],
            }),
        ]);
        expect(remote.getRemoteSettings()).toMatchObject({
            closeTabAfterQueueByDomain: {
                'existing.example.com': 'completed',
            },
            reactAllItemsInPostByDomain: {
                'existing.example.com': true,
            },
        });
        expect(remote.fetchMock.mock.calls.map((call) => call[1]?.method)).toEqual(['GET', 'POST']);
    });
});
