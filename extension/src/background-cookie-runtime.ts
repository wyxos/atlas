export type RuntimeCookie = {
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

export async function collectCookiesForUrls(urls: string[]): Promise<RuntimeCookie[]> {
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
