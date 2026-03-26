import {
    handleDownloadProgressRuntimeMessage,
    removeDownloadProgressSubscriber,
} from './background-download-progress';
import { notifyTabsExtensionReloaded } from './background-reload-overlay';
import {
    handleAtlasApiRequestRuntimeMessage,
    handleGetUrlCookiesRuntimeMessage,
    handleQueuedBadgeCheckRuntimeMessage,
    handleQueuedReferrerCheckRuntimeMessage,
    handleSubmitReactionRuntimeMessage,
} from './background-runtime-message-handlers';
import { normalizeComparableOpenTabUrl } from './open-tab-url';
import { resolveTabDomainGroupKey, summarizeTabCounts } from './tab-counts';
type TabPresenceChangedMessage = {
    type: 'ATLAS_TAB_PRESENCE_CHANGED';
    urls: string[];
    counts: Record<string, number>;
};
type TabCountChangedMessage = {
    type: 'ATLAS_TAB_COUNT_CHANGED';
    count: number;
    similarDomainCount: number | null;
};
type RuntimeMessageSender = {
    tab?: {
        id?: number;
    };
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
        const safeTabs = Array.isArray(tabs) ? tabs : [];
        const count = safeTabs.length;
        const domainCounts = new Map<string, number>();
        const tabRows: Array<{ tabId: number; similarDomainKey: string | null }> = [];

        for (const tab of safeTabs) {
            if (typeof tab.id !== 'number') {
                continue;
            }

            const similarDomainKey = resolveTabDomainGroupKey(tab.url ?? null);
            tabRows.push({
                tabId: tab.id,
                similarDomainKey,
            });

            if (similarDomainKey !== null) {
                domainCounts.set(similarDomainKey, (domainCounts.get(similarDomainKey) ?? 0) + 1);
            }
        }

        for (const row of tabRows) {
            const message: TabCountChangedMessage = {
                type: 'ATLAS_TAB_COUNT_CHANGED',
                count,
                similarDomainCount: row.similarDomainKey === null
                    ? null
                    : (domainCounts.get(row.similarDomainKey) ?? 0),
            };
            chrome.tabs.sendMessage(row.tabId, message, () => {
                void chrome.runtime.lastError;
            });
        }
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
    if (handleGetUrlCookiesRuntimeMessage(message, sendResponse)
        || handleSubmitReactionRuntimeMessage(message, sender, sendResponse)
        || handleQueuedBadgeCheckRuntimeMessage(message, sendResponse)
        || handleQueuedReferrerCheckRuntimeMessage(message, sendResponse)
        || handleAtlasApiRequestRuntimeMessage(message, sendResponse)) {
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
            const safeTabs = Array.isArray(tabs) ? tabs : [];
            const senderTab = typeof sender.tab?.id === 'number'
                ? (safeTabs.find((tab) => tab.id === sender.tab?.id) ?? null)
                : null;
            const summary = summarizeTabCounts(safeTabs, senderTab?.url ?? null);
            sendResponse({
                count: summary.totalCount,
                similarDomainCount: summary.similarDomainCount,
            });
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
        broadcastTabCountChanged();
    }
});

chrome.runtime.onInstalled.addListener((details: { reason: string }) => {
    if (details.reason === 'install' || details.reason === 'update') {
        notifyTabsExtensionReloaded();
    }
});

initializeTrackedTabUrls();
