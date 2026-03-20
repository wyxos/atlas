import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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
};

function createConnectedRuntime(): CapturedReverbRuntime {
    let eventHandler: ((eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void) | null = null;
    let connectionStateHandler:
        | ((state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void)
        | null = null;
    let connectionErrorHandler: ((message: string) => void) | null = null;

    const client = {
        disconnect: vi.fn(),
        getLastConnectionError: vi.fn(() => null),
        onConnectionError: vi.fn((handler: (message: string) => void) => {
            connectionErrorHandler = handler;
            return { unsubscribe: vi.fn() };
        }),
        onConnectionState: vi.fn((handler: (state: 'connected' | 'connecting' | 'reconnecting' | 'disconnected' | 'failed') => void) => {
            connectionStateHandler = handler;
            return { unsubscribe: vi.fn() };
        }),
        onEvent: vi.fn((handler: (eventName: 'DownloadTransferQueued', payload: Record<string, unknown>) => void) => {
            eventHandler = handler;
            return { unsubscribe: vi.fn() };
        }),
    };

    mockConnectRuntimeReverb.mockResolvedValue({
        kind: 'connected',
        endpoint: 'wss://atlas.test/reverb',
        client,
    });

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

    it('renders a connected reverb session, logs events, and clears them', async () => {
        const runtime = createConnectedRuntime();
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

    it('refreshes when storage changes and reports disconnected connection errors', async () => {
        const runtime = createConnectedRuntime();
        let storageListener: (() => void) | null = null;

        const wrapper = await mountComponent(
            vi.fn((listener: () => void) => {
                storageListener = listener;
            }),
        );
        await flushPromises();

        runtime.emitState('failed');
        runtime.emitConnectionError('socket refused');
        await flushPromises();

        expect(wrapper.text()).toContain('Disconnected');
        expect(wrapper.text()).toContain('Reverb websocket error. socket refused');

        storageListener?.();
        await flushPromises();

        expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(2);
    });
});
