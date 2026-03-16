import {
    handleDownloadProgressRuntimeMessage,
    removeDownloadProgressSubscriber,
} from './background-download-progress';
import { collectCookiesForUrls } from './background-cookie-runtime';
import { notifyTabsExtensionReloaded } from './background-reload-overlay';
import { normalizeComparableUrls } from './background-url-utils';
import { normalizeComparableOpenTabUrl } from './open-tab-url';

type TabPresenceChangedMessage = {
    type: 'ATLAS_TAB_PRESENCE_CHANGED';
    urls: string[];
    counts: Record<string, number>;
};

type TabCountChangedMessage = {
    type: 'ATLAS_TAB_COUNT_CHANGED';
    count: number;
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
    endpoint: string;
    body: Record<string, unknown>;
};

type AtlasApiRequestPayload = {
    type: 'ATLAS_API_REQUEST';
    atlasDomain: string;
    apiToken: string;
    endpoint: string;
    method: 'GET' | 'POST';
    body?: Record<string, unknown> | null;
};

type BrowserTab = {
    id?: number;
    url?: string;
    active?: boolean;
    discarded?: boolean;
};

const openComparableUrlByTabId = new Map<number, string>();
const openComparableUrlCountByUrl = new Map<string, number>();
let discardInactiveTabsInFlight: Promise<{ discardedCount: number; failedCount: number; skippedCount: number }> | null = null;

function parseJsonResponse(response: Response): Promise<unknown> {
    return response.text()
        .then((bodyText) => {
            const trimmed = bodyText.trim();
            if (trimmed === '') {
                return null;
            }

            try {
                return JSON.parse(trimmed) as unknown;
            } catch {
                return bodyText;
            }
        })
        .catch(() => null);
}

function isAllowedAtlasApiEndpoint(
    atlasDomain: string,
    endpoint: string,
    method: 'GET' | 'POST',
): boolean {
    if (atlasDomain === '') {
        return false;
    }

    if (method === 'GET') {
        return endpoint === `${atlasDomain}/api/extension/ping`;
    }

    return endpoint === `${atlasDomain}/api/extension/badges/checks`
        || endpoint === `${atlasDomain}/api/extension/referrer-checks`;
}

function updateTrackedComparableTabUrl(tabId: number, nextComparableUrl: string | null): string[] {
    const previousComparableUrl = openComparableUrlByTabId.get(tabId) ?? null;
    if (previousComparableUrl === nextComparableUrl) {
        return [];
    }

    if (previousComparableUrl !== null) {
        const previousCount = (openComparableUrlCountByUrl.get(previousComparableUrl) ?? 0) - 1;
        if (previousCount > 0) {
            openComparableUrlCountByUrl.set(previousComparableUrl, previousCount);
        } else {
            openComparableUrlCountByUrl.delete(previousComparableUrl);
        }
    }

    if (nextComparableUrl === null) {
        openComparableUrlByTabId.delete(tabId);
    } else {
        openComparableUrlByTabId.set(tabId, nextComparableUrl);
        openComparableUrlCountByUrl.set(nextComparableUrl, (openComparableUrlCountByUrl.get(nextComparableUrl) ?? 0) + 1);
    }

    return Array.from(new Set([previousComparableUrl, nextComparableUrl].filter((url): url is string => url !== null)));
}

function getComparableOpenTabCounts(urls?: string[]): Record<string, number> {
    const counts: Record<string, number> = {};

    if (Array.isArray(urls)) {
        for (const url of Array.from(new Set(urls))) {
            counts[url] = openComparableUrlCountByUrl.get(url) ?? 0;
        }

        return counts;
    }

    for (const [url, count] of openComparableUrlCountByUrl.entries()) {
        counts[url] = count;
    }

    return counts;
}

function getComparableOpenTabUrls(): string[] {
    return Array.from(openComparableUrlByTabId.values());
}

function broadcastTabPresenceChanged(urls: string[]): void {
    if (urls.length === 0) {
        return;
    }

    const dedupedUrls = Array.from(new Set(urls));
    const counts = getComparableOpenTabCounts(dedupedUrls);
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        tabs.forEach((tab) => {
            if (typeof tab.id !== 'number') {
                return;
            }

            const message: TabPresenceChangedMessage = {
                type: 'ATLAS_TAB_PRESENCE_CHANGED',
                urls: dedupedUrls,
                counts,
            };
            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
    });
}

