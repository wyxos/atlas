import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockConnectReverb = vi.fn();

vi.mock('./atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('./reverb-client', () => ({
    connectReverb: mockConnectReverb,
}));

describe('connectRuntimeReverb', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            siteCustomizations: [],
        });
    });

    it('uses the background runtime ping before falling back to page fetch', async () => {
        const fetchMock = vi.fn();
        const runtimeSendMessage = vi.fn((payload: unknown, callback: (response: unknown) => void) => {
            const typed = payload as Record<string, unknown>;
            if (typed.type === 'ATLAS_API_REQUEST') {
                callback({
                    ok: true,
                    status: 200,
                    payload: {
                        reverb: {
                            enabled: false,
                            key: '',
                            host: '',
                            port: 443,
                            scheme: 'https',
                            channel: '',
                            auth: null,
                        },
                    },
                });
                return;
            }

            callback(null);
        });

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { connectRuntimeReverb } = await import('./reverb-runtime');
        const result = await connectRuntimeReverb();

        expect(result).toEqual({
            kind: 'reverb_unavailable',
            domain: 'https://atlas.wyxos.com',
            endpoint: null,
        });
        expect(runtimeSendMessage).toHaveBeenCalledWith(
            {
                type: 'ATLAS_API_REQUEST',
                endpoint: 'https://atlas.wyxos.com/api/extension/ping',
                atlasDomain: 'https://atlas.wyxos.com',
                apiToken: 'test-api-token',
                method: 'GET',
                body: null,
            },
            expect.any(Function),
        );
        expect(fetchMock).not.toHaveBeenCalled();
        expect(mockConnectReverb).not.toHaveBeenCalled();
    });

    it('falls back to direct fetch when the runtime request is unavailable', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reverb: {
                    enabled: false,
                    key: '',
                    host: '',
                    port: 443,
                    scheme: 'https',
                    channel: '',
                    auth: null,
                },
            }), { status: 200 }),
        );
        const runtimeSendMessage = vi.fn((_: unknown, callback: (response: unknown) => void) => {
            callback(undefined);
        });

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: runtimeSendMessage,
            },
        });

        const { connectRuntimeReverb } = await import('./reverb-runtime');
        const result = await connectRuntimeReverb();

        expect(result).toEqual({
            kind: 'reverb_unavailable',
            domain: 'https://atlas.wyxos.com',
            endpoint: null,
        });
        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/ping', {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': 'test-api-token',
            },
        });
        expect(mockConnectReverb).not.toHaveBeenCalled();
    });

    it('connects when Reverb is enabled', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reverb: {
                    enabled: true,
                    key: 'atlas-key',
                    host: 'atlas.wyxos.com',
                    port: 443,
                    scheme: 'https',
                    channel: 'private-extension-downloads.test-hash',
                    auth: null,
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
        mockConnectReverb.mockResolvedValue(connectedClient);

        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage: vi.fn((_: unknown, callback: (response: unknown) => void) => {
                    callback(undefined);
                }),
            },
        });

        const { connectRuntimeReverb } = await import('./reverb-runtime');
        const result = await connectRuntimeReverb();

        expect(mockConnectReverb).toHaveBeenCalledWith({
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
