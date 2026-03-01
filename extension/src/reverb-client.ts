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
    disconnect: () => void;
};

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
        wsPath: '/app',
        forceTLS: config.scheme === 'https',
        enabledTransports: config.scheme === 'https' ? ['wss'] : ['ws'],
        disableStats: true,
    });

    const channel = pusher.subscribe(config.channel);
    const eventCallbacks = new Set<(event: ReverbEventName, payload: ReverbEventPayload) => void>();
    const connectionCallbacks = new Set<(state: ReverbConnectionState) => void>();

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

    const onStateChange = (state: unknown) => {
        const current = (state as { current?: unknown })?.current;
        const raw = typeof current === 'string' ? current : (typeof pusher.connection.state === 'string' ? pusher.connection.state : 'disconnected');
        const normalized = raw === 'connected'
            ? 'connected'
            : raw === 'connecting'
                ? 'connecting'
                : raw === 'unavailable' || raw === 'failed'
                    ? 'failed'
                    : raw === 'disconnected'
                        ? 'disconnected'
                        : 'reconnecting';
        connectionCallbacks.forEach((callback) => {
            callback(normalized);
        });
    };
    pusher.connection.bind('state_change', onStateChange);

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
            const currentState: ReverbConnectionState | null = raw === 'connected'
                ? 'connected'
                : raw === 'connecting'
                    ? 'connecting'
                    : raw === 'unavailable' || raw === 'failed'
                        ? 'failed'
                        : raw === 'disconnected'
                            ? 'disconnected'
                            : raw === null
                                ? null
                                : 'reconnecting';
            if (currentState !== null) {
                callback(currentState);
            }
            return {
                unsubscribe: () => {
                    connectionCallbacks.delete(callback);
                },
            };
        },
        disconnect: () => {
            eventCallbacks.clear();
            connectionCallbacks.clear();
            pusher.connection.unbind('state_change', onStateChange);
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
