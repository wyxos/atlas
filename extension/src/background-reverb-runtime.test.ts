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
            siteCustomizations: [],
        });
    });

    it('returns setup required when no API token is configured', async () => {
        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: '',
            siteCustomizations: [],
        });
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');

        await expect(connectBackgroundReverb()).resolves.toEqual({ kind: 'setup_required' });
        expect(fetchMock).not.toHaveBeenCalled();
        expect(mockConnectWorkerReverb).not.toHaveBeenCalled();
    });

    it('returns auth failed when the extension ping is rejected', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response('', { status: 401 }));
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');

        await expect(connectBackgroundReverb()).resolves.toEqual({ kind: 'auth_failed' });
        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/ping', {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': 'test-api-token',
            },
        });
    });

    it('returns reverb unavailable when Atlas reports Reverb disabled', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            new Response(JSON.stringify({
                reverb: {
                    enabled: false,
                    host: 'atlas.wyxos.com',
                    port: 443,
                    scheme: 'https',
                },
            }), { status: 200 }),
        );
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');

        await expect(connectBackgroundReverb()).resolves.toEqual({
            kind: 'reverb_unavailable',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
        });
        expect(mockConnectWorkerReverb).not.toHaveBeenCalled();
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

    it('returns disconnected when the worker runtime cannot initialize a client', async () => {
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
        mockConnectWorkerReverb.mockResolvedValue(null);
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');

        await expect(connectBackgroundReverb()).resolves.toEqual({
            kind: 'disconnected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            detail: 'Unable to initialize Reverb client.',
        });
    });

    it('returns disconnected when the worker runtime throws a connection error', async () => {
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
        mockConnectWorkerReverb.mockRejectedValue(new Error('socket refused'));
        vi.stubGlobal('fetch', fetchMock);

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');

        await expect(connectBackgroundReverb()).resolves.toEqual({
            kind: 'disconnected',
            domain: 'https://atlas.wyxos.com',
            endpoint: 'https://atlas.wyxos.com:443',
            detail: 'Reverb connection failed: socket refused',
        });
    });

    it('returns offline when stored options or the ping request cannot be read', async () => {
        mockGetStoredOptions.mockRejectedValue(new Error('storage unavailable'));

        const { connectBackgroundReverb } = await import('./background-reverb-runtime');
        await expect(connectBackgroundReverb()).resolves.toEqual({ kind: 'offline' });

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas.wyxos.com',
            apiToken: 'test-api-token',
            siteCustomizations: [],
        });
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')));

        await expect(connectBackgroundReverb()).resolves.toEqual({ kind: 'offline' });
    });
});
