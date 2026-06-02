import { getStoredConnectionOptions } from './atlas-options';
import { hasAtlasApiAuth } from './atlas-auth';
import { connectReverb, type ReverbClient, type ReverbConnectionState } from './reverb-client';
import { requestAtlasViaRuntime } from './atlas-runtime-request';
import { createExtensionReverbAuthConfig, formatReverbEndpoint, parseReverbConfig } from './reverb-config';
import { fetchCachedReverbPing, type ReverbPingPayload } from './reverb-ping-cache';
import type { ReverbConfig } from './reverb-types';

type RuntimeReverbStatus =
    | { kind: 'setup_required' }
    | { kind: 'auth_failed' }
    | { kind: 'offline' }
    | { kind: 'reverb_unavailable'; domain: string; endpoint: string | null }
    | { kind: 'connected'; domain: string; endpoint: string | null; client: ReverbClient }
    | { kind: 'disconnected'; domain: string; endpoint: string | null; detail: string };

type RuntimeReverbAvailabilityStatus =
    | { kind: 'setup_required' }
    | { kind: 'auth_failed' }
    | { kind: 'offline' }
    | { kind: 'reverb_unavailable'; domain: string; endpoint: string | null }
    | { kind: 'available'; domain: string; apiToken: string; endpoint: string | null; config: ReverbConfig };

type StoredReverbConnection = {
    atlasDomain: string;
    apiToken: string;
};

async function loadRuntimeReverbPing(stored: StoredReverbConnection): Promise<{
    kind: 'ok';
    payload: ReverbPingPayload | null;
} | {
    kind: 'auth_failed' | 'offline';
}> {
    const pingEndpoint = `${stored.atlasDomain}/api/extension/ping`;
    const runtimeResponse = await requestAtlasViaRuntime({
        endpoint: pingEndpoint,
        atlasDomain: stored.atlasDomain,
        apiToken: stored.apiToken,
        method: 'GET',
    });
    if (runtimeResponse !== null) {
        if (!runtimeResponse.ok) {
            return { kind: runtimeResponse.status === 0 ? 'offline' : 'auth_failed' };
        }

        return {
            kind: 'ok',
            payload: runtimeResponse.payload as ReverbPingPayload | null,
        };
    }

    const directResponse = await fetchCachedReverbPing(stored.atlasDomain, stored.apiToken);
    if (!directResponse.ok) {
        return { kind: directResponse.status === 0 ? 'offline' : 'auth_failed' };
    }

    return {
        kind: 'ok',
        payload: directResponse.payload,
    };
}

async function resolveRuntimeReverbAvailability(): Promise<RuntimeReverbAvailabilityStatus> {
    try {
        const stored = await getStoredConnectionOptions();
        if (!hasAtlasApiAuth(stored.atlasDomain, stored.apiToken)) {
            return { kind: 'setup_required' };
        }

        const ping = await loadRuntimeReverbPing(stored);
        if (ping.kind !== 'ok') {
            return { kind: ping.kind };
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

        return {
            kind: 'available',
            domain: stored.atlasDomain,
            apiToken: stored.apiToken,
            endpoint,
            config,
        };
    } catch {
        return { kind: 'offline' };
    }
}

async function connectRuntimeReverb(): Promise<RuntimeReverbStatus> {
    try {
        const runtime = await resolveRuntimeReverbAvailability();
        if (runtime.kind !== 'available') {
            return runtime;
        }

        try {
            const client = await connectReverb({
                ...runtime.config,
                auth: createExtensionReverbAuthConfig(runtime.domain, runtime.apiToken),
            });
            if (!client) {
                return {
                    kind: 'disconnected',
                    domain: runtime.domain,
                    endpoint: runtime.endpoint,
                    detail: 'Unable to initialize Reverb client.',
                };
            }

            return {
                kind: 'connected',
                domain: runtime.domain,
                endpoint: runtime.endpoint,
                client,
            };
        } catch (error) {
            return {
                kind: 'disconnected',
                domain: runtime.domain,
                endpoint: runtime.endpoint,
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
            const currentState = client.getConnectionState();
            resolve({
                state: currentState === 'connected' || currentState === 'failed' || currentState === 'disconnected'
                    ? currentState
                    : 'timeout',
                error: lastError ?? client.getLastConnectionError(),
            });
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
    resolveRuntimeReverbAvailability,
    waitForReverbState,
    type RuntimeReverbAvailabilityStatus,
    type RuntimeReverbStatus,
};
