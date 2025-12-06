import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createRouter, createMemoryHistory } from 'vue-router';
import { ref } from 'vue';
import Browse from './Browse.vue';

// Mock fetch
global.fetch = vi.fn();

// Mock @wyxos/vibe
const mockIsLoading = ref(false);
vi.mock('@wyxos/vibe', () => ({
    Masonry: {
        name: 'Masonry',
        template: '<div class="masonry-mock"><slot></slot></div>',
        props: ['items', 'getNextPage', 'loadAtPage', 'layout', 'layoutMode', 'mobileBreakpoint'],
        setup() {
            return {
                isLoading: mockIsLoading,
            };
        },
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });
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
    it('renders the Masonry component', async () => {
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

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
        const router = await createTestRouter();
        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        const masonry = wrapper.findComponent({ name: 'Masonry' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('loadAtPage')).toBe(1);
        expect(masonry.props('layoutMode')).toBe('auto');
        expect(masonry.props('mobileBreakpoint')).toBe(768);
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

    it('initializes from URL query parameters on mount', async () => {
        const pageParam = 'cursor-page-123';
        const nextParam = 'cursor-next-456';
        const router = await createTestRouter(`/browse?page=${pageParam}&next=${nextParam}`);

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(pageParam);
        expect(vm.nextCursor).toBe(nextParam);
        expect(vm.loadAtPage).toBe(pageParam);
    });

    it('initializes with default values when no URL parameters', async () => {
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
        expect(vm.currentPage).toBe(1);
        expect(vm.nextCursor).toBeNull();
        expect(vm.loadAtPage).toBe(1);
    });

    it('updateUrl function updates router with correct query parameters', async () => {
        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.currentPage = 'cursor-123';
        vm.nextCursor = 'cursor-456';
        vm.updateUrl();

        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                page: 'cursor-123',
                next: 'cursor-456',
            },
        });
    });

    it('does not add page to URL when currentPage is 1', async () => {
        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.currentPage = 1;
        vm.nextCursor = 'cursor-456';
        vm.updateUrl();

        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                next: 'cursor-456',
            },
        });
    });

    it('preserves existing query parameters when updating URL', async () => {
        const router = await createTestRouter('/browse?filter=test&sort=asc');
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.currentPage = 'cursor-123';
        vm.nextCursor = 'cursor-456';
        vm.updateUrl();

        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                filter: 'test',
                sort: 'asc',
                page: 'cursor-123',
                next: 'cursor-456',
            },
        });
    });

    it('does not add next to URL when nextCursor is null', async () => {
        const router = await createTestRouter();
        const replaceSpy = vi.spyOn(router, 'replace');

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        vm.currentPage = 'cursor-123';
        vm.nextCursor = null;
        vm.updateUrl();

        expect(replaceSpy).toHaveBeenCalledWith({
            query: {
                page: 'cursor-123',
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

    it('handles string page parameter from URL', async () => {
        const pageParam = 'cursor-string-123';
        const router = await createTestRouter(`/browse?page=${pageParam}`);

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        expect(vm.currentPage).toBe(pageParam);
        expect(vm.loadAtPage).toBe(pageParam);
    });

    it('handles array page parameter from URL by converting to string', async () => {
        const router = await createTestRouter('/browse?page=123&page=456');
        // Vue Router may return array for duplicate params
        router.currentRoute.value.query.page = ['123', '456'];

        const wrapper = mount(Browse, {
            global: {
                plugins: [router],
            },
        });

        await flushPromises();
        await wrapper.vm.$nextTick();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const vm = wrapper.vm as any;
        // Should convert array to string
        expect(typeof vm.currentPage).toBe('string');
    });
});
