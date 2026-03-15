import { connectBackgroundReverb } from './background-reverb-runtime';
import { createProgressEvent, type ProgressEvent } from './download-progress-event';
import type { ReverbClient, ReverbSubscription } from './reverb-client';

type RuntimeMessageSender = {
    tab?: {
        id?: number;
    };
};

type RuntimeSendResponse = (response?: unknown) => void;

type DownloadProgressEventMessage = {
    type: 'ATLAS_DOWNLOAD_PROGRESS_EVENT';
    event: ProgressEvent;
};

const downloadProgressSubscriberTabIds = new Set<number>();
const DOWNLOAD_PROGRESS_RECONNECT_DELAY_MS = 1500;
let downloadProgressConnectPromise: Promise<void> | null = null;
let downloadProgressClient: ReverbClient | null = null;
let downloadProgressEventSubscription: ReverbSubscription | null = null;
let downloadProgressStateSubscription: ReverbSubscription | null = null;
let downloadProgressReconnectTimer: ReturnType<typeof setTimeout> | null = null;

function clearDownloadProgressReconnectTimer(): void {
    if (downloadProgressReconnectTimer === null) {
        return;
    }

    clearTimeout(downloadProgressReconnectTimer);
    downloadProgressReconnectTimer = null;
}

function teardownDownloadProgressConnection(): void {
    downloadProgressEventSubscription?.unsubscribe();
    downloadProgressEventSubscription = null;
    downloadProgressStateSubscription?.unsubscribe();
    downloadProgressStateSubscription = null;

    if (downloadProgressClient) {
        downloadProgressClient.disconnect();
        downloadProgressClient = null;
    }
}

function disconnectDownloadProgressIfUnused(): void {
    if (downloadProgressSubscriberTabIds.size > 0) {
        return;
    }

    clearDownloadProgressReconnectTimer();
    teardownDownloadProgressConnection();
}

function scheduleDownloadProgressReconnect(): void {
    if (downloadProgressSubscriberTabIds.size === 0 || downloadProgressReconnectTimer !== null) {
        return;
    }

    downloadProgressReconnectTimer = setTimeout(() => {
        downloadProgressReconnectTimer = null;
        if (downloadProgressSubscriberTabIds.size === 0 || downloadProgressClient !== null) {
            return;
        }

        void ensureDownloadProgressConnected();
    }, DOWNLOAD_PROGRESS_RECONNECT_DELAY_MS);
}

export function removeDownloadProgressSubscriber(tabId: number): void {
    if (!downloadProgressSubscriberTabIds.delete(tabId)) {
        return;
    }

    disconnectDownloadProgressIfUnused();
}

function broadcastDownloadProgressEvent(event: ProgressEvent): void {
    if (downloadProgressSubscriberTabIds.size === 0) {
        return;
    }

    const message: DownloadProgressEventMessage = {
        type: 'ATLAS_DOWNLOAD_PROGRESS_EVENT',
        event,
    };

    for (const tabId of Array.from(downloadProgressSubscriberTabIds)) {
        chrome.tabs.sendMessage(tabId, message, () => {
            if (!chrome.runtime.lastError) {
                return;
            }

            removeDownloadProgressSubscriber(tabId);
        });
    }
}

async function ensureDownloadProgressConnected(): Promise<void> {
    if (downloadProgressSubscriberTabIds.size === 0) {
        return;
    }

    if (downloadProgressConnectPromise !== null) {
        return downloadProgressConnectPromise;
    }

    if (downloadProgressClient && downloadProgressEventSubscription && downloadProgressStateSubscription) {
        return;
    }

    downloadProgressConnectPromise = (async () => {
        const runtime = await connectBackgroundReverb();
        if (runtime.kind !== 'connected') {
            if (runtime.kind === 'offline' || runtime.kind === 'disconnected') {
                scheduleDownloadProgressReconnect();
            }
            return;
        }

        clearDownloadProgressReconnectTimer();
        downloadProgressClient = runtime.client;
        downloadProgressEventSubscription = runtime.client.onEvent((event, payload) => {
            broadcastDownloadProgressEvent(createProgressEvent(event, payload));
        });
        downloadProgressStateSubscription = runtime.client.onConnectionState((state) => {
            if (state === 'connected' || state === 'connecting' || state === 'reconnecting') {
                return;
            }

            teardownDownloadProgressConnection();
            scheduleDownloadProgressReconnect();
        });

        disconnectDownloadProgressIfUnused();
    })().finally(() => {
        downloadProgressConnectPromise = null;
    });

    return downloadProgressConnectPromise;
}

export function handleDownloadProgressRuntimeMessage(
    message: unknown,
    sender: RuntimeMessageSender,
    sendResponse: RuntimeSendResponse,
): boolean {
    if (typeof message !== 'object' || message === null) {
        return false;
    }

    const payload = message as { type?: unknown };
    if (payload.type === 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS') {
        const senderTabId = sender.tab?.id;
        if (typeof senderTabId !== 'number') {
            sendResponse({ ok: false });
            return true;
        }

        downloadProgressSubscriberTabIds.add(senderTabId);
        void ensureDownloadProgressConnected();
        sendResponse({ ok: true });
        return true;
    }

    if (payload.type === 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS') {
        const senderTabId = sender.tab?.id;
        if (typeof senderTabId === 'number') {
            removeDownloadProgressSubscriber(senderTabId);
        }

        sendResponse({ ok: true });
        return true;
    }

    return false;
}
