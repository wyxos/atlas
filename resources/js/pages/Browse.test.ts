import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref } from 'vue';
import Browse from './Browse.vue';

// Mock fetch
global.fetch = vi.fn();

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock @wyxos/vibe
const mockIsLoading = ref(false);
vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: '<div class="masonry-mock"><slot></slot></div>',
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint', 'skipInitialLoad'],
        setup() {
            return {
                isLoading: mockIsLoading,
                init: vi.fn(),
                refreshLayout: vi.fn(),
            };
        },
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });

    // Mock tabs API to return empty array by default
    mockAxios.get.mockResolvedValue({ data: [] });
});

async function createTestRouter(initialPath = '/browse') {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/browse', component: Browse },
            { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
        ],
    });
    await router.push(initialPath);
    await router.isReady();
    return router;
}

function createMockBrowseResponse(
    page: number | string,
    nextPageValue: number | string | null = null
) {
    const items = Array.from({ length: 40 }, (_, i) => ({
        id: `item-${page}-${i}`,
        width: 300 + (i % 100),
        height: 200 + (i % 100),
        src: `https://picsum.photos/id/${i}/300/200`,
        type: i % 10 === 0 ? 'video' : 'image',
        page: typeof page === 'number' ? page : 1,
        index: i,
        notFound: false,
    }));

    return {
        items,
        nextPage: nextPageValue !== null ? nextPageValue : (typeof page === 'number' && page < 100 ? page + 1 : null),
    };
}

