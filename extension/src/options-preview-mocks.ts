import type { DownloadProgressDebugSnapshot } from './background-download-progress';
import type { ExtensionSettings } from './atlas-options';
import type { SiteCustomization } from './site-customizations';

type ChromeStorageChange = {
    oldValue?: unknown;
    newValue?: unknown;
};

type ChromeStorageListener = (
    changes: Record<string, ChromeStorageChange>,
    areaName: 'local',
) => void;

type RuntimeMessage = {
    type?: unknown;
    method?: unknown;
    body?: unknown;
    endpoint?: unknown;
};

type RuntimeResponse = {
    ok: boolean;
    status?: number;
    payload?: unknown;
    snapshot?: DownloadProgressDebugSnapshot;
};

type ChromeMock = {
    runtime: {
        lastError: { message: string } | null;
        getManifest: () => { version: string };
        getURL: (path: string) => string;
        sendMessage: (message: RuntimeMessage, callback?: (response: RuntimeResponse) => void) => void;
    };
    storage: {
        local: {
            get: (
                keys: string[] | string | Record<string, unknown> | null,
                callback: (items: Record<string, unknown>) => void,
            ) => void;
            set: (items: Record<string, unknown>, callback?: () => void) => void;
            remove: (keys: readonly string[] | string, callback?: () => void) => void;
        };
        onChanged: {
            addListener: (listener: ChromeStorageListener) => void;
            removeListener: (listener: ChromeStorageListener) => void;
        };
    };
};

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

function createPreviewSiteCustomizations(): SiteCustomization[] {
    return [
        {
            enabled: true,
            domain: 'civitai.com',
            matchRules: [
                '^https://(?:www\\.)?civitai\\.com/images/\\d+',
                '^https://(?:www\\.)?civitai\\.com/models/\\d+',
            ],
            widget: {
                minImageWidth: 180,
            },
            referrerCleaner: {
                stripQueryParams: ['modelVersionId', 'postId'],
            },
            mediaCleaner: {
                stripQueryParams: ['width', 'format'],
                rewriteRules: [
                    {
                        pattern: '/width=\\d+/',
                        replace: 'width=original',
                    },
                ],
                strategies: ['civitaiCanonical'],
            },
        },
        {
            enabled: false,
            domain: 'deviantart.com',
            matchRules: [
                '^https://(?:www\\.)?deviantart\\.com/.+/art/.+',
            ],
            widget: {
                minImageWidth: null,
            },
            referrerCleaner: {
                stripQueryParams: ['ga_submit_new', 'ga_type'],
            },
            mediaCleaner: {
                stripQueryParams: ['token'],
                rewriteRules: [],
                strategies: [],
            },
        },
        {
            enabled: true,
            domain: 'wallhaven.cc',
            matchRules: [],
            widget: {
                minImageWidth: 240,
            },
            referrerCleaner: {
                stripQueryParams: [],
            },
            mediaCleaner: {
                stripQueryParams: ['thumb'],
                rewriteRules: [],
                strategies: [],
            },
        },
    ];
}

function createPreviewSettings(): ExtensionSettings {
    return {
        version: 1,
        siteCustomizations: createPreviewSiteCustomizations(),
        closeTabAfterQueueByDomain: {
            'civitai.com': 'completed',
        },
        reactAllItemsInPostByDomain: {
            'civitai.com': true,
        },
    };
}

function createPreviewDownloadProgressSnapshot(): DownloadProgressDebugSnapshot {
    return {
        subscriberTabCount: 2,
        connectionState: 'connected',
        connectionDetail: null,
        recentEvents: [
            {
                id: 1,
                receivedAt: new Date().toISOString(),
                event: {
                    event: 'DownloadTransferProgressUpdated',
                    fileId: 4201,
                    transferId: 8842,
                    sourceUrl: 'https://civitai.com/images/4201',
                    referrerUrl: 'https://civitai.com/models/73',
                    status: 'downloading',
                    percent: 68,
                    reaction: 'love',
                    reactedAt: new Date().toISOString(),
                    downloadedAt: null,
                    blacklistedAt: null,
                    payload: {
                        downloadTransferId: 8842,
                        fileId: 4201,
                        status: 'downloading',
                        percent: 68,
                    },
                },
            },
            {
                id: 2,
                receivedAt: new Date(Date.now() - 75000).toISOString(),
                event: {
                    event: 'DownloadTransferQueued',
                    fileId: 4200,
                    transferId: 8841,
                    sourceUrl: 'https://wallhaven.cc/w/atlas',
                    referrerUrl: 'https://wallhaven.cc',
                    status: 'queued',
                    percent: 0,
                    reaction: null,
                    reactedAt: null,
                    downloadedAt: null,
                    blacklistedAt: null,
                    payload: {
                        id: 8841,
                        fileId: 4200,
                        status: 'queued',
                    },
                },
            },
        ],
    };
}

