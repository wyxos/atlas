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
