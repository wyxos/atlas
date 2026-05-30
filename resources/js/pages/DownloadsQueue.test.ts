import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import DownloadsQueue from './DownloadsQueue.vue';
import type { DownloadQueueIndexResponse, DownloadQueueItem } from '@/types/downloadQueue';

function createDeferred<T>() {
    let resolve: ((value: T | PromiseLike<T>) => void) | null = null;
    const promise = new Promise<T>((innerResolve) => {
        resolve = innerResolve;
    });

    return {
        promise,
        resolve: (value: T) => {
            resolve?.(value);
        },
    };
}

function downloadQueueItem(overrides: Partial<DownloadQueueItem> & Pick<DownloadQueueItem, 'id'>): DownloadQueueItem {
    return {
        status: 'queued',
        created_at: null,
        queued_at: null,
        started_at: null,
        finished_at: null,
        failed_at: null,
        percent: 0,
        error: null,
        ...overrides,
    };
}

const mockToast = {
    info: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    dismiss: vi.fn(),
};

vi.mock('@/components/ui/toast/use-toast', () => ({
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

it('loads downloads with cursor pagination before rendering rows', async () => {
    const secondPage = createDeferred<{ data: DownloadQueueIndexResponse }>();
    window.axios.get = vi.fn((_url: string, config?: { params?: { after_id?: number } }) => {
        if (config?.params?.after_id === 0) {
            return Promise.resolve({
                data: {
                    items: [
                        downloadQueueItem({
                            id: 1,
                            error: 'First page queued',
                        }),
                    ],
                    cursor: {
                        after_id: 0,
                        next_after_id: 1,
                        has_more: true,
                        max_id: 2,
                    },
                    pagination: {
                        per_page: 100,
                        total: 2,
                        total_pages: 2,
                    },
                } satisfies DownloadQueueIndexResponse,
            });
        }

        if (config?.params?.after_id === 1) {
            return secondPage.promise;
        }

        return Promise.reject(new Error(`Unexpected cursor: ${String(config?.params?.after_id)}`));
    });

    const wrapper = mount(DownloadsQueue);
    await flushPromises();

    expect(window.axios.get).toHaveBeenNthCalledWith(1, '/api/download-transfers', {
        params: {
            after_id: 0,
            per_page: 100,
        },
    });
    expect(window.axios.get).toHaveBeenNthCalledWith(2, '/api/download-transfers', {
        params: {
            after_id: 1,
            max_id: 2,
            per_page: 100,
        },
    });
    expect(wrapper.text()).toContain('Pages: 1 / 2');
    expect(wrapper.text()).toContain('Downloads loaded: 1 / 2');
    expect(wrapper.text()).toContain('Loading downloads...');
    expect(wrapper.text()).not.toContain('First page queued');

    secondPage.resolve({
        data: {
            items: [
                downloadQueueItem({
                    id: 2,
                    error: 'Second page queued',
                }),
            ],
            cursor: {
                after_id: 1,
                next_after_id: null,
                has_more: false,
                max_id: 2,
            },
            pagination: {
                per_page: 100,
                total: null,
                total_pages: null,
            },
        } satisfies DownloadQueueIndexResponse,
    });
    await flushPromises();

    expect(wrapper.find('[data-test="downloads-progress-panel"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('First page queued');
    expect(wrapper.text()).toContain('Second page queued');
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

it('shows a yt-dlp badge for transfers that use yt-dlp', async () => {
    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 13,
                    status: 'queued',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: null,
                    percent: 0,
                    error: null,
                    download_via: 'yt-dlp',
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

    expect(wrapper.text()).toContain('YT-DLP');
});

it('filters downloads by fuzzy search text', async () => {
    window.axios.get = vi.fn().mockResolvedValue({
        data: {
            items: [
                {
                    id: 101,
                    status: 'queued',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: null,
                    percent: 0,
                    error: null,
                    search_text: 'downloads/ab/cd/civitai-preview.png',
                },
                {
                    id: 202,
                    status: 'queued',
                    created_at: null,
                    queued_at: null,
                    started_at: null,
                    finished_at: null,
                    failed_at: null,
                    percent: 0,
                    error: null,
                    search_text: 'downloads/ef/gh/wallhaven-landscape.png',
                },
            ],
        },
    });

    const wrapper = mount(DownloadsQueue);
    await flushPromises();

    expect(wrapper.text()).toContain('Filtered files: 2');

    await wrapper.find('input[aria-label="Search downloads"]').setValue('cvta');
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('Filtered files: 1');
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
