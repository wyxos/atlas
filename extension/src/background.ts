type TabPresenceChangedMessage = {
    type: 'ATLAS_TAB_PRESENCE_CHANGED';
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

type BrowserTab = {
    id?: number;
    url?: string;
};

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

function broadcastTabPresenceChanged(): void {
    chrome.tabs.query({}, (tabs: BrowserTab[]) => {
        tabs.forEach((tab) => {
            if (typeof tab.id !== 'number') {
                return;
            }

            const message: TabPresenceChangedMessage = { type: 'ATLAS_TAB_PRESENCE_CHANGED' };
            chrome.tabs.sendMessage(tab.id, message, () => {
                void chrome.runtime.lastError;
            });
        });
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

chrome.tabs.onCreated.addListener(() => {
    broadcastTabPresenceChanged();
});

chrome.tabs.onRemoved.addListener(() => {
    broadcastTabPresenceChanged();
});

chrome.tabs.onUpdated.addListener((_tabId: number, changeInfo: { url?: string; status?: string }) => {
    if (typeof changeInfo.url === 'string' || changeInfo.status === 'complete') {
        broadcastTabPresenceChanged();
    }
});
