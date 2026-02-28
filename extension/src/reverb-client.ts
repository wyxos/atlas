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
        bind: (eventName: string, callback: (state: unknown) => void) => void;
    };
    subscribe: (channelName: string) => {
        bind: (eventName: string, callback: (payload: unknown) => void) => void;
        unbind_all?: () => void;
    };
    disconnect: () => void;
};

type ReverbClient = {
    onEvent: (callback: (event: ReverbEventName, payload: ReverbEventPayload) => void) => ReverbSubscription;
    onConnectionState: (callback: (state: string) => void) => ReverbSubscription;
    disconnect: () => void;
};

async function createPusher(config: ReverbConfig): Promise<PusherLike> {
    const module = await import('pusher-js');
    const PusherCtor = (module as { default?: unknown }).default ?? module;

    return new (PusherCtor as new (key: string, options: Record<string, unknown>) => PusherLike)(config.key, {
        cluster: 'mt1',
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        wsPath: '/app',
        forceTLS: config.scheme === 'https',
        enabledTransports: config.scheme === 'https' ? ['wss'] : ['ws'],
        disableStats: true,
    });
}

export async function connectReverb(config: ReverbConfig): Promise<ReverbClient | null> {
    if (!config.enabled || config.key === '' || config.host === '' || config.channel === '') {
        return null;
    }

    const pusher = await createPusher(config);
    const channel = pusher.subscribe(config.channel);
    const eventCallbacks = new Set<(event: ReverbEventName, payload: ReverbEventPayload) => void>();
    const connectionCallbacks = new Set<(state: string) => void>();

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

    pusher.connection.bind('state_change', (state: unknown) => {
        const current = (state as { current?: unknown })?.current;
        const name = typeof current === 'string' ? current : 'unknown';
        connectionCallbacks.forEach((callback) => {
            callback(name);
        });
    });

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
            return {
                unsubscribe: () => {
                    connectionCallbacks.delete(callback);
                },
            };
        },
        disconnect: () => {
            eventCallbacks.clear();
            connectionCallbacks.clear();
            if (typeof channel.unbind_all === 'function') {
                channel.unbind_all();
            }
            pusher.disconnect();
        },
    };
}

export type { ReverbConfig, ReverbEventName, ReverbEventPayload, ReverbSubscription };
