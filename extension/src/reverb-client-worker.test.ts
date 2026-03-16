import { beforeEach, describe, expect, it, vi } from 'vitest';
import { connectWorkerReverb, type WorkerFetch, type WorkerWebSocketCtor } from './reverb-client-worker';

type Listener = (event: unknown) => void;

class FakeWebSocket {
    static instances: FakeWebSocket[] = [];

    readonly url: string;
    readonly sentMessages: string[] = [];
    private listeners = new Map<string, Set<Listener>>();

    constructor(url: string) {
        this.url = url;
        FakeWebSocket.instances.push(this);
    }

    addEventListener(type: string, listener: Listener): void {
        const existing = this.listeners.get(type) ?? new Set<Listener>();
        existing.add(listener);
        this.listeners.set(type, existing);
    }

    removeEventListener(type: string, listener: Listener): void {
        const existing = this.listeners.get(type);
        existing?.delete(listener);
    }

    send(data: string): void {
        this.sentMessages.push(data);
    }

    close(): void {
        this.emit('close', { code: 1000, reason: 'closed', wasClean: true });
    }

    emit(type: string, event: unknown): void {
        const callbacks = this.listeners.get(type);
        if (!callbacks) {
            return;
        }

        callbacks.forEach((callback) => {
            callback(event);
        });
    }
}

const baseConfig = {
    enabled: true,
    key: 'atlas-key',
    host: 'atlas.wyxos.com',
    port: 443,
    scheme: 'https' as const,
    channel: 'private-extension-downloads.test-hash',
    auth: {
        endpoint: 'https://atlas.wyxos.com/api/extension/broadcasting/auth',
        headers: {
            'X-Atlas-Api-Key': 'test-api-token',
        },
    },
};

function getLastSocket(): FakeWebSocket {
    const socket = FakeWebSocket.instances.at(-1);
    if (!socket) {
        throw new Error('Expected fake websocket instance.');
    }

    return socket;
}

describe('connectWorkerReverb', () => {
    beforeEach(() => {
        FakeWebSocket.instances = [];
        vi.useRealTimers();
    });

    it('authenticates and subscribes to the configured private channel after websocket handshake', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                auth: 'atlas-key:test-signature',
            }),
        })) as unknown as WorkerFetch;
        const client = await connectWorkerReverb(
            baseConfig,
            FakeWebSocket as unknown as WorkerWebSocketCtor,
            fetchMock,
        );

        expect(client).not.toBeNull();
        const socket = getLastSocket();
        expect(socket.url).toBe('wss://atlas.wyxos.com:443/app/atlas-key?protocol=7&client=atlas-extension&version=1.0&flash=false');

        const states = vi.fn();
        client?.onConnectionState(states);
        expect(states).toHaveBeenCalledWith('connecting');

        socket.emit('message', {
            data: JSON.stringify({
                event: 'pusher:connection_established',
                data: JSON.stringify({
                    socket_id: '123.456',
                    activity_timeout: 30,
                }),
            }),
        });

        await vi.waitFor(() => {
            expect(states).toHaveBeenCalledWith('connected');
        });
        expect(fetchMock).toHaveBeenCalledWith('https://atlas.wyxos.com/api/extension/broadcasting/auth', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': 'test-api-token',
            },
            body: JSON.stringify({
                socket_id: '123.456',
                channel_name: 'private-extension-downloads.test-hash',
            }),
        });
        expect(socket.sentMessages[0]).toBe(JSON.stringify({
            event: 'pusher:subscribe',
            data: {
                channel: 'private-extension-downloads.test-hash',
                auth: 'atlas-key:test-signature',
            },
        }));

        client?.disconnect();
    });

    it('emits transfer events to subscribers', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                auth: 'atlas-key:test-signature',
            }),
        })) as unknown as WorkerFetch;
        const client = await connectWorkerReverb(
            baseConfig,
            FakeWebSocket as unknown as WorkerWebSocketCtor,
            fetchMock,
        );
        const socket = getLastSocket();
        const onEvent = vi.fn();

        client?.onEvent(onEvent);
        socket.emit('message', {
            data: JSON.stringify({
                event: 'DownloadTransferQueued',
                data: JSON.stringify({
                    transfer_id: 123,
                    file_id: 456,
                    status: 'queued',
                    percent: 0,
                }),
            }),
        });

        expect(onEvent).toHaveBeenCalledWith('DownloadTransferQueued', {
            transfer_id: 123,
            file_id: 456,
            status: 'queued',
            percent: 0,
        });

        client?.disconnect();
    });

    it('surfaces websocket errors through connection error callbacks', async () => {
        const fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({
                auth: 'atlas-key:test-signature',
            }),
        })) as unknown as WorkerFetch;
        const client = await connectWorkerReverb(
            baseConfig,
            FakeWebSocket as unknown as WorkerWebSocketCtor,
            fetchMock,
        );
        const socket = getLastSocket();
        const onError = vi.fn();

        client?.onConnectionError(onError);
        socket.emit('error', { message: 'document is not defined' });

        expect(onError).toHaveBeenCalledWith('document is not defined');
        expect(client?.getConnectionState()).toBe('failed');

        client?.disconnect();
    });

    it('returns null when config is missing required values', async () => {
        const client = await connectWorkerReverb(
            {
                ...baseConfig,
                key: '',
            },
            FakeWebSocket as unknown as WorkerWebSocketCtor,
            vi.fn() as unknown as WorkerFetch,
        );

        expect(client).toBeNull();
        expect(FakeWebSocket.instances.length).toBe(0);
    });
});
