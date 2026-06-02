export const ATLAS_SOURCE_TAB_BRIDGE_ATTR = 'data-atlas-extension-source-tab-bridge';
export const ATLAS_SOURCE_TAB_MESSAGE_TYPE = 'ATLAS_OPEN_SOURCE_TABS';

const ATLAS_APP_HOSTNAMES = new Set([
    'atlas.test',
    'atlas.wyxos.com',
    'localhost',
    '127.0.0.1',
]);

function isAtlasAppHostname(hostname: string): boolean {
    return ATLAS_APP_HOSTNAMES.has(hostname);
}

function normalizeSourceTabUrls(value: unknown): string[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const urls = new Set<string>();

    value.forEach((item) => {
        const trimmed = typeof item === 'string' ? item.trim() : '';
        if (trimmed === '') {
            return;
        }

        try {
            const url = new URL(trimmed);
            if (url.protocol === 'http:' || url.protocol === 'https:') {
                urls.add(url.href);
            }
        } catch {
            // Ignore invalid URLs from page messages.
        }
    });

    return Array.from(urls);
}

export function installAtlasSourceTabBridge(): () => void {
    if (!isAtlasAppHostname(window.location.hostname)) {
        return () => {};
    }

    document.documentElement.setAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR, '1');

    const handleMessage = (event: MessageEvent): void => {
        if (event.source !== window || event.origin !== window.location.origin) {
            return;
        }

        const data = event.data as { type?: unknown; urls?: unknown } | null;
        if (!data || typeof data !== 'object' || data.type !== ATLAS_SOURCE_TAB_MESSAGE_TYPE) {
            return;
        }

        const urls = normalizeSourceTabUrls(data.urls);
        if (urls.length === 0) {
            return;
        }

        chrome.runtime.sendMessage({
            type: ATLAS_SOURCE_TAB_MESSAGE_TYPE,
            urls,
        }, () => {
            void chrome.runtime.lastError;
        });
    };

    window.addEventListener('message', handleMessage);

    return () => {
        window.removeEventListener('message', handleMessage);
        document.documentElement.removeAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR);
    };
}
