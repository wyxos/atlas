import { getStoredOptions } from './atlas-options';
import { connectReverb, type ReverbClient, type ReverbConnectionState } from './reverb-client';
import { requestAtlasViaRuntime } from './atlas-runtime-request';
import { formatReverbEndpoint, parseReverbConfig } from './reverb-config';

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

async function connectRuntimeReverb(): Promise<RuntimeReverbStatus> {
    try {
        const stored = await getStoredOptions();
        if (stored.apiToken === '') {
            return { kind: 'setup_required' };
        }

        const pingEndpoint = `${stored.atlasDomain}/api/extension/ping`;
        let payload: ReverbPingResponse | null = null;
        const runtimeResponse = await requestAtlasViaRuntime({
            endpoint: pingEndpoint,
            atlasDomain: stored.atlasDomain,
            apiToken: stored.apiToken,
            method: 'GET',
        });
        if (runtimeResponse !== null) {
            if (!runtimeResponse.ok) {
                return { kind: 'auth_failed' };
            }

            payload = runtimeResponse.payload as ReverbPingResponse;
        } else {
            const response = await fetch(pingEndpoint, {
                method: 'GET',
                headers: {
                    'X-Atlas-Api-Key': stored.apiToken,
                },
            });

            if (!response.ok) {
                return { kind: 'auth_failed' };
            }

            payload = await response.json() as ReverbPingResponse;
        }

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
    waitForReverbState,
    type RuntimeReverbStatus,
};
