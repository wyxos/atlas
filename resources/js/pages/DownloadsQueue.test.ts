import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import DownloadsQueue from './DownloadsQueue.vue';

beforeEach(() => {
    window.axios = {
        get: vi.fn().mockResolvedValue({ data: { items: [] } }),
        post: vi.fn().mockResolvedValue({ data: { items: [] } }),
        delete: vi.fn(),
    } as typeof window.axios;

    window.Echo = {
        private: () => ({ listen: () => {} }),
        leave: () => {},
    } as typeof window.Echo;
});

it('renders the downloads queue', async () => {
    const wrapper = mount(DownloadsQueue);

    await flushPromises();

    expect(wrapper.text()).toContain('Downloads Queue');
    expect(wrapper.text()).toContain('Manage queued downloads.');
});

it('disables pause and cancel when progress is complete', async () => {
    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 1,
                    status: 'downloading',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: null,
                    percent: 100,
                },
            ],
        },
    });

    const wrapper = mount(DownloadsQueue);
    await flushPromises();

    const container = wrapper.find('.flex-1.overflow-auto');
    Object.defineProperty(container.element, 'clientHeight', { value: 600, configurable: true });
    window.dispatchEvent(new Event('resize'));
    await wrapper.vm.$nextTick();

    const pauseButton = wrapper.find('button[aria-label="Pause download"]');
    const cancelButton = wrapper.find('button[aria-label="Cancel download"]');

    expect(pauseButton.attributes('disabled')).toBeDefined();
    expect(cancelButton.attributes('disabled')).toBeDefined();
});
