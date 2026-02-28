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
    it('opens delete dialog when delete button is clicked', async () => {
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
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
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

        // Verify dialog is initially closed
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        expect(vm.deletionHandler.dialogOpen).toBe(false);
        expect(vm.deletionHandler.itemToDelete).toBe(null);

        // Open delete dialog using component method
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        // Check that dialog state is updated
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.itemToDelete).toEqual(mockFiles[0]);
    });
    it('cancels deletion when cancel button is clicked', async () => {
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
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
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

        // Open delete dialog using component method
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        // Cancel deletion using component method
        vm.deletionHandler.closeDialog();
        await wrapper.vm.$nextTick();

        // Verify delete was not called
        expect(mockAxios.delete).not.toHaveBeenCalled();
    });
    it('successfully deletes a file', async () => {
        const mockFiles = [
            {
                id: 1,
                source: 'local',
                filename: 'test1.jpg',
                ext: 'jpg',
                size: 1024,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test1.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
            {
                id: 2,
                source: 'local',
                filename: 'test2.jpg',
                ext: 'jpg',
                size: 2048,
                mime_type: 'image/jpeg',
                title: null,
                url: null,
                path: '/path/to/test2.jpg',
                preview_url: null,
                downloaded: false,
                not_found: false,
                created_at: '2024-01-02T00:00:00Z',
                updated_at: '2024-01-02T00:00:00Z',
            },
        ];

        const remainingFiles = [mockFiles[1]];

        mockAxios.get
            .mockResolvedValueOnce(createHarmonieResponse(mockFiles, 1, 2))
            .mockResolvedValueOnce(createHarmonieResponse(remainingFiles, 1, 1));

        mockAxios.delete.mockResolvedValue({});

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await waitForListingToLoad(wrapper);

        // Verify delete was called
        expect(mockAxios.delete).toHaveBeenCalledWith(filesDestroy.url({ file: 1 }));

        // Verify files list was refreshed
        expect(mockAxios.get).toHaveBeenCalledTimes(2);
    });
    it('displays error message when deletion fails', async () => {
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
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        mockAxios.delete.mockRejectedValue(new Error('Network error'));

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('Failed to delete file. Please try again later.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });
    it('displays permission error when deletion is forbidden', async () => {
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
                created_at: '2024-01-01T00:00:00Z',
                updated_at: '2024-01-01T00:00:00Z',
            },
        ];

        mockAxios.get.mockResolvedValue(createHarmonieResponse(mockFiles, 1, 1));

        const forbiddenError = new Error('Forbidden');
        (forbiddenError as { response?: { status?: number } }).response = { status: 403 };
        mockAxios.delete.mockRejectedValue(forbiddenError);

        const router = await createTestRouter();
        const wrapper = mount(Files, {
            global: {
                plugins: [router, Oruga],
            },
        });

        await waitForListingToLoad(wrapper);

        // Open delete dialog and confirm deletion using component methods
        const vm = wrapper.vm as unknown as FilesComponentInstance;
        vm.deletionHandler.openDialog(mockFiles[0]);
        await wrapper.vm.$nextTick();

        await vm.deletionHandler.delete();
        await flushPromises();
        await wrapper.vm.$nextTick();

        // Verify permission error state is set on the dialog
        expect(vm.deletionHandler.dialogOpen).toBe(true);
        expect(vm.deletionHandler.deleteError).toBe('You do not have permission to delete files.');
        expect(vm.deletionHandler.canRetryDelete).toBe(false);
    });
});
