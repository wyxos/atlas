import { connectBackgroundReverb } from './background-reverb-runtime';
import { createProgressEvent, type ProgressEvent } from './download-progress-event';
import type { ReverbClient, ReverbConnectionState, ReverbSubscription } from './reverb-client';

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

type DownloadProgressDebugConnectionState =
    | 'idle'
    | 'connecting'
    | 'connected'
    | 'disconnected'
    | 'reconnecting'
    | 'failed'
    | 'setup_required'
    | 'auth_failed'
    | 'offline'
    | 'reverb_unavailable';

type DownloadProgressDebugEventLogEntry = {
    id: number;
    receivedAt: string;
    event: ProgressEvent;
};

type DownloadProgressDebugSnapshot = {
    subscriberTabCount: number;
    connectionState: DownloadProgressDebugConnectionState;
    connectionDetail: string | null;
    recentEvents: DownloadProgressDebugEventLogEntry[];
};

const downloadProgressSubscriberTabIds = new Set<number>();
const DOWNLOAD_PROGRESS_RECONNECT_DELAY_MS = 1500;
const MAX_DOWNLOAD_PROGRESS_DEBUG_EVENTS = 20;

let downloadProgressConnectPromise: Promise<void> | null = null;
let downloadProgressClient: ReverbClient | null = null;
let downloadProgressEventSubscription: ReverbSubscription | null = null;
let downloadProgressStateSubscription: ReverbSubscription | null = null;
let downloadProgressReconnectTimer: ReturnType<typeof setTimeout> | null = null;
let downloadProgressConnectionState: DownloadProgressDebugConnectionState = 'idle';
let downloadProgressConnectionDetail: string | null = null;
let downloadProgressDebugEvents: DownloadProgressDebugEventLogEntry[] = [];
let nextDownloadProgressDebugEventId = 1;

function clearDownloadProgressReconnectTimer(): void {
    if (downloadProgressReconnectTimer === null) {
        return;
    }

    clearTimeout(downloadProgressReconnectTimer);
    downloadProgressReconnectTimer = null;
}

function setDownloadProgressConnectionState(
    state: DownloadProgressDebugConnectionState,
    detail: string | null = null,
): void {
    downloadProgressConnectionState = state;
    downloadProgressConnectionDetail = detail;
}

function clearDownloadProgressDebugState(): void {
    downloadProgressDebugEvents = [];
    nextDownloadProgressDebugEventId = 1;
}

function pushDownloadProgressDebugEvent(event: ProgressEvent): void {
    downloadProgressDebugEvents = [
        {
            id: nextDownloadProgressDebugEventId++,
            receivedAt: new Date().toISOString(),
            event,
        },
        ...downloadProgressDebugEvents,
    ].slice(0, MAX_DOWNLOAD_PROGRESS_DEBUG_EVENTS);
}

function getDownloadProgressDebugSnapshot(): DownloadProgressDebugSnapshot {
    return {
        subscriberTabCount: downloadProgressSubscriberTabIds.size,
        connectionState: downloadProgressConnectionState,
        connectionDetail: downloadProgressConnectionDetail,
        recentEvents: [...downloadProgressDebugEvents],
    };
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
    setDownloadProgressConnectionState('idle');
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

function isMissingReceiverError(errorMessage: string): boolean {
    const normalized = errorMessage.trim().toLowerCase();
    return normalized.includes('receiving end does not exist')
        || normalized.includes('no tab with id');
}

function applyRuntimeConnectionFailureState(
    kind: Exclude<Awaited<ReturnType<typeof connectBackgroundReverb>>['kind'], 'connected'>,
    detail: string | null = null,
): void {
    switch (kind) {
        case 'setup_required':
            setDownloadProgressConnectionState('setup_required');
            return;
        case 'auth_failed':
            setDownloadProgressConnectionState('auth_failed');
            return;
        case 'offline':
            setDownloadProgressConnectionState('offline');
            return;
        case 'reverb_unavailable':
            setDownloadProgressConnectionState('reverb_unavailable');
            return;
        case 'disconnected':
            setDownloadProgressConnectionState('disconnected', detail);
            return;
    }
}

function applyReverbConnectionState(state: ReverbConnectionState, detail: string | null = null): void {
    if (state === 'connected') {
        setDownloadProgressConnectionState('connected');
        return;
    }

    if (state === 'connecting') {
        setDownloadProgressConnectionState('connecting');
        return;
    }

    if (state === 'reconnecting') {
        setDownloadProgressConnectionState('reconnecting');
        return;
    }

    if (state === 'failed') {
        setDownloadProgressConnectionState('failed', detail);
        return;
    }

    setDownloadProgressConnectionState('disconnected', detail);
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
            const errorMessage = chrome.runtime.lastError?.message ?? '';
            if (errorMessage === '') {
                return;
            }

            if (isMissingReceiverError(errorMessage)) {
                removeDownloadProgressSubscriber(tabId);
            }
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
        setDownloadProgressConnectionState('connecting');
        const runtime = await connectBackgroundReverb();
        if (runtime.kind !== 'connected') {
            applyRuntimeConnectionFailureState(runtime.kind, runtime.kind === 'disconnected' ? runtime.detail : null);
            if (runtime.kind === 'offline' || runtime.kind === 'disconnected') {
                scheduleDownloadProgressReconnect();
            }
            return;
        }

        clearDownloadProgressReconnectTimer();
        downloadProgressClient = runtime.client;
        downloadProgressEventSubscription = runtime.client.onEvent((event, payload) => {
            const progressEvent = createProgressEvent(event, payload);
            pushDownloadProgressDebugEvent(progressEvent);
            broadcastDownloadProgressEvent(progressEvent);
        });
        downloadProgressStateSubscription = runtime.client.onConnectionState((state) => {
            applyReverbConnectionState(state, runtime.client.getLastConnectionError());
            if (state === 'connected' || state === 'connecting' || state === 'reconnecting') {
                return;
            }

            teardownDownloadProgressConnection();
            scheduleDownloadProgressReconnect();
        });
        setDownloadProgressConnectionState('connected');

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

    if (payload.type === 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE') {
        sendResponse({
            ok: true,
            snapshot: getDownloadProgressDebugSnapshot(),
        });
        return true;
    }

    if (payload.type === 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE') {
        clearDownloadProgressDebugState();
        sendResponse({ ok: true });
        return true;
    }

    return false;
}

export type {
    DownloadProgressDebugConnectionState,
    DownloadProgressDebugEventLogEntry,
    DownloadProgressDebugSnapshot,
};
