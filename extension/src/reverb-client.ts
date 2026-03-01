type ReverbConfig = {
    enabled: boolean;
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
    channel: string;
};

type ReverbEventName = 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated';

type ReverbEventPayload = Record<string, unknown>;

type ReverbSubscription = {
    unsubscribe: () => void;
};

type PusherLike = {
    connection: {
        state?: string;
        bind: (eventName: string, callback: (state: unknown) => void) => void;
        unbind: (eventName: string, callback: (state: unknown) => void) => void;
    };
    subscribe: (channelName: string) => {
        bind: (eventName: string, callback: (payload: unknown) => void) => void;
        unbind_all?: () => void;
    };
    disconnect: () => void;
};

type ReverbConnectionState = 'connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed';

type ReverbClient = {
    onEvent: (callback: (event: ReverbEventName, payload: ReverbEventPayload) => void) => ReverbSubscription;
    onConnectionState: (callback: (state: ReverbConnectionState) => void) => ReverbSubscription;
    onConnectionError: (callback: (message: string) => void) => ReverbSubscription;
    getConnectionState: () => ReverbConnectionState | null;
    getLastConnectionError: () => string | null;
    disconnect: () => void;
};

function mapPusherState(raw: string | null): ReverbConnectionState | null {
    if (raw === null) {
        return null;
    }

    if (raw === 'connected') {
        return 'connected';
    }

    if (raw === 'connecting' || raw === 'initialized') {
        return 'connecting';
    }

    if (raw === 'unavailable' || raw === 'failed') {
        return 'failed';
    }

    if (raw === 'disconnected') {
        return 'disconnected';
    }

    return 'reconnecting';
}

export async function connectReverb(config: ReverbConfig): Promise<ReverbClient | null> {
    if (!config.enabled || config.key === '' || config.host === '' || config.channel === '') {
        return null;
    }

    const module = await import('pusher-js');
    const PusherCtor = (module as { default?: unknown }).default ?? module;
    const pusher = new (PusherCtor as new (key: string, options: Record<string, unknown>) => PusherLike)(config.key, {
        cluster: 'mt1',
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });

    const channel = pusher.subscribe(config.channel);
    const eventCallbacks = new Set<(event: ReverbEventName, payload: ReverbEventPayload) => void>();
    const connectionCallbacks = new Set<(state: ReverbConnectionState) => void>();
    const connectionErrorCallbacks = new Set<(message: string) => void>();
    let lastConnectionError: string | null = null;

    const emitEvent = (event: ReverbEventName, payload: unknown): void => {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const normalizedPayload = payload as ReverbEventPayload;
        eventCallbacks.forEach((callback) => {
            callback(event, normalizedPayload);
        });
    };

    channel.bind('DownloadTransferCreated', (payload: unknown) => {
        emitEvent('DownloadTransferCreated', payload);
    });
    channel.bind('DownloadTransferQueued', (payload: unknown) => {
        emitEvent('DownloadTransferQueued', payload);
    });
    channel.bind('DownloadTransferProgressUpdated', (payload: unknown) => {
        emitEvent('DownloadTransferProgressUpdated', payload);
    });

    const emitConnectionState = (state: unknown): void => {
        const current = (state as { current?: unknown })?.current;
        const raw = typeof current === 'string'
            ? current
            : (typeof pusher.connection.state === 'string' ? pusher.connection.state : null);
        const normalized = mapPusherState(raw);
        if (normalized === null) {
            return;
        }
        connectionCallbacks.forEach((callback) => {
            callback(normalized);
        });
    };
    const connectionEvents = ['state_change', 'connected', 'disconnected', 'unavailable', 'failed'] as const;
    connectionEvents.forEach((eventName) => {
        pusher.connection.bind(eventName, emitConnectionState);
    });
    const onConnectionError = (error: unknown): void => {
        const candidate = error as { error?: { message?: unknown }; data?: { message?: unknown }; message?: unknown } | null;
        const message = typeof candidate?.error?.message === 'string'
            ? candidate.error.message
            : typeof candidate?.data?.message === 'string'
                ? candidate.data.message
                : typeof candidate?.message === 'string'
                    ? candidate.message
                    : 'Unknown websocket error';
        lastConnectionError = message;
        connectionErrorCallbacks.forEach((callback) => {
            callback(message);
        });
    };
    pusher.connection.bind('error', onConnectionError);

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
            const raw = typeof pusher.connection.state === 'string' ? pusher.connection.state : null;
            const currentState = mapPusherState(raw);
            if (currentState !== null) {
                callback(currentState);
            }
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
        getConnectionState: () => {
            const raw = typeof pusher.connection.state === 'string' ? pusher.connection.state : null;
            return mapPusherState(raw);
        },
        getLastConnectionError: () => lastConnectionError,
        disconnect: () => {
            eventCallbacks.clear();
            connectionCallbacks.clear();
            connectionErrorCallbacks.clear();
            connectionEvents.forEach((eventName) => {
                pusher.connection.unbind(eventName, emitConnectionState);
            });
            pusher.connection.unbind('error', onConnectionError);
            if (typeof channel.unbind_all === 'function') {
                channel.unbind_all();
            }
            pusher.disconnect();
        },
    };
}

export type {
    ReverbClient,
    ReverbConfig,
    ReverbConnectionState,
    ReverbEventName,
    ReverbEventPayload,
    ReverbSubscription,
};
