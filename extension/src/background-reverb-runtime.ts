import { getStoredOptions } from './atlas-options';
import { connectReverb, type ReverbClient } from './reverb-client';
import { formatReverbEndpoint, parseReverbConfig } from './reverb-runtime';

type BackgroundReverbStatus =
    | { kind: 'setup_required' }
    | { kind: 'auth_failed' }
    | { kind: 'offline' }
    | { kind: 'reverb_unavailable'; domain: string; endpoint: string | null }
    | { kind: 'connected'; domain: string; endpoint: string | null; client: ReverbClient }
    | { kind: 'disconnected'; domain: string; endpoint: string | null; detail: string };

type ReverbPingResponse = {
    reverb?: unknown;
};

export async function connectBackgroundReverb(): Promise<BackgroundReverbStatus> {
    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return { kind: 'setup_required' };
        }

        const pingEndpoint = `${stored.atlasDomain}/api/extension/ping`;
        const response = await fetch(pingEndpoint, {
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

export type {
    BackgroundReverbStatus,
};
