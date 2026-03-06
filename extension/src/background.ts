import { collectCookiesForUrls } from './background-cookie-runtime';
import { notifyTabsExtensionReloaded } from './background-reload-overlay';
import { normalizeComparableUrl, normalizeComparableUrls } from './background-url-utils';

type TabPresenceChangedMessage = {
    type: 'ATLAS_TAB_PRESENCE_CHANGED';
    urls: string[];
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

type BrowserTab = {
    id?: number;
    url?: string;
    active?: boolean;
    discarded?: boolean;
};

const openComparableUrlByTabId = new Map<number, string>();
let discardInactiveTabsInFlight: Promise<{ discardedCount: number; failedCount: number; skippedCount: number }> | null = null;

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

        void fetch(endpoint, {
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

chrome.tabs.onCreated.addListener((tab: BrowserTab) => {
    if (typeof tab.id !== 'number') {
        return;
    }

    const comparableUrl = typeof tab.url === 'string' ? normalizeComparableUrl(tab.url) : null;
    const changedUrls = updateTrackedComparableTabUrl(tab.id, comparableUrl);
    broadcastTabPresenceChanged(changedUrls);
    broadcastTabCountChanged();
});

chrome.tabs.onRemoved.addListener((tabId: number) => {
    const changedUrls = updateTrackedComparableTabUrl(tabId, null);
    broadcastTabPresenceChanged(changedUrls);
    broadcastTabCountChanged();
});

chrome.tabs.onUpdated.addListener((tabId: number, changeInfo: { url?: string; status?: string }, tab: BrowserTab) => {
    if (typeof changeInfo.url === 'string' || changeInfo.status === 'complete') {
        const sourceUrl = typeof changeInfo.url === 'string' ? changeInfo.url : tab.url ?? null;
        const comparableUrl = sourceUrl === null ? null : normalizeComparableUrl(sourceUrl);
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
