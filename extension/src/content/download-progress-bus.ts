import { getStoredOptions } from '../atlas-options';
import { connectReverb, type ReverbConfig, type ReverbSubscription } from '../reverb-client';

export type ProgressEvent = {
    event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated';
    fileId: number | null;
    transferId: number | null;
    status: string | null;
    percent: number | null;
};

type BusListener = (event: ProgressEvent) => void;

type ReverbPingResponse = {
    reverb?: unknown;
};

const listeners = new Set<BusListener>();
let connectionPromise: Promise<void> | null = null;
let activeSubscription: ReverbSubscription | null = null;
let activeClient: { disconnect: () => void } | null = null;

function asNumber(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function parseReverbConfig(value: unknown): ReverbConfig | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Record<string, unknown>;
    const enabled = row.enabled === true;
    const key = asString(row.key) ?? '';
    const host = asString(row.host) ?? '';
    const channel = asString(row.channel) ?? '';
    const scheme = row.scheme === 'http' ? 'http' : 'https';
    const port = asNumber(row.port) ?? 443;

    return {
        enabled,
        key,
        host,
        port,
        scheme,
        channel,
    };
}

async function fetchReverbConfig(): Promise<ReverbConfig | null> {
    const stored = await getStoredOptions();
    if (stored.apiToken === '') {
        return null;
    }

    const response = await fetch(`${stored.atlasDomain}/api/extension/ping`, {
        method: 'GET',
        headers: {
            'X-Atlas-Api-Key': stored.apiToken,
        },
    });

    if (!response.ok) {
        return null;
    }

    const payload = await response.json() as ReverbPingResponse;
    return parseReverbConfig(payload.reverb);
}

async function ensureConnected(): Promise<void> {
    if (connectionPromise) {
        return connectionPromise;
    }

    connectionPromise = (async () => {
        const config = await fetchReverbConfig();
        if (!config) {
            return;
        }

        const client = await connectReverb(config);
        if (!client) {
            return;
        }

        activeClient = client;
        activeSubscription = client.onEvent((event, payload) => {
            const progressEvent: ProgressEvent = {
                event,
                fileId: asNumber(payload.fileId ?? payload.file_id),
                transferId: asNumber(payload.downloadTransferId ?? payload.id),
                status: asString(payload.status),
                percent: asNumber(payload.percent),
            };

            listeners.forEach((listener) => {
                listener(progressEvent);
            });
        });
    })().finally(() => {
        connectionPromise = null;
    });

    return connectionPromise;
}

function teardownIfUnused(): void {
    if (listeners.size > 0) {
        return;
    }

    if (activeSubscription) {
        activeSubscription.unsubscribe();
        activeSubscription = null;
    }
    if (activeClient) {
        activeClient.disconnect();
        activeClient = null;
    }
}

export function subscribeToDownloadProgress(listener: BusListener): () => void {
    listeners.add(listener);
    void ensureConnected();

    return () => {
        listeners.delete(listener);
        teardownIfUnused();
    };
}
