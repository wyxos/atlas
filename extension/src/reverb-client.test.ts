import { beforeEach, describe, expect, it, vi } from 'vitest';

const subscribe = vi.fn(() => ({
    bind: vi.fn(),
    unbind_all: vi.fn(),
}));
const disconnect = vi.fn();
const bindConnection = vi.fn();
const unbindConnection = vi.fn();
const pusherCtor = vi.fn(function PusherMock() {
    return {
        connection: {
            state: 'initialized',
            bind: bindConnection,
            unbind: unbindConnection,
        },
        subscribe,
        disconnect,
    };
});

vi.mock('pusher-js', () => ({
    default: pusherCtor,
}));

describe('connectReverb (browser)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('returns null when config is incomplete', async () => {
        const { connectReverb } = await import('./reverb-client');
        const client = await connectReverb({
            enabled: false,
            key: '',
            host: '',
            port: 443,
            scheme: 'https',
            channel: '',
            auth: null,
        });

        expect(client).toBeNull();
        expect(pusherCtor).not.toHaveBeenCalled();
    });

    it('builds a client with the browser pusher constructor', async () => {
        const { connectReverb } = await import('./reverb-client');
        const client = await connectReverb({
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

        expect(client).not.toBeNull();
        expect(pusherCtor).toHaveBeenCalledWith('atlas-key', expect.objectContaining({
            wsHost: 'atlas.wyxos.com',
            wsPort: 443,
            forceTLS: true,
        }));
        const options = pusherCtor.mock.calls[0]?.[1] as {
            channelAuthorization?: {
                endpoint: string;
                headersProvider: () => Record<string, string>;
            };
        };
        expect(options.channelAuthorization?.endpoint).toBe('https://atlas.wyxos.com/api/extension/broadcasting/auth');
        expect(options.channelAuthorization?.headersProvider()).toEqual({
            'X-Atlas-Api-Key': 'test-api-token',
        });
        expect(subscribe).toHaveBeenCalledWith('private-extension-downloads.test-hash');
    });
});
