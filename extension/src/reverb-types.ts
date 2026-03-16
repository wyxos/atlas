type ReverbAuthConfig = {
    endpoint: string;
    headers: Record<string, string>;
};

type ReverbConfig = {
    enabled: boolean;
    key: string;
    host: string;
    port: number;
    scheme: 'http' | 'https';
    channel: string;
    auth: ReverbAuthConfig | null;
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
    onConnectionError: (callback: (message: string) => void) => ReverbSubscription;
    getConnectionState: () => ReverbConnectionState | null;
    getLastConnectionError: () => string | null;
    disconnect: () => void;
};

export type {
    ReverbAuthConfig,
    ReverbClient,
    ReverbConfig,
    ReverbConnectionState,
    ReverbEventName,
    ReverbEventPayload,
    ReverbSubscription,
};
