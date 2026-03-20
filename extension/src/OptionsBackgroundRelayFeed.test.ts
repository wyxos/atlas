import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

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
    });

    it('shows an unavailable state when the background snapshot cannot be read', async () => {
        const wrapper = await mountComponent((_message, callback) => {
            callback(null);
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Unavailable');
        expect(wrapper.text()).toContain('Unable to read the background relay state.');
    });
});
