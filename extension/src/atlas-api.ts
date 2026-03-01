import { connectRuntimeReverb, waitForReverbState } from './reverb-runtime';

export type AtlasApiConnectionStatus = {
    label: 'Ready' | 'Setup required' | 'Auth failed' | 'Offline';
    detail: string;
    reverbLabel: 'Connected' | 'Disconnected' | 'Unavailable';
    reverbDetail: string;
    reverbEndpoint: string | null;
};

export async function resolveApiConnectionStatus(): Promise<AtlasApiConnectionStatus> {
    const runtime = await connectRuntimeReverb();
    switch (runtime.kind) {
        case 'setup_required':
            return {
                label: 'Setup required',
                detail: 'Set the API key in extension options before using Atlas API actions.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Requires API key first.',
                reverbEndpoint: null,
            };
        case 'auth_failed':
            return {
                label: 'Auth failed',
                detail: 'API key or domain is invalid. Update extension options.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Cannot test Reverb until API auth succeeds.',
                reverbEndpoint: null,
            };
        case 'offline':
            return {
                label: 'Offline',
                detail: 'Unable to verify API access. Check extension options.',
                reverbLabel: 'Disconnected',
                reverbDetail: 'Unable to reach Atlas.',
                reverbEndpoint: null,
            };
        case 'reverb_unavailable':
            return {
                label: 'Ready',
                detail: `Connected to ${runtime.domain}`,
                reverbLabel: 'Unavailable',
                reverbDetail: 'Reverb is not configured on Atlas.',
                reverbEndpoint: runtime.endpoint,
            };
        case 'disconnected':
            return {
                label: 'Ready',
                detail: `Connected to ${runtime.domain}`,
                reverbLabel: 'Disconnected',
                reverbDetail: runtime.detail,
                reverbEndpoint: runtime.endpoint,
            };
        case 'connected': {
            const stateResult = await waitForReverbState(runtime.client);
            runtime.client.disconnect();

            if (stateResult.state === 'connected') {
                return {
                    label: 'Ready',
                    detail: `Connected to ${runtime.domain}`,
                    reverbLabel: 'Connected',
                    reverbDetail: 'Reverb websocket connected.',
                    reverbEndpoint: runtime.endpoint,
                };
            }

            return {
                label: 'Ready',
                detail: `Connected to ${runtime.domain}`,
                reverbLabel: 'Disconnected',
                reverbDetail: stateResult.state === 'timeout'
                    ? 'Reverb websocket timed out.'
                    : stateResult.error
                        ? `Reverb websocket state: ${stateResult.state}. ${stateResult.error}`
                        : `Reverb websocket state: ${stateResult.state}.`,
                reverbEndpoint: runtime.endpoint,
            };
        }
    }
}
