import type { ProgressEvent } from '../download-progress-event';

type BusListener = (event: ProgressEvent) => void;

const listeners = new Set<BusListener>();
let runtimeListenerBound = false;
let backgroundSubscribed = false;

function bindRuntimeListener(): void {
    if (runtimeListenerBound || !chrome.runtime?.onMessage) {
        return;
    }

    chrome.runtime.onMessage.addListener((message: unknown) => {
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
    });

    runtimeListenerBound = true;
}

function subscribeBackgroundIfNeeded(): void {
    if (backgroundSubscribed || !chrome.runtime?.sendMessage) {
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

function unsubscribeBackgroundIfUnused(): void {
    if (listeners.size > 0 || !backgroundSubscribed || !chrome.runtime?.sendMessage) {
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
    subscribeBackgroundIfNeeded();

    return () => {
        listeners.delete(listener);
        unsubscribeBackgroundIfUnused();
    };
}

export type {
    ProgressEvent,
};