function broadcastTabCountChanged(): void {
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        const count = Array.isArray(tabs) ? tabs.length : 0;

        tabs.forEach((tab) => {
            if (typeof tab.id !== 'number') {
                return;
            }

            const message: TabCountChangedMessage = {
                type: 'ATLAS_TAB_COUNT_CHANGED',
                count,
            };
            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
    });
}

function initializeTrackedTabUrls(): void {
    openComparableUrlByTabId.clear();
    openComparableUrlCountByUrl.clear();

    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        for (const tab of tabs) {
            if (typeof tab.id !== 'number') {
                continue;
            }

            const comparableUrl = typeof tab.url === 'string' ? normalizeComparableOpenTabUrl(tab.url) : null;
            updateTrackedComparableTabUrl(tab.id, comparableUrl);
        }
    });
}

function discardTab(tabId: number): Promise<boolean> {
    return new Promise((resolve) => {
        chrome.tabs.discard(tabId, (discardedTab: BrowserTab | undefined) => {
            resolve(!chrome.runtime.lastError && discardedTab !== undefined);
        });
    });
}

async function discardInactiveTabs(): Promise<{ discardedCount: number; failedCount: number; skippedCount: number }> {
    const tabs = await new Promise<BrowserTab[]>((resolve) => {
        chrome.tabs.query({}, (items: BrowserTab[]) => {
            if (chrome.runtime.lastError || !Array.isArray(items)) {
                resolve([]);
                return;
            }

            resolve(items);
        });
    });

    const candidateTabs = tabs.filter((tab): tab is BrowserTab & { id: number } => {
        return typeof tab.id === 'number' && tab.active !== true;
    });

    let discardedCount = 0;
    let failedCount = 0;
    let skippedCount = 0;

    await Promise.all(candidateTabs.map(async (tab) => {
        if (tab.discarded === true) {
            skippedCount += 1;
            return;
        }

        const didDiscard = await discardTab(tab.id);
        if (didDiscard) {
            discardedCount += 1;
            return;
        }

        failedCount += 1;
    }));

    return { discardedCount, failedCount, skippedCount };
}

function discardInactiveTabsOnce(): Promise<{ discardedCount: number; failedCount: number; skippedCount: number }> {
    if (discardInactiveTabsInFlight !== null) {
        return discardInactiveTabsInFlight;
    }

    discardInactiveTabsInFlight = discardInactiveTabs().finally(() => {
        discardInactiveTabsInFlight = null;
    });
    return discardInactiveTabsInFlight;
}

