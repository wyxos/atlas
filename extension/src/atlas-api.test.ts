import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockConnectRuntimeReverb = vi.fn();
const mockWaitForReverbState = vi.fn();

vi.mock('./reverb-runtime', () => ({
    connectRuntimeReverb: mockConnectRuntimeReverb,
    waitForReverbState: mockWaitForReverbState,
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
            'disconnected runtime',
            {
                kind: 'disconnected',
                domain: 'https://atlas.test',
                endpoint: 'wss://atlas.test/reverb',
                detail: 'socket closed',
            },
            {
                label: 'Ready',
                detail: 'Connected to https://atlas.test',
                reverbLabel: 'Disconnected',
                reverbDetail: 'socket closed',
                reverbEndpoint: 'wss://atlas.test/reverb',
            },
        ],
    ])('maps the %s runtime result', async (_label, runtimeResult, expected) => {
        mockConnectRuntimeReverb.mockResolvedValueOnce(runtimeResult);

        const { resolveApiConnectionStatus } = await import('./atlas-api');
        await expect(resolveApiConnectionStatus()).resolves.toEqual(expected);
        expect(mockWaitForReverbState).not.toHaveBeenCalled();
    });

    it('maps a fully connected runtime result and disconnects the probe client', async () => {
        const client = {
            disconnect: vi.fn(),
        };
        mockConnectRuntimeReverb.mockResolvedValueOnce({
            kind: 'connected',
            domain: 'https://atlas.test',
            endpoint: 'wss://atlas.test/reverb',
            client,
        });
        mockWaitForReverbState.mockResolvedValueOnce({
            state: 'connected',
            error: null,
        });

        const { resolveApiConnectionStatus } = await import('./atlas-api');
        await expect(resolveApiConnectionStatus()).resolves.toEqual({
            label: 'Ready',
            detail: 'Connected to https://atlas.test',
            reverbLabel: 'Connected',
            reverbDetail: 'Reverb websocket connected.',
            reverbEndpoint: 'wss://atlas.test/reverb',
        });
        expect(mockWaitForReverbState).toHaveBeenCalledWith(client);
        expect(client.disconnect).toHaveBeenCalledTimes(1);
    });

    it('maps a timed out websocket state to a disconnected reverb detail', async () => {
        const client = {
            disconnect: vi.fn(),
        };
        mockConnectRuntimeReverb.mockResolvedValueOnce({
            kind: 'connected',
            domain: 'https://atlas.test',
            endpoint: 'wss://atlas.test/reverb',
            client,
        });
        mockWaitForReverbState.mockResolvedValueOnce({
            state: 'timeout',
            error: null,
        });

        const { resolveApiConnectionStatus } = await import('./atlas-api');
        await expect(resolveApiConnectionStatus()).resolves.toEqual({
            label: 'Ready',
            detail: 'Connected to https://atlas.test',
            reverbLabel: 'Disconnected',
            reverbDetail: 'Reverb websocket timed out.',
            reverbEndpoint: 'wss://atlas.test/reverb',
        });
        expect(client.disconnect).toHaveBeenCalledTimes(1);
    });

    it('includes the websocket error when a connected runtime settles in a failed state', async () => {
        const client = {
            disconnect: vi.fn(),
        };
        mockConnectRuntimeReverb.mockResolvedValueOnce({
            kind: 'connected',
            domain: 'https://atlas.test',
            endpoint: 'wss://atlas.test/reverb',
            client,
        });
        mockWaitForReverbState.mockResolvedValueOnce({
            state: 'failed',
            error: 'socket refused',
        });

        const { resolveApiConnectionStatus } = await import('./atlas-api');
        await expect(resolveApiConnectionStatus()).resolves.toEqual({
            label: 'Ready',
            detail: 'Connected to https://atlas.test',
            reverbLabel: 'Disconnected',
            reverbDetail: 'Reverb websocket state: failed. socket refused',
            reverbEndpoint: 'wss://atlas.test/reverb',
        });
        expect(client.disconnect).toHaveBeenCalledTimes(1);
    });
});
