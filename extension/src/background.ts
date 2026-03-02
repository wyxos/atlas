import {
    EXTENSION_RELOAD_REQUIRED_EVENT,
    GET_EXTENSION_RELOAD_STATE_EVENT,
    type ExtensionReloadRequiredMessage,
    type ExtensionReloadStateResponse,
} from './reload-required-message';

type TabPresenceChangedMessage = {
    type: 'ATLAS_TAB_PRESENCE_CHANGED';
    urls: string[];
};
type RuntimeCookie = {
    name: string;
    value: string;
    domain: string;
    path: string;
    secure: boolean;
    http_only: boolean;
    host_only: boolean;
    expires_at: number | null;
};
type BrowserCookie = {
    name?: unknown;
    value?: unknown;
    domain?: unknown;
    path?: unknown;
    secure?: unknown;
    httpOnly?: unknown;
    hostOnly?: unknown;
    expirationDate?: unknown;
};

type RuntimeMessageSender = {
    tab?: {
        id?: number;
    };
};

type SubmitReactionPayload = {
    type: 'ATLAS_SUBMIT_REACTION';
    atlasDomain: string;
    apiToken: string;
    body: Record<string, unknown>;
};

type BrowserTab = {
    id?: number;
    url?: string;
};

const EXTENSION_RELOAD_REQUIRED_STORAGE_KEY = 'atlasExtensionReloadRequired';
const openComparableUrlByTabId = new Map<number, string>();

function normalizeComparableUrl(value: string): string | null {
    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        const parsed = new URL(trimmed);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return null;
        }
        parsed.hash = '';
        return parsed.toString();
    } catch {
        return null;
    }
}

function normalizeComparableUrls(values: unknown): string[] {
    if (!Array.isArray(values)) {
        return [];
    }

    const normalized = values
        .map((value) => (typeof value === 'string' ? normalizeComparableUrl(value) : null))
        .filter((value): value is string => value !== null);

    return Array.from(new Set(normalized));
}

function mapRuntimeCookie(cookie: BrowserCookie): RuntimeCookie | null {
    const name = typeof cookie.name === 'string' ? cookie.name.trim() : '';
    const value = typeof cookie.value === 'string' ? cookie.value : '';
    const domain = typeof cookie.domain === 'string' ? cookie.domain.trim().toLowerCase() : '';
    const path = typeof cookie.path === 'string' && cookie.path.trim() !== '' ? cookie.path.trim() : '/';
    const expiresAt = typeof cookie.expirationDate === 'number' && Number.isFinite(cookie.expirationDate)
        ? Math.floor(cookie.expirationDate)
        : null;

    if (name === '' || domain === '') {
        return null;
    }

    return {
        name,
        value,
        domain,
        path: path.startsWith('/') ? path : `/${path}`,
        secure: cookie.secure === true,
        http_only: cookie.httpOnly === true,
        host_only: cookie.hostOnly === true,
        expires_at: expiresAt,
    };
}

function readCookiesForUrl(url: string): Promise<RuntimeCookie[]> {
    return new Promise((resolve) => {
        if (!chrome.cookies || typeof chrome.cookies.getAll !== 'function') {
            resolve([]);
            return;
        }

        chrome.cookies.getAll({ url }, (cookies: unknown) => {
            if (chrome.runtime.lastError || !Array.isArray(cookies)) {
                resolve([]);
                return;
            }

            const mapped = cookies
                .map((cookie) => mapRuntimeCookie(cookie as BrowserCookie))
                .filter((cookie): cookie is RuntimeCookie => cookie !== null);

            resolve(mapped);
        });
    });
}

