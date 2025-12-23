import { describe, it, expect, vi, beforeEach } from 'vitest';
import { ref, computed } from 'vue';
import { useBrowseService } from './useBrowseService';
import type { UseBrowseServiceOptions } from './useBrowseService';

// Mock axios
const mockAxios = {
    get: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

// Mock browseIndex and browseServices actions
vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
    index: {
        url: vi.fn((params: any) => {
            const query = new URLSearchParams(params?.query || {}).toString();
            return `/api/browse?${query}`;
        }),
    },
    services: {
        url: vi.fn(() => '/api/browse/services'),
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
});

describe('useBrowseService', () => {
    it('initializes with empty state', () => {
        const { availableServices } = useBrowseService();

        expect(availableServices.value).toEqual([]);
    });

    it('fetches available services from API', async () => {
        const mockServices = [
            { key: 'civit-ai-images', label: 'CivitAI Images' },
            { key: 'wallhaven', label: 'Wallhaven' },
        ];

        mockAxios.get.mockResolvedValue({
            data: {
                items: [],
                services: mockServices,
            },
        });

        const { fetchServices, availableServices } = useBrowseService();

        await fetchServices();

        expect(mockAxios.get).toHaveBeenCalled();
        expect(availableServices.value).toEqual(mockServices);
    });

    // Note: Fallback behavior was removed in commit dfc0cb92 for better error transparency
    // Tests removed as they test obsolete behavior

    it('returns empty result when no service is selected', async () => {
        const options: UseBrowseServiceOptions = {
            hasServiceSelected: computed(() => false),
            isInitializing: ref(false),
            items: ref([]),
            nextCursor: ref(null),
            currentPage: ref(null),
            currentTabService: computed(() => null),
            activeTabId: ref(null),
            getActiveTab: () => undefined,
            updateActiveTab: vi.fn(),
        };

        const { getNextPage } = useBrowseService(options);

        const result = await getNextPage(1);

        expect(result.items).toEqual([]);
        expect(result.nextPage).toBeNull();
        expect(mockAxios.get).not.toHaveBeenCalled();
    });

    it('returns empty result when tab is initializing', async () => {
        const options: UseBrowseServiceOptions = {
            hasServiceSelected: computed(() => true),
            isInitializing: ref(true),
            items: ref([]),
            nextCursor: ref('cursor-123'),
            currentPage: ref(null),
            currentTabService: computed(() => 'civit-ai-images'),
            activeTabId: ref(1),
            getActiveTab: () => undefined,
            updateActiveTab: vi.fn(),
        };

        const { getNextPage } = useBrowseService(options);

        const result = await getNextPage(1);

        expect(result.items).toEqual([]);
        expect(result.nextPage).toBe('cursor-123');
        expect(mockAxios.get).not.toHaveBeenCalled();
    });

    it('gets current service from tab query params', () => {
        const { getCurrentService } = useBrowseService();

        expect(getCurrentService({ service: 'civit-ai-images' })).toBe('civit-ai-images');
        expect(getCurrentService({})).toBeNull();
        expect(getCurrentService()).toBeNull();
    });

    it('includes source parameter in offline mode', async () => {
        const mockResponse = {
            data: {
                items: [],
                nextPage: null,
            },
        };

        mockAxios.get.mockResolvedValueOnce(mockResponse);

        const options: UseBrowseServiceOptions = {
            hasServiceSelected: computed(() => true),
            isInitializing: ref(false),
            items: ref([]),
            nextCursor: ref(null),
            currentPage: ref(null),
            currentTabService: computed(() => null),
            activeTabId: ref(1),
            getActiveTab: () => ({
                id: 1,
                sourceType: 'offline',
                queryParams: { source: 'CivitAI', limit: 20 },
                itemsData: [],
            } as any),
            updateActiveTab: vi.fn(),
        };

        const { getNextPage } = useBrowseService(options);

        await getNextPage(1);

        expect(mockAxios.get).toHaveBeenCalled();
        const callUrl = mockAxios.get.mock.calls[0][0];
        expect(callUrl).toContain('source=CivitAI');
        expect(callUrl).toContain('limit=20');
    });

    it('includes limit parameter with default value when not set', async () => {
        const mockResponse = {
            data: {
                items: [],
                nextPage: null,
            },
        };

        mockAxios.get.mockResolvedValueOnce(mockResponse);

        const options: UseBrowseServiceOptions = {
            hasServiceSelected: computed(() => true),
            isInitializing: ref(false),
            items: ref([]),
            nextCursor: ref(null),
            currentPage: ref(null),
            currentTabService: computed(() => 'civit-ai-images'),
            activeTabId: ref(1),
            getActiveTab: () => ({
                id: 1,
                sourceType: 'online',
                queryParams: {},
                itemsData: [],
            } as any),
            updateActiveTab: vi.fn(),
        };

        const { getNextPage } = useBrowseService(options);

        await getNextPage(1);

        expect(mockAxios.get).toHaveBeenCalled();
        const callUrl = mockAxios.get.mock.calls[0][0];
        expect(callUrl).toContain('limit=20');
    });

    it('allows getNextPage in offline mode without service selected', async () => {
        const mockResponse = {
            data: {
                items: [],
                nextPage: null,
            },
        };

        mockAxios.get.mockResolvedValueOnce(mockResponse);

        // In offline mode, hasServiceSelected can be false but we still allow the call
        // if source is selected in queryParams
        const options: UseBrowseServiceOptions = {
            hasServiceSelected: computed(() => false), // Service not selected, but source is
            isInitializing: ref(false),
            items: ref([]),
            nextCursor: ref(null),
            currentPage: ref(null),
            currentTabService: computed(() => null),
            activeTabId: ref(1),
            getActiveTab: () => ({
                id: 1,
                sourceType: 'offline',
                queryParams: { source: 'all', limit: 20 },
                itemsData: [],
            } as any),
            updateActiveTab: vi.fn(),
        };

        const { getNextPage } = useBrowseService(options);

        const result = await getNextPage(1);

        // The call should be made because we're in offline mode and source is set
        expect(mockAxios.get).toHaveBeenCalled();
        expect(result.items).toEqual([]);
    });
});

