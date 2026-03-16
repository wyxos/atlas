import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockConnectWorkerReverb = vi.fn();

vi.mock('./atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./reverb-client-worker', () => ({
    connectWorkerReverb: mockConnectWorkerReverb,
}));

describe('connectBackgroundReverb', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            matchRules: [],
            referrerQueryParamsToStripByDomain: {},
        });
    });

    it('connects using the worker runtime when Reverb is enabled', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reverb: {
                    enabled: true,
                    key: 'atlas-key',
                    host: 'atlas.wyxos.com',
                    port: 443,
                    scheme: 'https',
                    channel: 'private-extension-downloads.test-hash',
                },
            }), { status: 200 }),
        );

        const connectedClient = {
            onEvent: vi.fn(),
            onConnectionState: vi.fn(),
            onConnectionError: vi.fn(),
            getConnectionState: vi.fn(),
            getLastConnectionError: vi.fn(),
            disconnect: vi.fn(),
        };
        mockConnectWorkerReverb.mockResolvedValue(connectedClient);
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');
        const result = await connectBackgroundReverb();

        expect(mockConnectWorkerReverb).toHaveBeenCalledWith({
            enabled: true,
            key: 'atlas-key',
            host: 'atlas.wyxos.com',
            port: 443,
            scheme: 'https',
            channel: 'private-extension-downloads.test-hash',
            auth: {
                endpoint: 'https://atlas.wyxos.com/api/extension/broadcasting/auth',
                headers: {
                    'X-Atlas-Api-Key': 'test-api-token',
                },
            },
        });
        expect(result).toEqual({
            kind: 'connected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            client: connectedClient,
        });
    });
});
