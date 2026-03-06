type TabCountChangedListener = (count: number) => void;

const tabCountChangedListeners = new Set<TabCountChangedListener>();
let tabCountRuntimeBound = false;

function toSafeCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : 0;
}

export function subscribeToTabCountChanged(listener: TabCountChangedListener): () => void {
    tabCountChangedListeners.add(listener);

    if (!tabCountRuntimeBound && chrome.runtime?.onMessage) {
        chrome.runtime.onMessage.addListener((message: unknown) => {
            if (typeof message !== 'object' || message === null) {
                return;
            }

            const payload = message as { type?: unknown; count?: unknown };
            if (payload.type !== 'ATLAS_TAB_COUNT_CHANGED') {
                return;
            }

            const count = toSafeCount(payload.count);
            tabCountChangedListeners.forEach((tabListener) => {
                tabListener(count);
            });
        });
        tabCountRuntimeBound = true;
    }

    return () => {
        tabCountChangedListeners.delete(listener);
    };
}

export async function requestTabCount(): Promise<number | null> {
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
                resolve(0);
                return;
            }

            resolve(toSafeCount((response as { count?: unknown }).count));
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
