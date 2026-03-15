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
            channel: 'downloads',
        });

        expect(client).not.toBeNull();
        expect(pusherCtor).toHaveBeenCalledWith('atlas-key', expect.objectContaining({
            wsHost: 'atlas.wyxos.com',
            wsPort: 443,
            forceTLS: true,
        }));
        expect(subscribe).toHaveBeenCalledWith('downloads');
    });
});
