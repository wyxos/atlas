export type TabCountSnapshot = {
    similarDomainCount: number | null;
    totalCount: number;
};

type TabCountChangedListener = (snapshot: TabCountSnapshot) => void;

const tabCountChangedListeners = new Set<TabCountChangedListener>();
let tabCountRuntimeBound = false;

function toSafeCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : 0;
}

function toSafeNullableCount(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : null;
}

export function subscribeToTabCountChanged(listener: TabCountChangedListener): () => void {
    tabCountChangedListeners.add(listener);

    if (!tabCountRuntimeBound && chrome.runtime?.onMessage) {
        chrome.runtime.onMessage.addListener((message: unknown) => {
            if (typeof message !== 'object' || message === null) {
                return;
            }

            const payload = message as { type?: unknown; count?: unknown; similarDomainCount?: unknown };
            if (payload.type !== 'ATLAS_TAB_COUNT_CHANGED') {
                return;
            }

            const snapshot: TabCountSnapshot = {
                similarDomainCount: toSafeNullableCount(payload.similarDomainCount),
                totalCount: toSafeCount(payload.count),
            };
            tabCountChangedListeners.forEach((tabListener) => {
                tabListener(snapshot);
            });
        });
        tabCountRuntimeBound = true;
    }

    return () => {
        tabCountChangedListeners.delete(listener);
    };
}

export async function requestTabCount(): Promise<TabCountSnapshot | null> {
    if (!chrome.runtime?.sendMessage) {
        return null;
    }

    return new Promise((resolve) => {
        chrome.runtime.sendMessage({ type: 'ATLAS_GET_TAB_COUNT' }, (response: unknown) => {
            if (chrome.runtime.lastError) {
                resolve(null);
                return;
            }

            if (typeof response !== 'object' || response === null) {
                resolve({
                    similarDomainCount: null,
                    totalCount: 0,
                });
                return;
            }

            resolve({
                similarDomainCount: toSafeNullableCount((response as { similarDomainCount?: unknown }).similarDomainCount),
                totalCount: toSafeCount((response as { count?: unknown }).count),
            });
        });
    });
}

export async function requestCloseCurrentTab(): Promise<void> {
    if (!chrome.runtime?.sendMessage) {
        return;
    }

    await new Promise<void>((resolve) => {
        chrome.runtime.sendMessage({ type: 'ATLAS_CLOSE_CURRENT_TAB' }, () => {
            void chrome.runtime.lastError;
            resolve();
        });
    });
}
