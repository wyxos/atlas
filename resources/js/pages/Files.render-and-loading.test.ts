/* eslint-disable @typescript-eslint/no-unused-vars */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import Files from './Files.vue';
import Oruga from '@oruga-ui/oruga-next';
import { index as filesIndex, destroy as filesDestroy } from '@/actions/App/Http/Controllers/FilesController';

interface File {
    id: number;
    source: string;
    filename: string;
    ext: string | null;
    size: number | null;
    mime_type: string | null;
    title: string | null;
    url: string | null;
    path: string | null;
    preview_url: string | null;
    downloaded: boolean;
    not_found: boolean;
    created_at: string;
    updated_at: string;
}

interface FilesComponentInstance {
    deletionHandler: {
        dialogOpen: boolean;
        itemToDelete: File | null;
        openDialog: (file: File) => void;
        closeDialog: () => void;
        delete: () => Promise<void>;
        deleteError: string | null;
        canRetryDelete: boolean;
        isDeleting: boolean;
    };
}

const filesIndexUrl = filesIndex.definition?.url ?? filesIndex.url();

// Mock axios
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
    },
}));


// Mock window.axios
const mockAxios = {
    get: vi.fn(),
    delete: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    // Suppress console.error output during tests to reduce noise
    vi.spyOn(console, 'error').mockImplementation(() => { });
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

async function createTestRouter(initialPath = '/files') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/files', component: Files },
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
        ],
    });
    await router.push(initialPath);
    await router.isReady();
    return router;
}

/**
 * Wait for the listing to finish loading by flushing promises and waiting for Vue updates.
 * Since axios is mocked, promises resolve immediately, so we just need to ensure
 * all promise chains complete and Vue has updated the DOM.
 */
async function waitForListingToLoad(wrapper: ReturnType<typeof mount>): Promise<void> {
    // Flush all pending promises multiple times to ensure promise chains complete
    await flushPromises();
    await wrapper.vm.$nextTick();
    await flushPromises();
    await wrapper.vm.$nextTick();
    // One more flush to catch any nested async operations
    await flushPromises();
    await wrapper.vm.$nextTick();
}

function createHarmonieResponse(items: File[], currentPage = 1, total?: number, perPage = 15) {
    const itemTotal = total ?? items.length;
    const lastPage = Math.ceil(itemTotal / perPage);
    const from = items.length > 0 ? (currentPage - 1) * perPage + 1 : null;
    const to = items.length > 0 ? from! + items.length - 1 : null;

    return {
        data: {
            listing: {
                items,
                total: itemTotal,
                perPage,
                current_page: currentPage,
                last_page: lastPage,
                from,
                to,
                showing: to ?? 0,
                nextPage: currentPage < lastPage ? currentPage + 1 : null,
            },
            links: {
                first: `${filesIndexUrl}?page=1`,
                last: `${filesIndexUrl}?page=${lastPage}`,
                prev: currentPage > 1 ? `${filesIndexUrl}?page=${currentPage - 1}` : null,
                next: currentPage < lastPage ? `${filesIndexUrl}?page=${currentPage + 1}` : null,
            },
            filters: [],
        },
    };
}

describe('Files', () => {
    it('renders the files title', async () => {
        mockAxios.get.mockResolvedValue(createHarmonieResponse([], 1, 0));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        expect(wrapper.text()).toContain('Files');
    });
    it('fetches and displays files', async () => {
        const mockFiles: File[] = [
            {
                id: 1,
                source: 'local',
                filename: 'test-image.jpg',
                ext: 'jpg',
                size: 1024000,
                mime_type: 'image/jpeg',
                title: 'Test Image',
                url: null,
                path: '/path/to/test-image.jpg',
                preview_url: null,
                downloaded: true,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'YouTube',
                filename: 'video.mp4',
                ext: 'mp4',
                size: 10485760,
                mime_type: 'video/mp4',
                title: 'Test Video',
                url: 'https://youtube.com/watch?v=123',
                path: null,
                preview_url: 'https://example.com/thumb.jpg',
                downloaded: false,
                not_found: false,
                created_at: '2024-01-02T00:00:00Z',
                updated_at: '2024-01-02T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 2));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        expect(mockAxios.get).toHaveBeenCalledWith(filesIndexUrl, {
            params: {
                page: 1,
                per_page: 15,
            },
        });

        const text = wrapper.text();
        expect(text).toContain('test-image.jpg');
        expect(text).toContain('video.mp4');
    });
    it('displays loading state', async () => {
        mockAxios.get.mockImplementation(() => new Promise(() => { })); // Never resolves

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Loading...');
    });
    it('displays error message on fetch failure', async () => {
        mockAxios.get.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler is working - should show customized message
        expect(wrapper.text()).toContain('Failed to load files');

         
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('Failed to load files. Please try again later.');
    });
    it('uses onLoadError handler for 403 permission errors', async () => {
        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.get.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Verify onLoadError handler customizes 403 error message
         
        const vm = wrapper.vm as any;
        expect(vm.error).toBe('You do not have permission to view files.');
        expect(wrapper.text()).toContain('You do not have permission to view files.');
    });
    it('formats dates with time', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T14:30:00Z',
                updated_at: '2024-01-01T14:30:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        const text = wrapper.text();

        // Check that formatted dates include time in 24-hour format
        const dateRegex = /\d{2}:\d{2}/;
        expect(text).toMatch(dateRegex);
    });
    it('formats file sizes correctly', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'small.jpg',
                ext: 'jpg',
                size: 1024, // 1 KB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/small.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'local',
                filename: 'medium.jpg',
                ext: 'jpg',
                size: 1048576, // 1 MB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/medium.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 3,
                source: 'local',
                filename: 'large.jpg',
                ext: 'jpg',
                size: 1073741824, // 1 GB
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/large.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 3));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        const text = wrapper.text();
        expect(text).toContain('KB');
        expect(text).toContain('MB');
        expect(text).toContain('GB');
    });
});