chrome.runtime.onMessage.addListener((
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: (response?: unknown) => void,
) => {
    if (typeof message !== 'object' || message === null) {
        return false;
    }

    if (handleDownloadProgressRuntimeMessage(message, sender, sendResponse)) {
        return false;
    }

    const payload = message as { type?: unknown; url?: unknown; urls?: unknown };
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
        const endpoint = typeof submitPayload.endpoint === 'string' ? submitPayload.endpoint.trim() : '';
        const body = submitPayload.body;
        const isAllowedEndpoint = endpoint === `${atlasDomain}/api/extension/reactions`
            || endpoint === `${atlasDomain}/api/extension/reactions/batch`;
        if (atlasDomain === '' || apiToken === '' || !isAllowedEndpoint || typeof body !== 'object' || body === null) {
            sendResponse({ ok: false, status: 0, payload: null });
            return false;
        }

        const bodyJson = JSON.stringify(body);
        void fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': apiToken,
            },
            body: bodyJson,
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

    if (payload.type === 'ATLAS_API_REQUEST') {
        const requestPayload = message as AtlasApiRequestPayload;
        const atlasDomain = typeof requestPayload.atlasDomain === 'string' ? requestPayload.atlasDomain.trim().replace(/\/+$/, '') : '';
        const apiToken = typeof requestPayload.apiToken === 'string' ? requestPayload.apiToken.trim() : '';
        const endpoint = typeof requestPayload.endpoint === 'string' ? requestPayload.endpoint.trim() : '';
        const method = requestPayload.method === 'POST' ? 'POST' : requestPayload.method === 'GET' ? 'GET' : null;
        const body = requestPayload.body;
        const requiresBody = method === 'POST';

        if (
            method === null
            || apiToken === ''
            || !isAllowedAtlasApiEndpoint(atlasDomain, endpoint, method)
            || (requiresBody && (typeof body !== 'object' || body === null))
        ) {
            sendResponse({ ok: false, status: 0, payload: null });
            return false;
        }

        const headers: Record<string, string> = {
            'X-Atlas-Api-Key': apiToken,
        };
        const init: RequestInit = {
            method,
            headers,
        };

        if (method === 'POST') {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        void fetch(endpoint, init)
            .then(async (response) => {
                sendResponse({
                    ok: response.ok,
                    status: response.status,
                    payload: await parseJsonResponse(response),
                });
            })
            .catch(() => {
                sendResponse({ ok: false, status: 0, payload: null });
            });

        return true;
    }

    if (payload.type === 'ATLAS_CLOSE_CURRENT_TAB') {
        const senderTabId = sender.tab?.id;
        if (typeof senderTabId !== 'number') {
            sendResponse({ ok: false });
            return false;
        }

        chrome.tabs.remove(senderTabId, () => {
            sendResponse({ ok: !chrome.runtime.lastError });
        });

        return true;
    }

    if (payload.type === 'ATLAS_DISCARD_INACTIVE_TABS') {
        void discardInactiveTabsOnce()
            .then(({ discardedCount, failedCount, skippedCount }) => {
                sendResponse({
                    ok: true,
                    discardedCount,
                    failedCount,
                    skippedCount,
                });
            })
            .catch(() => {
                sendResponse({
                    ok: false,
                    discardedCount: 0,
                    failedCount: 0,
                    skippedCount: 0,
                });
            });

        return true;
    }

    if (payload.type === 'ATLAS_GET_TAB_COUNT') {
        chrome.tabs.query({}, (tabs: BrowserTab[]) => {
            sendResponse({ count: Array.isArray(tabs) ? tabs.length : 0 });
        });

        return true;
    }

    if (payload.type === 'ATLAS_GET_OPEN_COMPARABLE_URLS') {
        sendResponse({ urls: getComparableOpenTabUrls() });
        return false;
    }

    if (payload.type === 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS') {
        sendResponse({ counts: getComparableOpenTabCounts() });
        return false;
    }

    if (payload.type !== 'ATLAS_IS_URL_OPEN' || typeof payload.url !== 'string') {
        return false;
    }

    const target = normalizeComparableOpenTabUrl(payload.url);
    if (target === null) {
        sendResponse({ isOpenInAnotherTab: false });
        return false;
    }

    const senderTabId = sender.tab?.id;
    const openCount = openComparableUrlCountByUrl.get(target) ?? 0;
    if (openCount === 0) {
        sendResponse({ isOpenInAnotherTab: false });
        return false;
    }

    const senderComparableUrl = typeof senderTabId === 'number'
        ? (openComparableUrlByTabId.get(senderTabId) ?? null)
        : null;
    const isOpenInAnotherTab = senderComparableUrl === target
        ? openCount > 1
        : openCount > 0;
    sendResponse({ isOpenInAnotherTab });
    return false;
});

chrome.tabs.onCreated.addListener((tab: BrowserTab) => {
    if (typeof tab.id !== 'number') {
        return;
    }

    const comparableUrl = typeof tab.url === 'string' ? normalizeComparableOpenTabUrl(tab.url) : null;
    const changedUrls = updateTrackedComparableTabUrl(tab.id, comparableUrl);
    broadcastTabPresenceChanged(changedUrls);
    broadcastTabCountChanged();
});

chrome.tabs.onRemoved.addListener((tabId: number) => {
    removeDownloadProgressSubscriber(tabId);
    const changedUrls = updateTrackedComparableTabUrl(tabId, null);
    broadcastTabPresenceChanged(changedUrls);
    broadcastTabCountChanged();
});

chrome.tabs.onUpdated.addListener((tabId: number, changeInfo: { url?: string; status?: string }, tab: BrowserTab) => {
    if (typeof changeInfo.url === 'string' || changeInfo.status === 'complete') {
        const sourceUrl = typeof changeInfo.url === 'string' ? changeInfo.url : tab.url ?? null;
        const comparableUrl = sourceUrl === null ? null : normalizeComparableOpenTabUrl(sourceUrl);
        const changedUrls = updateTrackedComparableTabUrl(tabId, comparableUrl);
        broadcastTabPresenceChanged(changedUrls);
    }
});

chrome.runtime.onInstalled.addListener((details: { reason: string }) => {
    if (details.reason === 'install' || details.reason === 'update') {
        notifyTabsExtensionReloaded();
    }
});

initializeTrackedTabUrls();
