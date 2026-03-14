import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import DownloadsQueue from './DownloadsQueue.vue';

const mockToast = {
    info: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    dismiss: vi.fn(),
};

vi.mock('vue-toastification', () => ({
    useToast: () => mockToast,
}));

beforeEach(() => {
    vi.clearAllMocks();

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

it('shows retry context when a download is queued for retry', async () => {
    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 12,
                    status: 'queued',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: null,
                    percent: 0,
                    error: 'Retry 1/3 scheduled in 30s: cURL error 28 timeout',
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

    expect(wrapper.text()).toContain('Retry 1/3 scheduled in 30s');
});

it('clears stale error when progress payload sends error as null', async () => {
    const listeners = new Map<string, (payload: unknown) => void>();
    window.Echo = {
        private: () => ({
            listen: (event: string, cb: (payload: unknown) => void) => {
                listeners.set(event, cb);
            },
        }),
        leave: () => {},
    } as typeof window.Echo;

    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 77,
                    status: 'failed',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: '2026-03-05T10:00:00.000000Z',
                    percent: 12,
                    error: 'Old failure should disappear',
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

    expect(wrapper.text()).toContain('Old failure should disappear');

    const onProgress = listeners.get('.DownloadTransferProgressUpdated');
    expect(onProgress).toBeTypeOf('function');

    onProgress?.({
        downloadTransferId: 77,
        status: 'completed',
        percent: 100,
        created_at: null,
        queued_at: null,
        started_at: null,
        finished_at: '2026-03-05T10:01:00.000000Z',
        failed_at: null,
        error: null,
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).not.toContain('Old failure should disappear');
});

it('removes rendered downloads when a removal broadcast arrives', async () => {
    const listeners = new Map<string, (payload: unknown) => void>();
    window.Echo = {
        private: () => ({
            listen: (event: string, cb: (payload: unknown) => void) => {
                listeners.set(event, cb);
            },
        }),
        leave: () => {},
    } as typeof window.Echo;

    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 88,
                    status: 'failed',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: '2026-03-05T10:00:00.000000Z',
                    percent: 12,
                    error: 'Removed by broadcast',
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

    expect(wrapper.text()).toContain('Removed by broadcast');

    const onRemoved = listeners.get('.DownloadTransfersRemoved');
    expect(onRemoved).toBeTypeOf('function');

    onRemoved?.({
        ids: [88],
    });
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).not.toContain('Removed by broadcast');
});
