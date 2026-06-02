import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockResolveRuntimeReverbAvailability = vi.fn();

vi.mock('./reverb-runtime', () => ({
    resolveRuntimeReverbAvailability: mockResolveRuntimeReverbAvailability,
}));

describe('resolveApiConnectionStatus', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
    });

    it.each([
        [
            'setup required',
            { kind: 'setup_required' },
            {
                label: 'Setup required',
                detail: 'Set the API key in extension options before using Atlas API actions.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Requires API key first.',
                reverbEndpoint: null,
            },
        ],
        [
            'auth failed',
            { kind: 'auth_failed' },
            {
                label: 'Auth failed',
                detail: 'API key or domain is invalid. Update extension options.',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Cannot test Reverb until API auth succeeds.',
                reverbEndpoint: null,
            },
        ],
        [
            'offline',
            { kind: 'offline' },
            {
                label: 'Offline',
                detail: 'Unable to verify API access. Check extension options.',
                reverbLabel: 'Disconnected',
                reverbDetail: 'Unable to reach Atlas.',
                reverbEndpoint: null,
            },
        ],
        [
            'reverb unavailable',
            { kind: 'reverb_unavailable', domain: 'https://atlas.test', endpoint: 'wss://atlas.test/reverb' },
            {
                label: 'Ready',
                detail: 'Connected to https://atlas.test',
                reverbLabel: 'Unavailable',
                reverbDetail: 'Reverb is not configured on Atlas.',
                reverbEndpoint: 'wss://atlas.test/reverb',
            },
        ],
        [
            'available runtime',
            {
                kind: 'available',
                domain: 'https://atlas.test',
                endpoint: 'wss://atlas.test/reverb',
                config: {},
            },
            {
                label: 'Ready',
                detail: 'Connected to https://atlas.test',
                reverbLabel: 'Available',
                reverbDetail: 'Reverb config is available.',
                reverbEndpoint: 'wss://atlas.test/reverb',
            },
        ],
    ])('maps the %s runtime result', async (_label, runtimeResult, expected) => {
        mockResolveRuntimeReverbAvailability.mockResolvedValueOnce(runtimeResult);

        const { resolveApiConnectionStatus } = await import('./atlas-api');
        await expect(resolveApiConnectionStatus()).resolves.toEqual(expected);
    });
});
