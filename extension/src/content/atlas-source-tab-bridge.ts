import { getStoredConnectionOptions } from '../atlas-options';

export const ATLAS_SOURCE_TAB_BRIDGE_ATTR = 'data-atlas-extension-source-tab-bridge';
export const ATLAS_SOURCE_TAB_MESSAGE_TYPE = 'ATLAS_OPEN_SOURCE_TABS';

function normalizeHttpOrigin(value: string): string | null {
    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.origin : null;
    } catch {
        return null;
    }
}

function isConfiguredAtlasAppOrigin(currentOrigin: string, atlasDomain: string): boolean {
    const configuredOrigin = normalizeHttpOrigin(atlasDomain);

    return configuredOrigin !== null && currentOrigin === configuredOrigin;
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
    let installedCleanup: (() => void) | null = null;
    let disposed = false;

    void getStoredConnectionOptions()
        .then(({ atlasDomain }) => {
            if (disposed || !isConfiguredAtlasAppOrigin(window.location.origin, atlasDomain)) {
                return;
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

            installedCleanup = () => {
                window.removeEventListener('message', handleMessage);
                document.documentElement.removeAttribute(ATLAS_SOURCE_TAB_BRIDGE_ATTR);
            };
        })
        .catch(() => {});

    return () => {
        disposed = true;
        installedCleanup?.();
    };
}
