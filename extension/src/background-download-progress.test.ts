import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockConnectBackgroundReverb = vi.fn();

vi.mock('./background-reverb-runtime', () => ({
    connectBackgroundReverb: mockConnectBackgroundReverb,
}));

type RuntimeMessageSender = {
    tab?: {
        id?: number;
    };
};

type DownloadProgressModule = typeof import('./background-download-progress');

type ConnectedRuntime = {
    client: {
        disconnect: ReturnType<typeof vi.fn>;
        getConnectionState: ReturnType<typeof vi.fn>;
        getLastConnectionError: ReturnType<typeof vi.fn>;
        onConnectionError: ReturnType<typeof vi.fn>;
        onConnectionState: ReturnType<typeof vi.fn>;
        onEvent: ReturnType<typeof vi.fn>;
    };
    emitEvent: (payload: Record<string, unknown>) => void;
    emitState: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void;
    unsubscribeEvent: ReturnType<typeof vi.fn>;
    unsubscribeState: ReturnType<typeof vi.fn>;
};

function dispatchRuntimeMessage(
    module: DownloadProgressModule,
    message: unknown,
    sender: RuntimeMessageSender = {},
): { handled: boolean; response: unknown } {
    let response: unknown;
    const handled = module.handleDownloadProgressRuntimeMessage(message, sender, (payload?: unknown) => {
        response = payload;
    });

    return { handled, response };
}

function createConnectedRuntime(lastConnectionError: string | null = null): ConnectedRuntime {
    let eventHandler: ((eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void) | null = null;
    let stateHandler:
        | ((state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void)
        | null = null;

    const unsubscribeEvent = vi.fn();
    const unsubscribeState = vi.fn();
    const client = {
        disconnect: vi.fn(),
        getConnectionState: vi.fn(() => 'connected'),
        getLastConnectionError: vi.fn(() => lastConnectionError),
        onConnectionError: vi.fn(),
        onConnectionState: vi.fn((handler: typeof stateHandler) => {
            stateHandler = handler;
            return { unsubscribe: unsubscribeState };
        }),
        onEvent: vi.fn((handler: typeof eventHandler) => {
            eventHandler = handler;
            return { unsubscribe: unsubscribeEvent };
        }),
    };

    mockConnectBackgroundReverb.mockResolvedValue({
        kind: 'connected',
        domain: 'https://atlas.test',
        endpoint: 'https://atlas.test:443',
        client,
    });

    return {
        client,
        emitEvent: (payload: Record<string, unknown>) => {
            eventHandler?.('DownloadTransferQueued', payload);
        },
        emitState: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => {
            stateHandler?.(state);
        },
        unsubscribeEvent,
        unsubscribeState,
    };
}

describe('background-download-progress', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
            },
            tabs: {
                sendMessage: vi.fn((_: number, __: unknown, callback?: () => void) => {
                    callback?.();
                }),
            },
        });
    });

    it('records debug events, clears debug state, and disconnects when the last subscriber leaves', async () => {
        const runtime = createConnectedRuntime();
        const module = await import('./background-download-progress');

        expect(
            dispatchRuntimeMessage(
                module,
                { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' },
                { tab: { id: 41 } },
            ),
        ).toEqual({
            handled: true,
            response: { ok: true },
        });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });

        runtime.emitEvent({
            file_id: 12,
            id: 55,
            original: 'https://cdn.example.com/video.mp4',
            status: 'queued',
            percent: 10,
        });

        const debugState = dispatchRuntimeMessage(
            module,
            { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' },
        );
        expect(debugState.handled).toBe(true);
        expect(debugState.response).toMatchObject({
            ok: true,
            snapshot: {
                subscriberTabCount: 1,
                connectionState: 'connected',
                recentEvents: [
                    {
                        id: 1,
                        event: {
                            event: 'DownloadTransferQueued',
                            fileId: 12,
                            transferId: 55,
                            sourceUrl: 'https://cdn.example.com/video.mp4',
                            status: 'queued',
                            percent: 10,
                        },
                    },
                ],
            },
        });

        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE' }),
        ).toEqual({
            handled: true,
            response: { ok: true },
        });
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                recentEvents: [],
            },
        });

        expect(
            dispatchRuntimeMessage(
                module,
                { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' },
                { tab: { id: 41 } },
            ),
        ).toEqual({
            handled: true,
            response: { ok: true },
        });
        expect(runtime.unsubscribeEvent).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeState).toHaveBeenCalledTimes(1);
        expect(runtime.client.disconnect).toHaveBeenCalledTimes(1);
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                subscriberTabCount: 0,
                connectionState: 'idle',
            },
        });
    });

    it('retries offline startup failures while subscribers remain active', async () => {
        vi.useFakeTimers();
        mockConnectBackgroundReverb.mockResolvedValue({ kind: 'offline' });

        const module = await import('./background-download-progress');
        expect(
            dispatchRuntimeMessage(
                module,
                { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' },
                { tab: { id: 7 } },
            ),
        ).toEqual({
            handled: true,
            response: { ok: true },
        });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                subscriberTabCount: 1,
                connectionState: 'offline',
            },
        });

        await vi.advanceTimersByTimeAsync(1500);
        expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(2);

        expect(
            dispatchRuntimeMessage(
                module,
                { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' },
                { tab: { id: 7 } },
            ),
        ).toEqual({
            handled: true,
            response: { ok: true },
        });
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                connectionState: 'idle',
            },
        });
    });

    it('tears down failed live connections and retries them on a timer', async () => {
        vi.useFakeTimers();
        const runtime = createConnectedRuntime('socket refused');
        mockConnectBackgroundReverb
            .mockResolvedValueOnce({
                kind: 'connected',
                domain: 'https://atlas.test',
                endpoint: 'https://atlas.test:443',
                client: runtime.client,
            })
            .mockResolvedValueOnce({ kind: 'setup_required' });

        const module = await import('./background-download-progress');
        dispatchRuntimeMessage(module, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }, { tab: { id: 9 } });

        await vi.waitFor(() => {
            expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(1);
        });

        runtime.emitState('failed');

        expect(runtime.unsubscribeEvent).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeState).toHaveBeenCalledTimes(1);
        expect(runtime.client.disconnect).toHaveBeenCalledTimes(1);
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                connectionState: 'failed',
                connectionDetail: 'socket refused',
            },
        });

        await vi.advanceTimersByTimeAsync(1500);
        expect(mockConnectBackgroundReverb).toHaveBeenCalledTimes(2);
        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }).response,
        ).toMatchObject({
            ok: true,
            snapshot: {
                connectionState: 'setup_required',
            },
        });
    });

    it('rejects subscriptions without a sender tab id', async () => {
        const module = await import('./background-download-progress');

        expect(
            dispatchRuntimeMessage(module, { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' }),
        ).toEqual({
            handled: true,
            response: { ok: false },
        });
        expect(mockConnectBackgroundReverb).not.toHaveBeenCalled();
    });
});
