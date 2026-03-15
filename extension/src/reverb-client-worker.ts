import type {
    ReverbClient,
    ReverbConnectionState,
    ReverbConfig,
    ReverbEventName,
    ReverbEventPayload,
    ReverbSubscription,
} from './reverb-types';

type WorkerWebSocketListener = (event: unknown) => void;

type WorkerWebSocket = {
    addEventListener: (type: string, listener: WorkerWebSocketListener) => void;
    removeEventListener: (type: string, listener: WorkerWebSocketListener) => void;
    send: (data: string) => void;
    close: () => void;
};

type WorkerWebSocketCtor = new (url: string) => WorkerWebSocket;

type PusherEnvelope = {
    event?: unknown;
    data?: unknown;
};

const SUPPORTED_TRANSFER_EVENTS = new Set<ReverbEventName>([
    'DownloadTransferCreated',
    'DownloadTransferQueued',
    'DownloadTransferProgressUpdated',
]);
const DEFAULT_ACTIVITY_TIMEOUT_SECONDS = 120;
const MIN_PING_INTERVAL_MS = 1000;

function resolveWorkerWebSocketCtor(): WorkerWebSocketCtor | null {
    if (typeof WebSocket !== 'function') {
        return null;
    }

    return WebSocket as unknown as WorkerWebSocketCtor;
}

function createWebSocketUrl(config: ReverbConfig): string {
    const protocol = config.scheme === 'https' ? 'wss' : 'ws';
    const query = 'protocol=7&client=atlas-extension&version=1.0&flash=false';
    return `${protocol}://${config.host}:${config.port}/app/${config.key}?${query}`;
}

function parseJson(raw: string): unknown {
    try {
        return JSON.parse(raw) as unknown;
    } catch {
        return raw;
    }
}