function storageKeysFor(
    keys: string[] | string | Record<string, unknown> | null,
    storageState: Record<string, unknown>,
): Record<string, unknown> {
    if (keys === null) {
        return { ...storageState };
    }

    if (typeof keys === 'string') {
        return { [keys]: storageState[keys] };
    }

    if (Array.isArray(keys)) {
        const selected: Record<string, unknown> = {};
        for (const key of keys) {
            selected[key] = storageState[key];
        }

        return selected;
    }

    const selected: Record<string, unknown> = {};
    for (const [key, fallback] of Object.entries(keys)) {
        selected[key] = storageState[key] ?? fallback;
    }

    return selected;
}

function createRuntimeApiResponse(
    message: RuntimeMessage,
    settings: ExtensionSettings,
): RuntimeResponse | null {
    if (message.type !== 'ATLAS_API_REQUEST') {
        return null;
    }

    const endpoint = typeof message.endpoint === 'string' ? message.endpoint : '';
    if (endpoint.endsWith('/api/extension/ping')) {
        return {
            ok: true,
            status: 200,
            payload: {
                reverb: {
                    enabled: false,
                    key: 'preview-key',
                    host: 'atlas.test',
                    port: 443,
                    scheme: 'https',
                    channel: 'private-downloads',
                },
            },
        };
    }

    if (!endpoint.endsWith('/api/extension/settings')) {
        return {
            ok: true,
            status: 200,
            payload: null,
        };
    }

    if (message.method === 'POST' && message.body && typeof message.body === 'object') {
        const body = message.body as { settings?: ExtensionSettings };
        if (body.settings) {
            Object.assign(settings, clone(body.settings));
        }
    }

    return {
        ok: true,
        status: 200,
        payload: {
            settings: clone(settings),
        },
    };
}

function installChromePreviewMock(): ChromeMock {
    const settings = createPreviewSettings();
    const storageState: Record<string, unknown> = {
        atlasDomain: 'https://atlas.test',
        apiToken: 'preview-api-token',
        siteCustomizations: clone(settings.siteCustomizations),
        settingsMigrationByDomain: {
            'https://atlas.test': true,
        },
        settingsUpdatedAt: String(Date.now()),
    };
    let debugSnapshot = createPreviewDownloadProgressSnapshot();
    const storageListeners = new Set<ChromeStorageListener>();

    const chromeMock: ChromeMock = {
        runtime: {
            lastError: null,
            getManifest: () => ({
                version: 'preview',
            }),
            getURL: (path: string) => `chrome-extension://atlas-preview/${path.replace(/^\/+/, '')}`,
            sendMessage: (message: RuntimeMessage, callback?: (response: RuntimeResponse) => void) => {
                chromeMock.runtime.lastError = null;

                const apiResponse = createRuntimeApiResponse(message, settings);
                if (apiResponse !== null) {
                    callback?.(apiResponse);
                    return;
                }

                if (message.type === 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE') {
                    callback?.({
                        ok: true,
                        snapshot: clone(debugSnapshot),
                    });
                    return;
                }

                if (message.type === 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE') {
                    debugSnapshot = {
                        ...debugSnapshot,
                        recentEvents: [],
                    };
                    callback?.({ ok: true });
                    return;
                }

                callback?.({
                    ok: true,
                    status: 200,
                    payload: null,
                });
            },
        },
        storage: {
            local: {
                get: (keys, callback) => {
                    chromeMock.runtime.lastError = null;
                    callback(storageKeysFor(keys, storageState));
                },
                set: (items, callback) => {
                    chromeMock.runtime.lastError = null;
                    const changes: Record<string, ChromeStorageChange> = {};
                    for (const [key, value] of Object.entries(items)) {
                        changes[key] = {
                            oldValue: storageState[key],
                            newValue: value,
                        };
                        storageState[key] = value;
                    }

                    for (const listener of storageListeners) {
                        listener(changes, 'local');
                    }

                    callback?.();
                },
                remove: (keys, callback) => {
                    chromeMock.runtime.lastError = null;
                    const removeKeys = Array.isArray(keys) ? keys : [keys];
                    const changes: Record<string, ChromeStorageChange> = {};
                    for (const key of removeKeys) {
                        changes[key] = {
                            oldValue: storageState[key],
                            newValue: undefined,
                        };
                        delete storageState[key];
                    }

                    for (const listener of storageListeners) {
                        listener(changes, 'local');
                    }

                    callback?.();
                },
            },
            onChanged: {
                addListener: (listener) => {
                    storageListeners.add(listener);
                },
                removeListener: (listener) => {
                    storageListeners.delete(listener);
                },
            },
        },
    };

    return chromeMock;
}

export function installExtensionOptionsPreviewMocks(): void {
    const chromeMock = installChromePreviewMock();
    const browserGlobal = globalThis as typeof globalThis & { chrome?: Partial<ChromeMock> };

    if (browserGlobal.chrome && typeof browserGlobal.chrome === 'object') {
        Object.assign(browserGlobal.chrome, chromeMock);
        return;
    }

    Object.defineProperty(browserGlobal, 'chrome', {
        configurable: true,
        value: chromeMock,
    });
}