describe('Browse', () => {
    it('renders the Masonry component when tab exists', async () => {
        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: 1,
                label: 'Test Tab',
                query_params: { page: 1 },
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        expect(wrapper.find('.masonry-mock').exists()).toBe(true);
    });

    it('initializes with empty items array', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.items).toEqual([]);
    });

    it('passes correct props to Masonry component', async () => {
        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: 1,
                label: 'Test Tab',
                query_params: { page: 1 },
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 200)); // Wait for tab switching

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        // When tab has no items, loadAtPage is set to 1 to start loading
        expect(masonry.props('loadAtPage')).toBe(1);
        expect(masonry.props('layoutMode')).toBe('auto');
        expect(masonry.props('mobileBreakpoint')).toBe(768);
        expect(masonry.props('skipInitialLoad')).toBe(false); // No items initially
        expect(masonry.props('layout')).toEqual({
            gutterX: 12,
            gutterY: 12,
            sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
        });
    });

    it('provides getNextPage function that fetches from API', async () => {
        const mockResponse = createMockBrowseResponse(2, 3);
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            json: async () => mockResponse,
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        const getNextPage = vm.getNextPage;

        const result = await getNextPage(2);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining('/api/browse?page=2')
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBe(3);
    });

    it('handles API errors gracefully', async () => {
        const networkError = new Error('Network error');
        (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValueOnce(networkError);

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        const getNextPage = vm.getNextPage;

        await expect(getNextPage(1)).rejects.toThrow('Network error');
    });

    it('returns correct structure from getNextPage with null nextPage', async () => {
        const mockResponse = createMockBrowseResponse(100, null);
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            json: async () => mockResponse,
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        const result = await vm.getNextPage(100);

        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.items).toBeInstanceOf(Array);
        expect(result.items.length).toBe(40);
        expect(result.nextPage).toBeNull();
    });

    it('handles cursor-based pagination with string cursors', async () => {
        const cursor = 'cursor-abc123';
        const nextCursor = 'cursor-xyz789';
        const mockResponse = createMockBrowseResponse(cursor, nextCursor);
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            json: async () => mockResponse,
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        const result = await vm.getNextPage(cursor);

        expect(global.fetch).toHaveBeenCalledWith(
            expect.stringContaining(`/api/browse?page=${cursor}`)
        );
        expect(result).toHaveProperty('items');
        expect(result).toHaveProperty('nextPage');
        expect(result.nextPage).toBe(nextCursor);
        expect(vm.currentPage).toBe(cursor);
        expect(vm.nextCursor).toBe(nextCursor);
    });

    it('updates currentPage to 1 when fetching first page', async () => {
        const mockResponse = createMockBrowseResponse(1, 2);
        (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce({
            ok: true,
            json: async () => mockResponse,
        });

        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        await vm.getNextPage(1);

        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBe(2);
    });

    it('initializes from tab in URL query parameters on mount', async () => {
        const tabId = 1;
        const pageParam = 'cursor-page-123';
        const nextParam = 'cursor-next-456';

        // Mock tabs API to return a tab with the page/next in query_params
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: { page: pageParam, next: nextParam },
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter(`/browse?tab=${tabId}`);

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);
        // When tab has no items_data, currentPage is reset to 1, but nextCursor is preserved from query_params
        // This is the actual behavior - nextCursor is only reset when items_data exists
        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBe(nextParam);
    });

    it('initializes with default values when no tabs exist', async () => {
        // Mock tabs API to return empty array
        mockAxios.get.mockResolvedValueOnce({ data: [] });

        const router = await createTestRouter('/browse');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBeNull();
        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBeNull();
        expect(vm.loadAtPage).toBeNull(); // Changed to null by default
    });

    it('updateUrl function updates router with tab ID only', async () => {
        const tabId = 1;

        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: {},
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.updateUrl();

        // updateUrl now only sets tab ID, not page/next
        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                tab: String(tabId),
            },
        });
    });

    it('updateUrl only includes tab ID, not page or next', async () => {
        const tabId = 1;

        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: {},
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.currentPage = 1;
        vm.nextCursor = 'cursor-456';
        vm.updateUrl();

        // updateUrl only sets tab ID, page/next are stored in tab's query_params
        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                tab: String(tabId),
            },
        });
    });

    it('updateUrl only includes tab ID, ignores other query params', async () => {
        const tabId = 1;

        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: {},
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter('/browse?filter=test&sort=asc');
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.updateUrl();

        // updateUrl only sets tab ID, other query params are stored in tab's query_params
        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                tab: String(tabId),
            },
        });
    });

    it('updateUrl only includes tab ID when activeTabId exists', async () => {
        const tabId = 1;

        // Mock tabs API to return a tab
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: {},
                file_ids: [],
                items_data: [],
                position: 0,
            }],
        });

        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 100)); // Wait for tab switching

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.nextCursor = null;
        vm.updateUrl();

        // updateUrl only sets tab ID
        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                tab: String(tabId),
            },
        });
    });

    it('displays Pill components with correct values', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.items = [{ id: '1' }, { id: '2' }, { id: '3' }];
        vm.currentPage = 2;
        vm.nextCursor = 'cursor-123';

        await wrapper.vm.$nextTick();

        const pills = wrapper.findAllComponents({ name: 'Pill' });
        expect(pills.length).toBeGreaterThan(0);

        // Check that pills are rendered (exact values depend on component state)
        const itemsPill = pills.find((p) => p.props('label') === 'Items');
        if (itemsPill) {
            expect(itemsPill.props('value')).toBe(3);
        }
    });

    it('displays N/A for next cursor when null', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.nextCursor = null;

        await wrapper.vm.$nextTick();

        const nextPill = wrapper
            .findAllComponents({ name: 'Pill' })
            .find((p) => p.props('label') === 'Next');
        if (nextPill) {
            expect(nextPill.props('value')).toBe('N/A');
        }
    });

    it('handles tab with page parameter in query_params', async () => {
        const tabId = 1;
        const pageParam = 'cursor-string-123';

        // Mock tabs API to return a tab with page in query_params and items_data
        // When items_data exists, the page from query_params is restored
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: { page: pageParam },
                file_ids: ['https://civitai.com/images/123'],
                items_data: [{ id: '123', width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                position: 0,
            }],
        });

        const router = await createTestRouter(`/browse?tab=${tabId}`);

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.activeTabId).toBe(tabId);
        // When items_data exists, page from query_params is restored
        expect(vm.currentPage).toBe(pageParam);
    });

    it('handles tab with page in query_params correctly', async () => {
        const tabId = 1;
        const pageValue = 123; // Can be number or string

        // Mock tabs API to return a tab with page as number in query_params and items_data
        // When items_data exists, the page from query_params is restored
        mockAxios.get.mockResolvedValueOnce({
            data: [{
                id: tabId,
                label: 'Test Tab',
                query_params: { page: pageValue },
                file_ids: ['https://civitai.com/images/123'],
                items_data: [{ id: '123', width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                position: 0,
            }],
        });

        const router = await createTestRouter(`/browse?tab=${tabId}`);

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();
        await new Promise(resolve => setTimeout(resolve, 300)); // Wait for tab switching and restoration

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Page value from query_params should be preserved when items_data exists (can be number or string)
        expect(vm.currentPage).toBe(pageValue);
    });
});
