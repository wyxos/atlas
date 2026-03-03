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
    active?: boolean;
    discarded?: boolean;
};

const openComparableUrlByTabId = new Map<number, string>();
let discardInactiveTabsInFlight: Promise<{ discardedCount: number; failedCount: number; skippedCount: number }> | null = null;

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

function canInjectReloadOverlay(tab: BrowserTab): tab is BrowserTab & { id: number; url: string } {
    return typeof tab.id === 'number'
        && typeof tab.url === 'string'
        && normalizeComparableUrl(tab.url) !== null;
}

function injectReloadOverlayIntoTab(tabId: number): void {
    if (!chrome.scripting || typeof chrome.scripting.executeScript !== 'function') {
        return;
    }

    chrome.scripting.executeScript({
        target: { tabId },
        func: () => {
            const overlayId = 'atlas-extension-reload-overlay';
            if (document.getElementById(overlayId)) {
                return;
            }

            const overlay = document.createElement('div');
            overlay.id = overlayId;
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.style.position = 'fixed';
            overlay.style.inset = '0';
            overlay.style.zIndex = '2147483647';
            overlay.style.background = 'rgba(8, 14, 24, 0.86)';
            overlay.style.backdropFilter = 'blur(2px)';
            overlay.style.display = 'flex';
            overlay.style.alignItems = 'center';
            overlay.style.justifyContent = 'center';
            overlay.style.padding = '24px';
            overlay.style.boxSizing = 'border-box';

            const panel = document.createElement('div');
            panel.style.maxWidth = '540px';
            panel.style.width = '100%';
            panel.style.background = '#0f172a';
            panel.style.border = '1px solid rgba(148, 163, 184, 0.35)';
            panel.style.borderRadius = '14px';
            panel.style.boxShadow = '0 24px 60px rgba(0, 0, 0, 0.45)';
            panel.style.padding = '24px';
            panel.style.color = '#e2e8f0';
            panel.style.fontFamily = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, sans-serif';

            const heading = document.createElement('h2');
            heading.textContent = 'Atlas extension updated';
            heading.style.margin = '0 0 10px 0';
            heading.style.fontSize = '20px';
            heading.style.lineHeight = '1.3';
            heading.style.fontWeight = '700';

            const message = document.createElement('p');
            message.textContent = 'Reload this tab to re-enable Atlas widgets and checks on this page.';
            message.style.margin = '0 0 18px 0';
            message.style.fontSize = '14px';
            message.style.lineHeight = '1.6';
            message.style.color = '#cbd5e1';

            const actions = document.createElement('div');
            actions.style.display = 'flex';
            actions.style.gap = '10px';
            actions.style.flexWrap = 'wrap';

            const reloadButton = document.createElement('button');
            reloadButton.type = 'button';
            reloadButton.textContent = 'Reload tab';
            reloadButton.style.background = '#14b8a6';
            reloadButton.style.color = '#052e2b';
            reloadButton.style.border = 'none';
            reloadButton.style.borderRadius = '10px';
            reloadButton.style.padding = '10px 16px';
            reloadButton.style.fontSize = '14px';
            reloadButton.style.fontWeight = '700';
            reloadButton.style.cursor = 'pointer';
            reloadButton.addEventListener('click', () => {
                window.location.reload();
            });

            const dismissButton = document.createElement('button');
            dismissButton.type = 'button';
            dismissButton.textContent = 'Dismiss';
            dismissButton.style.background = 'transparent';
            dismissButton.style.color = '#cbd5e1';
            dismissButton.style.border = '1px solid rgba(148, 163, 184, 0.5)';
            dismissButton.style.borderRadius = '10px';
            dismissButton.style.padding = '10px 16px';
            dismissButton.style.fontSize = '14px';
            dismissButton.style.fontWeight = '600';
            dismissButton.style.cursor = 'pointer';
            dismissButton.addEventListener('click', () => {
                overlay.remove();
            });

            actions.appendChild(reloadButton);
            actions.appendChild(dismissButton);
            panel.appendChild(heading);
            panel.appendChild(message);
            panel.appendChild(actions);
            overlay.appendChild(panel);
            (document.documentElement ?? document.body).appendChild(overlay);
            reloadButton.focus();
        },
    }, () => {
        void chrome.runtime.lastError;
    });
}

function notifyTabsExtensionReloaded(): void {
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        for (const tab of tabs) {
            if (!canInjectReloadOverlay(tab)) {
                continue;
            }

            injectReloadOverlayIntoTab(tab.id);
        }
    });
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

    discardInactiveTabsInFlight = discardInactiveTabs()
        .finally(() => {
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

chrome.runtime.onInstalled.addListener((details: { reason: string }) => {
    if (details.reason !== 'install' && details.reason !== 'update') {
        return;
    }

    notifyTabsExtensionReloaded();
});

initializeTrackedTabUrls();