async function collectCookiesForUrls(urls: string[]): Promise<RuntimeCookie[]> {
    if (urls.length === 0) {
        return [];
    }

    const byKey = new Map<string, RuntimeCookie>();
    const results = await Promise.all(urls.map((url) => readCookiesForUrl(url)));

    for (const cookies of results) {
        for (const cookie of cookies) {
            const key = [
                cookie.domain,
                cookie.path,
                cookie.name,
                cookie.value,
                cookie.secure ? '1' : '0',
                cookie.http_only ? '1' : '0',
                cookie.host_only ? '1' : '0',
                cookie.expires_at === null ? 'null' : String(cookie.expires_at),
            ].join('|');

            if (!byKey.has(key)) {
                byKey.set(key, cookie);
            }
        }
    }

    return Array.from(byKey.values());
}

function readExtensionReloadRequired(): Promise<boolean> {
    return new Promise((resolve) => {
        if (!chrome.storage?.local) {
            resolve(false);
            return;
        }

        chrome.storage.local.get(EXTENSION_RELOAD_REQUIRED_STORAGE_KEY, (row: unknown) => {
            if (chrome.runtime.lastError || !row || typeof row !== 'object') {
                resolve(false);
                return;
            }

            const value = (row as Record<string, unknown>)[EXTENSION_RELOAD_REQUIRED_STORAGE_KEY];
            resolve(value === true);
        });
    });
}

function writeExtensionReloadRequired(value: boolean): Promise<void> {
    return new Promise((resolve) => {
        if (!chrome.storage?.local) {
            resolve();
            return;
        }

        chrome.storage.local.set({ [EXTENSION_RELOAD_REQUIRED_STORAGE_KEY]: value }, () => {
            resolve();
        });
    });
}

function broadcastExtensionReloadRequired(): void {
    const message: ExtensionReloadRequiredMessage = {
        type: EXTENSION_RELOAD_REQUIRED_EVENT,
    };

    chrome.runtime.sendMessage(message, () => {
        void chrome.runtime.lastError;
    });

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        tabs.forEach((tab) => {
            if (typeof tab.id !== 'number') {
                return;
            }

            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
    });
}

function updateTrackedComparableTabUrl(tabId: number, nextComparableUrl: string | null): string[] {
    const previousComparableUrl = openComparableUrlByTabId.get(tabId) ?? null;
    if (nextComparableUrl === null) {
        openComparableUrlByTabId.delete(tabId);
    } else {
        openComparableUrlByTabId.set(tabId, nextComparableUrl);
    }

    if (previousComparableUrl === nextComparableUrl) {
        return [];
    }

    return Array.from(new Set([previousComparableUrl, nextComparableUrl].filter((url): url is string => url !== null)));
}

function broadcastTabPresenceChanged(urls: string[]): void {
    if (urls.length === 0) {
        return;
    }

    const dedupedUrls = Array.from(new Set(urls));

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        tabs.forEach((tab) => {
            if (typeof tab.id !== 'number') {
                return;
            }

            const message: TabPresenceChangedMessage = {
                type: 'ATLAS_TAB_PRESENCE_CHANGED',
                urls: dedupedUrls,
            };
            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
    });
}

function initializeTrackedTabUrls(): void {
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        for (const tab of tabs) {
            if (typeof tab.id !== 'number') {
                continue;
            }

            const comparableUrl = typeof tab.url === 'string' ? normalizeComparableUrl(tab.url) : null;
            if (comparableUrl === null) {
                openComparableUrlByTabId.delete(tab.id);
                continue;
            }

            openComparableUrlByTabId.set(tab.id, comparableUrl);
        }
    });
}

