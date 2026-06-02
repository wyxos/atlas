import { getStoredConnectionOptions } from './atlas-options';
import { hasAtlasApiAuth } from './atlas-auth';
import { connectWorkerReverb, type ReverbClient } from './reverb-client-worker';
import { createExtensionReverbAuthConfig, formatReverbEndpoint, parseReverbConfig } from './reverb-config';
import { fetchCachedReverbPing } from './reverb-ping-cache';

type BackgroundReverbStatus =
    | { kind: 'setup_required' }
    | { kind: 'auth_failed' }
    | { kind: 'offline' }
    | { kind: 'reverb_unavailable'; domain: string; endpoint: string | null }
    | { kind: 'connected'; domain: string; endpoint: string | null; client: ReverbClient }
    | { kind: 'disconnected'; domain: string; endpoint: string | null; detail: string };

export async function connectBackgroundReverb(): Promise<BackgroundReverbStatus> {
    try {
        const stored = await getStoredConnectionOptions();
        if (!hasAtlasApiAuth(stored.atlasDomain, stored.apiToken)) {
            return { kind: 'setup_required' };
        }

        const ping = await fetchCachedReverbPing(stored.atlasDomain, stored.apiToken);
        if (!ping.ok) {
            return ping.status === 0 ? { kind: 'offline' } : { kind: 'auth_failed' };
        }

        const config = parseReverbConfig(ping.payload?.reverb);
        const endpoint = formatReverbEndpoint(config);

        if (!config || !config.enabled) {
            return {
                kind: 'reverb_unavailable',
                domain: stored.atlasDomain,
                endpoint,
            };
        }

        try {
            const client = await connectWorkerReverb({
                ...config,
                auth: createExtensionReverbAuthConfig(stored.atlasDomain, stored.apiToken),
            });
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
