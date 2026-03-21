import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockConnectRuntimeReverb = vi.fn();

vi.mock('./reverb-runtime', () => ({
    connectRuntimeReverb: mockConnectRuntimeReverb,
}));

type CapturedReverbRuntime = {
    client: {
        disconnect: ReturnType<typeof vi.fn>;
    };
    emitConnectionError: (message: string) => void;
    emitEvent: (eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void;
    emitState: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void;
    response: {
        kind: 'connected';
        endpoint: string;
        client: {
            disconnect: ReturnType<typeof vi.fn>;
        };
    };
    unsubscribeError: ReturnType<typeof vi.fn>;
    unsubscribeEvent: ReturnType<typeof vi.fn>;
    unsubscribeState: ReturnType<typeof vi.fn>;
};

function createConnectedRuntime(): CapturedReverbRuntime {
    let eventHandler: ((eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void) | null = null;
    let connectionStateHandler:
        | ((state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void)
        | null = null;
    let connectionErrorHandler: ((message: string) => void) | null = null;
    const unsubscribeError = vi.fn();
    const unsubscribeEvent = vi.fn();
    const unsubscribeState = vi.fn();

    const client = {
        disconnect: vi.fn(),
        getLastConnectionError: vi.fn(() => null),
        onConnectionError: vi.fn((handler: (message: string) => void) => {
            connectionErrorHandler = handler;
            return { unsubscribe: unsubscribeError };
        }),
        onConnectionState: vi.fn((handler: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void) => {
            connectionStateHandler = handler;
            return { unsubscribe: unsubscribeState };
        }),
        onEvent: vi.fn((handler: (eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void) => {
            eventHandler = handler;
            return { unsubscribe: unsubscribeEvent };
        }),
    };

    return {
        client,
        emitConnectionError: (message: string) => {
            connectionErrorHandler?.(message);
        },
        emitEvent: (eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => {
            eventHandler?.(eventName, payload);
        },
        emitState: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => {
            connectionStateHandler?.(state);
        },
        response: {
            kind: 'connected',
            endpoint: 'wss://atlas.test/reverb',
            client,
        },
        unsubscribeError,
        unsubscribeEvent,
        unsubscribeState,
    };
}

async function mountComponent(storageAddListener = vi.fn(), storageRemoveListener = vi.fn()) {
    vi.stubGlobal('chrome', {
        storage: {
            onChanged: {
                addListener: storageAddListener,
                removeListener: storageRemoveListener,
            },
        },
    });

    const component = await import('./OptionsReverbFeed.vue');

    return mount(component.default, {
        global: {
            stubs: {
                Badge: {
                    template: '<span><slot /></span>',
                },
            },
        },
    });
}

describe('OptionsReverbFeed', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders a connected reverb session, logs events, and clears them', async () => {
        const runtime = createConnectedRuntime();
        mockConnectRuntimeReverb.mockResolvedValueOnce(runtime.response);
        const wrapper = await mountComponent();
        await flushPromises();
        runtime.emitState('connected');
        await flushPromises();

        expect(wrapper.text()).toContain('Direct Reverb Feed');
        expect(wrapper.text()).toContain('Connected');
        expect(wrapper.text()).toContain('wss://atlas.test/reverb');
        expect(wrapper.text()).toContain('0 events');

        runtime.emitEvent('DownloadTransferQueued', {
            file_id: 7,
            downloadTransferId: 41,
            status: 'queued',
            percent: 15,
        });
        await flushPromises();

        expect(wrapper.text()).toContain('1 events');
        expect(wrapper.text()).toContain('DownloadTransferQueued');
        expect(wrapper.text()).toContain('transfer=41');

        const clearButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Clear Events');
        expect(clearButton).toBeTruthy();

        await clearButton!.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('No Reverb events received yet.');
        expect(runtime.client.disconnect).not.toHaveBeenCalled();
    });

    it.each([
        [{ kind: 'setup_required' }, 'Unavailable', 'Requires API key first.', null],
        [{ kind: 'auth_failed' }, 'Unavailable', 'Cannot test Reverb until API auth succeeds.', null],
        [{ kind: 'offline' }, 'Disconnected', 'Unable to reach Atlas.', null],
        [{ kind: 'reverb_unavailable', endpoint: 'wss://atlas.test/reverb' }, 'Unavailable', 'Reverb is not configured on Atlas.', 'wss://atlas.test/reverb'],
        [{ kind: 'disconnected', endpoint: 'wss://atlas.test/reverb', detail: 'socket closed' }, 'Disconnected', 'socket closed', 'wss://atlas.test/reverb'],
    ])('renders the %j startup status', async (runtimeResponse, expectedLabel, expectedDetail, expectedEndpoint) => {
        mockConnectRuntimeReverb.mockResolvedValueOnce(runtimeResponse);

        const wrapper = await mountComponent();
        await flushPromises();

        expect(wrapper.text()).toContain(expectedLabel);
        expect(wrapper.text()).toContain(expectedDetail);
        if (expectedEndpoint === null) {
            expect(wrapper.text()).not.toContain('Reverb Endpoint:');
            return;
        }

        expect(wrapper.text()).toContain(expectedEndpoint);
    });

    it('reconnects from the button and tears down the active client before reconnecting', async () => {
        const runtime = createConnectedRuntime();
        mockConnectRuntimeReverb
            .mockResolvedValueOnce(runtime.response)
            .mockResolvedValueOnce({
                kind: 'disconnected',
                endpoint: 'wss://atlas.test/reverb',
                detail: 'socket closed',
            });

        const wrapper = await mountComponent();
        await flushPromises();
        runtime.emitState('connected');
        await flushPromises();

        const reconnectButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Reconnect Reverb');
        expect(reconnectButton).toBeTruthy();

        await reconnectButton!.trigger('click');
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(2);
        expect(runtime.unsubscribeEvent).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeError).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeState).toHaveBeenCalledTimes(1);
        expect(runtime.client.disconnect).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('socket closed');
    });

    it('refreshes when storage changes and reports disconnected connection errors', async () => {
        const runtime = createConnectedRuntime();
        let storageListener: ((changes: Record<string, unknown>) => void) | null = null;
        mockConnectRuntimeReverb
            .mockResolvedValueOnce(runtime.response)
            .mockResolvedValueOnce({
                kind: 'disconnected',
                endpoint: 'wss://atlas.test/reverb',
                detail: 'socket closed',
            });

        const wrapper = await mountComponent(
            vi.fn((listener: (changes: Record<string, unknown>) => void) => {
                storageListener = listener;
            }),
        );
        await flushPromises();

        runtime.emitState('failed');
        runtime.emitConnectionError('socket refused');
        await flushPromises();

        expect(wrapper.text()).toContain('Disconnected');
        expect(wrapper.text()).toContain('Reverb websocket error. socket refused');

        storageListener?.({
            apiToken: {
                oldValue: 'old',
                newValue: 'new',
            },
        });
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('socket closed');
    });

    it('ignores unrelated storage changes and only refreshes for atlas settings', async () => {
        const runtime = createConnectedRuntime();
        let storageListener: ((changes: Record<string, unknown>) => void) | null = null;
        mockConnectRuntimeReverb
            .mockResolvedValueOnce(runtime.response)
            .mockResolvedValueOnce({
                kind: 'disconnected',
                endpoint: 'wss://atlas.test/reverb',
                detail: 'socket closed',
            });

        const wrapper = await mountComponent(
            vi.fn((listener: (changes: Record<string, unknown>) => void) => {
                storageListener = listener;
            }),
        );
        await flushPromises();

        runtime.emitState('connected');
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(1);

        storageListener?.({
            unrelatedKey: {
                oldValue: 'old',
                newValue: 'new',
            },
        });
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(1);

        storageListener?.({
            apiToken: {
                oldValue: 'old',
                newValue: 'new',
            },
        });
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('socket closed');
    });

    it('removes the storage listener and disconnects the active monitor on unmount', async () => {
        const runtime = createConnectedRuntime();
        const storageAddListener = vi.fn();
        const storageRemoveListener = vi.fn();
        mockConnectRuntimeReverb.mockResolvedValueOnce(runtime.response);

        const wrapper = await mountComponent(storageAddListener, storageRemoveListener);
        await flushPromises();
        runtime.emitState('connected');
        await flushPromises();

        wrapper.unmount();

        expect(storageAddListener).toHaveBeenCalledTimes(1);
        expect(storageRemoveListener).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeEvent).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeError).toHaveBeenCalledTimes(1);
        expect(runtime.unsubscribeState).toHaveBeenCalledTimes(1);
        expect(runtime.client.disconnect).toHaveBeenCalledTimes(1);
    });
});
