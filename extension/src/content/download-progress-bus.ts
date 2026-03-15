import type { ProgressEvent } from '../download-progress-event';

type BusListener = (event: ProgressEvent) => void;

const listeners = new Set<BusListener>();
const DOWNLOAD_PROGRESS_RESUBSCRIBE_INTERVAL_MS = 15000;
let runtimeListenerBound = false;
let backgroundSubscribed = false;
let backgroundSubscriptionRefreshTimer: number | null = null;

function bindRuntimeListener(): void {
    if (runtimeListenerBound || !chrome.runtime?.onMessage) {
        return;
    }

    chrome.runtime.onMessage.addListener((message: unknown, _sender: unknown, sendResponse?: (response?: unknown) => void) => {
        if (typeof message !== 'object' || message === null) {
            return;
        }

        const payload = message as { type?: unknown; event?: unknown };
        if (payload.type !== 'ATLAS_DOWNLOAD_PROGRESS_EVENT' || !payload.event || typeof payload.event !== 'object') {
            return;
        }

        const event = payload.event as ProgressEvent;
        listeners.forEach((listener) => {
            listener(event);
        });
        sendResponse?.({ ok: true });
    });

    runtimeListenerBound = true;
}

function requestBackgroundSubscription(): void {
    if (!chrome.runtime?.sendMessage) {
        return;
    }

    backgroundSubscribed = true;

    try {
        chrome.runtime.sendMessage({ type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, () => {
            if (chrome.runtime.lastError) {
                backgroundSubscribed = false;
            }
        });
    } catch {
        backgroundSubscribed = false;
    }
}

function startBackgroundSubscriptionRefresh(): void {
    if (backgroundSubscriptionRefreshTimer !== null) {
        return;
    }

    backgroundSubscriptionRefreshTimer = window.setInterval(() => {
        if (listeners.size === 0) {
            stopBackgroundSubscriptionRefresh();
            return;
        }

        requestBackgroundSubscription();
    }, DOWNLOAD_PROGRESS_RESUBSCRIBE_INTERVAL_MS);
}

function stopBackgroundSubscriptionRefresh(): void {
    if (backgroundSubscriptionRefreshTimer === null) {
        return;
    }

    window.clearInterval(backgroundSubscriptionRefreshTimer);
    backgroundSubscriptionRefreshTimer = null;
}

function unsubscribeBackgroundIfUnused(): void {
    if (listeners.size > 0) {
        return;
    }

    stopBackgroundSubscriptionRefresh();

    if (!backgroundSubscribed || !chrome.runtime?.sendMessage) {
        return;
    }

    backgroundSubscribed = false;

    try {
        chrome.runtime.sendMessage({ type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' }, () => {
            void chrome.runtime.lastError;
        });
    } catch {
        // Ignore runtime teardown errors during page unload.
    }
}

export function subscribeToDownloadProgress(listener: BusListener): () => void {
    listeners.add(listener);
    bindRuntimeListener();
    if (listeners.size === 1) {
        requestBackgroundSubscription();
    }
    startBackgroundSubscriptionRefresh();

    return () => {
        listeners.delete(listener);
        unsubscribeBackgroundIfUnused();
    };
}

export type {
    ProgressEvent,
};
