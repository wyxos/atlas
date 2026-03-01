import { getStoredOptions } from './atlas-options';
import { connectReverb, type ReverbClient, type ReverbConfig, type ReverbConnectionState } from './reverb-client';

type RuntimeReverbStatus =
    | { kind: 'setup_required' }
    | { kind: 'auth_failed' }
    | { kind: 'offline' }
    | { kind: 'reverb_unavailable'; domain: string; endpoint: string | null }
    | { kind: 'connected'; domain: string; endpoint: string | null; client: ReverbClient }
    | { kind: 'disconnected'; domain: string; endpoint: string | null; detail: string };

type ReverbPingResponse = {
    reverb?: unknown;
};

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asNumber(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed === '') {
            return null;
        }

        const parsed = Number(trimmed);
        return Number.isFinite(parsed) ? parsed : null;
    }

    return null;
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

function formatReverbEndpoint(config: ReverbConfig | null): string | null {
    if (!config || config.host === '' || !Number.isFinite(config.port) || config.port <= 0) {
        return null;
    }

    return `${config.scheme}://${config.host}:${config.port}`;
}

async function connectRuntimeReverb(): Promise<RuntimeReverbStatus> {
    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return { kind: 'setup_required' };
        }

        const response = await fetch(`${stored.atlasDomain}/api/extension/ping`, {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': stored.apiToken,
            },
        });

        if (!response.ok) {
            return { kind: 'auth_failed' };
        }

        const payload = await response.json() as ReverbPingResponse;
        const config = parseReverbConfig(payload.reverb);
        const endpoint = formatReverbEndpoint(config);

        if (!config || !config.enabled) {
            return {
                kind: 'reverb_unavailable',
                domain: stored.atlasDomain,
                endpoint,
            };
        }

        try {
            const client = await connectReverb(config);
            if (!client) {
                return {
                    kind: 'disconnected',
                    domain: stored.atlasDomain,
                    endpoint,
                    detail: 'Unable to initialize Reverb client.',
                };
            }

            return {
                kind: 'connected',
                domain: stored.atlasDomain,
                endpoint,
                client,
            };
        } catch (error) {
            return {
                kind: 'disconnected',
                domain: stored.atlasDomain,
                endpoint,
                detail: error instanceof Error && error.message.trim() !== ''
                    ? `Reverb connection failed: ${error.message}`
                    : 'Reverb connection failed.',
            };
        }
    } catch {
        return { kind: 'offline' };
    }
}

async function waitForReverbState(
    client: ReverbClient,
    timeoutMs = 4500,
): Promise<{ state: ReverbConnectionState | 'timeout'; error: string | null }> {
    return new Promise((resolve) => {
        let finished = false;
        let subscription: { unsubscribe: () => void } | null = null;
        let errorSubscription: { unsubscribe: () => void } | null = null;
        let lastError: string | null = null;

        const timeout = window.setTimeout(() => {
            if (finished) {
                return;
            }

            finished = true;
            subscription?.unsubscribe();
            errorSubscription?.unsubscribe();
            resolve({ state: 'timeout', error: lastError ?? client.getLastConnectionError() });
        }, timeoutMs);

        errorSubscription = client.onConnectionError((message) => {
            lastError = message;
        });

        subscription = client.onConnectionState((state) => {
            if (finished) {
                return;
            }

            if (state === 'connected' || state === 'failed' || state === 'disconnected') {
                finished = true;
                window.clearTimeout(timeout);
                subscription?.unsubscribe();
                errorSubscription?.unsubscribe();
                resolve({ state, error: lastError ?? client.getLastConnectionError() });
            }
        });
    });
}

export {
    connectRuntimeReverb,
    formatReverbEndpoint,
    parseReverbConfig,
    waitForReverbState,
    type RuntimeReverbStatus,
};
