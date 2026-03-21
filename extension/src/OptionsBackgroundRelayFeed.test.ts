import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function createSnapshot(
    connectionState: string,
    overrides: Partial<{
        connectionDetail: string | null;
        recentEvents: unknown[];
        subscriberTabCount: number;
    }> = {},
) {
    return {
        subscriberTabCount: overrides.subscriberTabCount ?? 0,
        connectionState,
        connectionDetail: overrides.connectionDetail ?? null,
        recentEvents: overrides.recentEvents ?? [],
    };
}

async function mountComponent(sendMessage: (message: unknown, callback: (response: unknown) => void) => void) {
    vi.stubGlobal('chrome', {
        runtime: {
            lastError: null,
            sendMessage: vi.fn(sendMessage),
        },
    });

    const component = await import('./OptionsBackgroundRelayFeed.vue');

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

describe('OptionsBackgroundRelayFeed', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders the background relay snapshot and clears logged events', async () => {
        const sendMessage = vi.fn((message: { type?: string }, callback: (response: unknown) => void) => {
            if (message.type === 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE') {
                callback({
                    ok: true,
                    snapshot: {
                        subscriberTabCount: 2,
                        connectionState: 'connected',
                        connectionDetail: null,
                        recentEvents: [
                            {
                                id: 1,
                                receivedAt: '2026-03-21T00:00:00Z',
                                event: {
                                    event: 'DownloadTransferQueued',
                                    transferId: 41,
                                    fileId: 7,
                                    status: 'queued',
                                    percent: 15,
                                    payload: {
                                        foo: 'bar',
                                    },
                                },
                            },
                        ],
                    },
                });
                return;
            }

            if (message.type === 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE') {
                callback({ ok: true });
                return;
            }

            callback(null);
        });

        const wrapper = await mountComponent(sendMessage);
        await flushPromises();

        expect(wrapper.text()).toContain('Background Relay');
        expect(wrapper.text()).toContain('Connected');
        expect(wrapper.text()).toContain('Subscribers:');
        expect(wrapper.text()).toContain('2');
        expect(wrapper.text()).toContain('1 relayed');
        expect(wrapper.text()).toContain('DownloadTransferQueued');
        expect(wrapper.text()).toContain('transfer=41');

        const clearButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Clear');
        expect(clearButton).toBeTruthy();

        await clearButton!.trigger('click');
        await flushPromises();

        expect(sendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE' },
            expect.any(Function),
        );
        expect(sendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' },
            expect.any(Function),
        );
    });

    it('shows an unavailable state when the background snapshot cannot be read', async () => {
        const wrapper = await mountComponent((_message, callback) => {
            callback(null);
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Unavailable');
        expect(wrapper.text()).toContain('Unable to read the background relay state.');
    });

    it.each([
        ['idle without subscribers', createSnapshot('idle'), 'Disconnected', 'No subscribed tabs yet.'],
        ['idle with subscribers', createSnapshot('idle', { subscriberTabCount: 2 }), 'Disconnected', 'Waiting for the background relay to reconnect.'],
        ['connecting', createSnapshot('connecting'), 'Checking', 'Background relay is connecting to Reverb.'],
        ['reconnecting', createSnapshot('reconnecting'), 'Checking', 'Background relay is reconnecting to Reverb.'],
        ['setup required', createSnapshot('setup_required'), 'Disconnected', 'Background relay cannot connect until the API key is configured.'],
        ['auth failed', createSnapshot('auth_failed'), 'Disconnected', 'Background relay could not authenticate against Atlas.'],
        ['offline', createSnapshot('offline'), 'Disconnected', 'Background relay could not reach Atlas.'],
        ['reverb unavailable', createSnapshot('reverb_unavailable'), 'Disconnected', 'Atlas reported that Reverb is unavailable.'],
        ['disconnected with detail', createSnapshot('disconnected', { connectionDetail: 'socket closed' }), 'Disconnected', 'socket closed'],
        ['failed with detail', createSnapshot('failed', { connectionDetail: 'worker crashed' }), 'Disconnected', 'worker crashed'],
    ])('renders the %s status detail', async (_label, snapshot, expectedBadge, expectedDetail) => {
        const wrapper = await mountComponent((_message, callback) => {
            callback({
                ok: true,
                snapshot,
            });
        });
        await flushPromises();

        expect(wrapper.text()).toContain(expectedBadge);
        expect(wrapper.text()).toContain(expectedDetail);
    });

    it('refreshes on demand, polls repeatedly, and stops polling on unmount', async () => {
        vi.useFakeTimers();

        const sendMessage = vi.fn((message: { type?: string }, callback: (response: unknown) => void) => {
            if (message.type !== 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE') {
                callback({ ok: true });
                return;
            }

            callback({
                ok: true,
                snapshot: createSnapshot('connected', {
                    subscriberTabCount: sendMessage.mock.calls
                        .filter(([payload]) => (payload as { type?: string }).type === 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE')
                        .length,
                }),
            });
        });

        const wrapper = await mountComponent(sendMessage);
        await flushPromises();

        expect(sendMessage).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Subscribers:');
        expect(wrapper.text()).toContain('1');

        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();
        expect(sendMessage).toHaveBeenCalledTimes(2);
        expect(wrapper.text()).toContain('2');

        const refreshButton = wrapper.findAll('button')
            .find((button) => button.text() === 'Refresh');
        expect(refreshButton).toBeTruthy();

        await refreshButton!.trigger('click');
        await flushPromises();
        expect(sendMessage).toHaveBeenCalledTimes(3);
        expect(wrapper.text()).toContain('3');

        wrapper.unmount();

        await vi.advanceTimersByTimeAsync(4_000);
        await flushPromises();
        expect(sendMessage).toHaveBeenCalledTimes(3);
    });

});
