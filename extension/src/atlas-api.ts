import { getStoredOptions } from './atlas-options';
import { connectReverb, type ReverbConfig } from './reverb-client';

export type AtlasApiConnectionStatus = {
    label: 'Ready' | 'Setup required' | 'Auth failed' | 'Offline';
    detail: string;
    reverbLabel: 'Connected' | 'Disconnected' | 'Unavailable';
    reverbDetail: string;
    reverbEndpoint: string | null;
};

type PingResponse = {
    reverb?: unknown;
};

function asString(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function asNumber(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
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

async function probeReverb(config: ReverbConfig | null): Promise<{
    label: 'Connected' | 'Disconnected' | 'Unavailable';
    detail: string;
}> {
    if (!config || !config.enabled) {
        return {
            label: 'Unavailable',
            detail: 'Reverb is not configured on Atlas.',
        };
    }

    try {
        const client = await connectReverb(config);
        if (!client) {
            return {
                label: 'Disconnected',
                detail: 'Unable to initialize Reverb client.',
            };
        }

        const state = await new Promise<string>((resolve) => {
            let done = false;
            let subscription: { unsubscribe: () => void } | null = null;
            const timeout = window.setTimeout(() => {
                if (done) {
                    return;
                }
                done = true;
                resolve('timeout');
            }, 4500);

            subscription = client.onConnectionState((next) => {
                if (done) {
                    return;
                }

                if (next === 'connected' || next === 'unavailable' || next === 'failed') {
                    done = true;
                    window.clearTimeout(timeout);
                    resolve(next);
                    subscription?.unsubscribe();
                }
            });

            if (done) {
                subscription.unsubscribe();
            }
        });

        client.disconnect();

        if (state === 'connected') {
            return {
                label: 'Connected',
                detail: 'Reverb websocket connected.',
            };
        }

        return {
            label: 'Disconnected',
            detail: state === 'timeout'
                ? 'Reverb websocket timed out.'
                : `Reverb websocket state: ${state}.`,
        };
    } catch (error) {
        const detail = error instanceof Error && error.message.trim() !== ''
            ? `Reverb probe failed: ${error.message}`
            : 'Reverb probe failed.';

        return {
            label: 'Disconnected',
            detail,
        };
    }
}

export async function resolveApiConnectionStatus(): Promise<AtlasApiConnectionStatus> {
    try {
        const stored = await getStoredOptions();
        const domain = stored.atlasDomain;
        const apiToken = stored.apiToken;

        if (apiToken === '') {
            return {
                label: 'Setup required',
                detail: 'Set the API key in extension options before using Atlas API actions.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Requires API key first.',
                reverbEndpoint: null,
            };
        }

        const response = await fetch(`${domain}/api/extension/ping`, {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': apiToken,
            },
        });

        if (!response.ok) {
            return {
                label: 'Auth failed',
                detail: 'API key or domain is invalid. Update extension options.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Cannot test Reverb until API auth succeeds.',
                reverbEndpoint: null,
            };
        }

        const payload = await response.json() as PingResponse;
        const reverbConfig = parseReverbConfig(payload.reverb);
        const reverb = await probeReverb(reverbConfig);
        const reverbEndpoint = formatReverbEndpoint(reverbConfig);

        return {
            label: 'Ready',
            detail: `Connected to ${domain}`,
            reverbLabel: reverb.label,
            reverbDetail: reverb.detail,
            reverbEndpoint,
        };
    } catch {
        return {
            label: 'Offline',
            detail: 'Unable to verify API access. Check extension options.',
            reverbLabel: 'Disconnected',
            reverbDetail: 'Unable to reach Atlas.',
            reverbEndpoint: null,
        };
    }
}