function normalizeEnvelopeData(value: unknown): unknown {
    if (typeof value !== 'string') {
        return value;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    return parseJson(trimmed);
}

function normalizeEventPayload(value: unknown): ReverbEventPayload | null {
    const normalized = normalizeEnvelopeData(value);
    if (!normalized || typeof normalized !== 'object') {
        return null;
    }

    return normalized as ReverbEventPayload;
}

function resolveErrorMessage(error: unknown): string {
    if (typeof error === 'string' && error.trim() !== '') {
        return error;
    }

    const candidate = error as {
        message?: unknown;
        error?: { message?: unknown };
        data?: { message?: unknown };
    } | null;

    if (typeof candidate?.error?.message === 'string' && candidate.error.message.trim() !== '') {
        return candidate.error.message;
    }

    if (typeof candidate?.data?.message === 'string' && candidate.data.message.trim() !== '') {
        return candidate.data.message;
    }

    if (typeof candidate?.message === 'string' && candidate.message.trim() !== '') {
        return candidate.message;
    }

    return 'Unknown websocket error';
}

function parseActivityTimeoutSeconds(data: unknown): number {
    if (!data || typeof data !== 'object') {
        return DEFAULT_ACTIVITY_TIMEOUT_SECONDS;
    }

    const payload = data as { activity_timeout?: unknown };
    const candidate = payload.activity_timeout;

    if (typeof candidate === 'number' && Number.isFinite(candidate) && candidate > 0) {
        return candidate;
    }

    if (typeof candidate === 'string') {
        const value = Number(candidate.trim());
        if (Number.isFinite(value) && value > 0) {
            return value;
        }
    }

    return DEFAULT_ACTIVITY_TIMEOUT_SECONDS;
}

function startPingLoop(socket: WorkerWebSocket, activityTimeoutSeconds: number): number {
    const intervalMs = Math.max(MIN_PING_INTERVAL_MS, Math.floor(activityTimeoutSeconds * 1000));
    return setInterval(() => {
        try {
            socket.send(JSON.stringify({
                event: 'pusher:ping',
                data: {},
            }));
        } catch {
            // Ignore send failures; close/error handlers will update state.
        }
    }, intervalMs) as unknown as number;
}

function createWorkerReverbClient(
    config: ReverbConfig,
    WebSocketCtor: WorkerWebSocketCtor,
): ReverbClient | null {
    if (!config.enabled || config.key === '' || config.host === '' || config.channel === '') {
        return null;
    }

    const socket = new WebSocketCtor(createWebSocketUrl(config));
    const eventCallbacks = new Set<(event: ReverbEventName, payload: ReverbEventPayload) => void>();
    const connectionCallbacks = new Set<(state: ReverbConnectionState) => void>();
    const connectionErrorCallbacks = new Set<(message: string) => void>();

    let connectionState: ReverbConnectionState = 'connecting';
    let lastConnectionError: string | null = null;
    let pingInterval: number | null = null;

    const emitConnectionState = (state: ReverbConnectionState): void => {
        connectionState = state;
        connectionCallbacks.forEach((callback) => {
            callback(state);
        });
    };

    const emitConnectionError = (message: string): void => {
        lastConnectionError = message;
        connectionErrorCallbacks.forEach((callback) => {
            callback(message);
        });
    };

    const stopPingLoop = (): void => {
        if (pingInterval === null) {
            return;
        }

        clearInterval(pingInterval);
        pingInterval = null;
    };

    const sendSubscribe = (): void => {
        socket.send(JSON.stringify({
            event: 'pusher:subscribe',
            data: {
                channel: config.channel,
            },
        }));
    };

    const sendPong = (): void => {
        socket.send(JSON.stringify({
            event: 'pusher:pong',
            data: {},
        }));
    };

    const onOpen = (): void => {
        emitConnectionState('connecting');
    };

    const onMessage = (rawEvent: unknown): void => {
        const eventData = (rawEvent as { data?: unknown } | null)?.data;
        if (typeof eventData !== 'string') {
            return;
        }

        const parsed = parseJson(eventData);
        if (!parsed || typeof parsed !== 'object') {
            return;
        }

        const envelope = parsed as PusherEnvelope;
        if (typeof envelope.event !== 'string') {
            return;
        }

        if (envelope.event === 'pusher:connection_established') {
            const data = normalizeEnvelopeData(envelope.data);
            const activityTimeoutSeconds = parseActivityTimeoutSeconds(data);
            sendSubscribe();
            stopPingLoop();
            pingInterval = startPingLoop(socket, activityTimeoutSeconds);
            emitConnectionState('connected');
            return;
        }

        if (envelope.event === 'pusher_internal:subscription_succeeded') {
            emitConnectionState('connected');
            return;
        }

        if (envelope.event === 'pusher:ping') {
            sendPong();
            return;
        }

        if (envelope.event === 'pusher:error') {
            const message = resolveErrorMessage(normalizeEnvelopeData(envelope.data));
            emitConnectionError(message);
            emitConnectionState('failed');
            return;
        }

        if (!SUPPORTED_TRANSFER_EVENTS.has(envelope.event as ReverbEventName)) {
            return;
        }

        const payload = normalizeEventPayload(envelope.data);
        if (payload === null) {
            return;
        }

        eventCallbacks.forEach((callback) => {
            callback(envelope.event as ReverbEventName, payload);
        });
    };

    const onError = (rawError: unknown): void => {
        const message = resolveErrorMessage(rawError);
        emitConnectionError(message);
        emitConnectionState('failed');
    };

    const onClose = (): void => {
        stopPingLoop();
        emitConnectionState('disconnected');
    };

    socket.addEventListener('open', onOpen);
    socket.addEventListener('message', onMessage);
    socket.addEventListener('error', onError);
    socket.addEventListener('close', onClose);

    return {
        onEvent: (callback) => {
            eventCallbacks.add(callback);
            return {
                unsubscribe: () => {
                    eventCallbacks.delete(callback);
                },
            };
        },
        onConnectionState: (callback) => {
            connectionCallbacks.add(callback);
            callback(connectionState);
            return {
                unsubscribe: () => {
                    connectionCallbacks.delete(callback);
                },
            };
        },
        onConnectionError: (callback) => {
            connectionErrorCallbacks.add(callback);
            if (lastConnectionError !== null) {
                callback(lastConnectionError);
            }
            return {
                unsubscribe: () => {
                    connectionErrorCallbacks.delete(callback);
                },
            };
        },
        getConnectionState: () => connectionState,
        getLastConnectionError: () => lastConnectionError,
        disconnect: () => {
            stopPingLoop();
            socket.removeEventListener('open', onOpen);
            socket.removeEventListener('message', onMessage);
            socket.removeEventListener('error', onError);
            socket.removeEventListener('close', onClose);
            eventCallbacks.clear();
            connectionCallbacks.clear();
            connectionErrorCallbacks.clear();

            try {
                socket.close();
            } catch {
                // Ignore teardown errors.
            }

            connectionState = 'disconnected';
        },
    };
}

async function connectWorkerReverb(
    config: ReverbConfig,
    socketCtor?: WorkerWebSocketCtor,
): Promise<ReverbClient | null> {
    const ctor = socketCtor ?? resolveWorkerWebSocketCtor();
    if (ctor === null) {
        throw new Error('WebSocket is unavailable in this runtime.');
    }

    return createWorkerReverbClient(config, ctor);
}

export {
    connectWorkerReverb,
};

export type {
    ReverbClient,
    ReverbConfig,
    ReverbSubscription,
    WorkerWebSocketCtor,
};
