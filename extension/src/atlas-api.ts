import { resolveRuntimeReverbAvailability } from './reverb-runtime';

export type AtlasApiConnectionStatus = {
    label: 'Ready' | 'Setup required' | 'Auth failed' | 'Offline';
    detail: string;
    reverbLabel: 'Available' | 'Disconnected' | 'Unavailable';
    reverbDetail: string;
    reverbEndpoint: string | null;
};

export async function resolveApiConnectionStatus(): Promise<AtlasApiConnectionStatus> {
    const runtime = await resolveRuntimeReverbAvailability();
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
        case 'available':
            return {
                label: 'Ready',
                detail: `Connected to ${runtime.domain}`,
                reverbLabel: 'Available',
                reverbDetail: 'Reverb config is available.',
                reverbEndpoint: runtime.endpoint,
            };
    }
}