chrome.runtime.onMessage.addListener((
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: (response?: unknown) => void,
) => {
    if (typeof message !== 'object' || message === null) {
        return false;
    }

    const payload = message as { type?: unknown; url?: unknown; urls?: unknown };
    if (payload.type === GET_EXTENSION_RELOAD_STATE_EVENT) {
        void readExtensionReloadRequired()
            .then((requiresReload) => {
                const response: ExtensionReloadStateResponse = { requiresReload };
                sendResponse(response);
            })
            .catch(() => {
                const response: ExtensionReloadStateResponse = { requiresReload: false };
                sendResponse(response);
            });

        return true;
    }

    if (payload.type === 'ATLAS_GET_URL_COOKIES') {
        const urls = normalizeComparableUrls(payload.urls);
        if (urls.length === 0) {
            sendResponse({ cookies: [] });
            return false;
        }

        void collectCookiesForUrls(urls)
            .then((cookies) => {
                sendResponse({ cookies });
            })
            .catch(() => {
                sendResponse({ cookies: [] });
            });

        return true;
    }

    if (payload.type === 'ATLAS_SUBMIT_REACTION') {
        const submitPayload = message as SubmitReactionPayload;
        const atlasDomain = typeof submitPayload.atlasDomain === 'string' ? submitPayload.atlasDomain.trim().replace(/\/+$/, '') : '';
        const apiToken = typeof submitPayload.apiToken === 'string' ? submitPayload.apiToken.trim() : '';
        const body = submitPayload.body;
        if (atlasDomain === '' || apiToken === '' || typeof body !== 'object' || body === null) {
            sendResponse({ ok: false, status: 0, payload: null });
            return false;
        }

        void fetch(`${atlasDomain}/api/extension/reactions`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': apiToken,
            },
            body: JSON.stringify(body),
            keepalive: true,
        })
            .then(async (response) => {
                let responsePayload: unknown = null;
                try {
                    responsePayload = await response.json();
                } catch {
                    responsePayload = null;
                }

                sendResponse({
                    ok: response.ok,
                    status: response.status,
                    payload: responsePayload,
                });
            })
            .catch(() => {
                sendResponse({ ok: false, status: 0, payload: null });
            });

        return true;
    }

    if (payload.type !== 'ATLAS_IS_URL_OPEN' || typeof payload.url !== 'string') {
        return false;
    }

    const target = normalizeComparableUrl(payload.url);
    if (target === null) {
        sendResponse({ isOpenInAnotherTab: false });
        return false;
    }

    const senderTabId = sender.tab?.id;

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        const isOpenInAnotherTab = tabs.some((tab) => {
            if (tab.id === undefined || tab.id === senderTabId || typeof tab.url !== 'string') {
                return false;
            }

            const tabUrl = normalizeComparableUrl(tab.url);
            return tabUrl !== null && tabUrl === target;
        });

        sendResponse({ isOpenInAnotherTab });
    });

    return true;
});

chrome.runtime.onInstalled.addListener(() => {
    void writeExtensionReloadRequired(false);
});

chrome.runtime.onUpdateAvailable.addListener(() => {
    void writeExtensionReloadRequired(true).finally(() => {
        broadcastExtensionReloadRequired();
    });
});

chrome.tabs.onCreated.addListener((tab: BrowserTab) => {
    if (typeof tab.id !== 'number') {
        return;
    }

    const comparableUrl = typeof tab.url === 'string' ? normalizeComparableUrl(tab.url) : null;
    const changedUrls = updateTrackedComparableTabUrl(tab.id, comparableUrl);
    broadcastTabPresenceChanged(changedUrls);
});

chrome.tabs.onRemoved.addListener((tabId: number) => {
    const changedUrls = updateTrackedComparableTabUrl(tabId, null);
    broadcastTabPresenceChanged(changedUrls);
});

chrome.tabs.onUpdated.addListener((tabId: number, changeInfo: { url?: string; status?: string }, tab: BrowserTab) => {
    if (typeof changeInfo.url === 'string' || changeInfo.status === 'complete') {
        const sourceUrl = typeof changeInfo.url === 'string' ? changeInfo.url : tab.url ?? null;
        const comparableUrl = sourceUrl === null ? null : normalizeComparableUrl(sourceUrl);
        const changedUrls = updateTrackedComparableTabUrl(tabId, comparableUrl);
        broadcastTabPresenceChanged(changedUrls);
    }
});

initializeTrackedTabUrls();
