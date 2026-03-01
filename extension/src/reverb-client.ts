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

    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    const echo = new Echo<'reverb'>({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: config.scheme === 'https' ? ['wss'] : ['ws'],
        disableStats: true,
        cluster: '',
        namespace: false,
        withoutInterceptors: true,
        Pusher,
    });

    const channel = echo.channel(config.channel);
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

    channel.listen('.DownloadTransferCreated', (payload: unknown) => {
        emitEvent('DownloadTransferCreated', payload);
    });
    channel.listen('.DownloadTransferQueued', (payload: unknown) => {
        emitEvent('DownloadTransferQueued', payload);
    });
    channel.listen('.DownloadTransferProgressUpdated', (payload: unknown) => {
        emitEvent('DownloadTransferProgressUpdated', payload);
    });

    const stopConnectionChangeListener = echo.connector.onConnectionChange((state) => {
        connectionCallbacks.forEach((callback) => {
            callback(state);
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
            const currentState = echo.connectionStatus();
            if (currentState) {
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
            stopConnectionChangeListener();
            channel.stopListening('.DownloadTransferCreated');
            channel.stopListening('.DownloadTransferQueued');
            channel.stopListening('.DownloadTransferProgressUpdated');
            echo.leaveChannel(config.channel);
            echo.disconnect();
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
