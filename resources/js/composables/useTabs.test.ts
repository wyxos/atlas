import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useTabs } from './useTabs';

// Mock axios
const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    patch: vi.fn(),
};

vi.mock('axios', () => ({
    default: mockAxios,
}));

// Mock window.axios
Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });

    // Reset default mock implementation
    mockAxios.get.mockImplementation(() => {
        return Promise.resolve({ data: [] });
    });
});

describe('useTabs', () => {
    it('initializes with empty state', () => {
        const { tabs, activeTabId, isLoadingTabs } = useTabs();

        expect(tabs.value).toEqual([]);
        expect(activeTabId.value).toBeNull();
        expect(isLoadingTabs.value).toBe(false);
    });

    it('loads tabs from API without items (lazy loading)', async () => {
        const mockTabs = [
            {
                id: 1,
                label: 'Tab 1',
                query_params: { page: 1 },
                position: 0,
                is_active: false,
            },
            {
                id: 2,
                label: 'Tab 2',
                query_params: { page: 2 },
                position: 1,
                is_active: false,
            },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs, isLoadingTabs } = useTabs();

        await loadTabs();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/tabs');
        expect(tabs.value).toHaveLength(2);
        expect(tabs.value[0].id).toBe(1);
        expect(tabs.value[0].label).toBe('Tab 1');
        expect(tabs.value[0].itemsData).toEqual([]); // itemsData should be empty on initial load
        expect(tabs.value[1].id).toBe(2);
        expect(tabs.value[1].label).toBe('Tab 2');
        expect(tabs.value[1].itemsData).toEqual([]); // itemsData should be empty on initial load
        expect(isLoadingTabs.value).toBe(false);
    });

    it('loads tabs with source_type field', async () => {
        const mockTabs = [
            {
                id: 1,
                label: 'Online Tab',
                query_params: { page: 1 },
                position: 0,
                is_active: false,
                source_type: 'online',
            },
            {
                id: 2,
                label: 'Offline Tab',
                query_params: { page: 1 },
                position: 1,
                is_active: false,
                source_type: 'offline',
            },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs } = useTabs();

        await loadTabs();

        expect(tabs.value[0].sourceType).toBe('online');
        expect(tabs.value[1].sourceType).toBe('offline');
    });

    it('defaults sourceType to online when not provided', async () => {
        const mockTabs = [
            {
                id: 1,
                label: 'Tab 1',
                query_params: { page: 1 },
                position: 0,
                is_active: false,
            },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs } = useTabs();

        await loadTabs();

        expect(tabs.value[0].sourceType).toBe('online');
    });

    it('sorts tabs by position', async () => {
        const mockTabs = [
            { id: 3, label: 'Tab 3', query_params: {}, position: 2, is_active: false },
            { id: 1, label: 'Tab 1', query_params: {}, position: 0, is_active: false },
            { id: 2, label: 'Tab 2', query_params: {}, position: 1, is_active: false },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs } = useTabs();

        await loadTabs();

        expect(tabs.value[0].id).toBe(1);
        expect(tabs.value[1].id).toBe(2);
        expect(tabs.value[2].id).toBe(3);
    });

    it('creates a new tab', async () => {
        mockAxios.get.mockResolvedValueOnce({ data: [] });
        mockAxios.post.mockResolvedValueOnce({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });
        mockAxios.patch.mockResolvedValueOnce({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: true,
            },
        });

        const onTabSwitch = vi.fn();
        const { tabs, createTab, activeTabId } = useTabs(onTabSwitch);

        await tabs.value; // Wait for initial state
        const newTab = await createTab();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/tabs', {
            label: 'Browse 1',
            query_params: {},
            position: 0,
        });
        expect(mockAxios.patch).toHaveBeenCalledWith('/api/tabs/1/active');
        expect(newTab.id).toBe(1);
        expect(newTab.label).toBe('Browse 1');
        expect(tabs.value).toHaveLength(1);
        expect(activeTabId.value).toBe(1);
        expect(onTabSwitch).toHaveBeenCalledWith(1);
    });

    it('closes a tab and switches to remaining tab', async () => {
        const onTabSwitch = vi.fn();
        const { tabs, closeTab, activeTabId } = useTabs(onTabSwitch);

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
            { id: 2, label: 'Tab 2', queryParams: {}, itemsData: [], position: 1, isActive: false },
        ];
        activeTabId.value = 1; // Set active tab to the one we're closing

        mockAxios.delete.mockResolvedValueOnce({});
        mockAxios.patch.mockResolvedValueOnce({
            data: {
                id: 2,
                label: 'Tab 2',
                query_params: {},
                position: 1,
                is_active: true,
            },
        });

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/tabs/1');
        expect(mockAxios.patch).toHaveBeenCalledWith('/api/tabs/2/active');
        expect(tabs.value).toHaveLength(1);
        expect(tabs.value[0].id).toBe(2);
        expect(activeTabId.value).toBe(2); // Should switch to remaining tab
        expect(onTabSwitch).toHaveBeenCalledWith(2);
    });

    it('closes a tab and creates new one when no tabs remain', async () => {
        const onTabSwitch = vi.fn();
        mockAxios.get.mockResolvedValueOnce({ data: [] });
        mockAxios.post.mockResolvedValueOnce({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: false,
            },
        });
        mockAxios.patch.mockResolvedValueOnce({
            data: {
                id: 1,
                label: 'Browse 1',
                query_params: {},
                file_ids: [],
                position: 0,
                is_active: true,
            },
        });

        const { tabs, closeTab, activeTabId } = useTabs(onTabSwitch);

        // Set up single tab
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
        ];
        activeTabId.value = 1;

        mockAxios.delete.mockResolvedValueOnce({});

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/tabs/1');
        expect(mockAxios.patch).toHaveBeenCalledWith('/api/tabs/1/active');
        expect(tabs.value).toHaveLength(1); // New tab created
        expect(activeTabId.value).toBe(1); // New tab ID
        expect(onTabSwitch).toHaveBeenCalledWith(1); // Should switch to new tab
    });

    it('closes a non-active tab without switching', async () => {
        const onTabSwitch = vi.fn();
        const { tabs, closeTab, activeTabId } = useTabs(onTabSwitch);

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
            { id: 2, label: 'Tab 2', queryParams: {}, itemsData: [], position: 1, isActive: false },
        ];
        activeTabId.value = 2; // Active tab is different from the one we're closing

        mockAxios.delete.mockResolvedValueOnce({});

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/tabs/1');
        expect(tabs.value).toHaveLength(1);
        expect(tabs.value[0].id).toBe(2);
        expect(activeTabId.value).toBe(2); // Should remain the same
        expect(onTabSwitch).not.toHaveBeenCalled(); // Should not switch
    });

    it('gets active tab', () => {
        const { tabs, activeTabId, getActiveTab } = useTabs();

        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
            { id: 2, label: 'Tab 2', queryParams: {}, itemsData: [], position: 1, isActive: false },
        ];
        activeTabId.value = 2;

        const activeTab = getActiveTab();

        expect(activeTab).toBeDefined();
        expect(activeTab?.id).toBe(2);
        expect(activeTab?.label).toBe('Tab 2');
    });

    it('returns undefined when no active tab', () => {
        const { getActiveTab } = useTabs();

        const activeTab = getActiveTab();

        expect(activeTab).toBeUndefined();
    });

    it('updates active tab', async () => {
        const { tabs, activeTabId, updateActiveTab } = useTabs();

        // Set up tabs manually for this test (simulating loaded state)
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
        ];
        activeTabId.value = 1;

        mockAxios.put.mockResolvedValueOnce({});

        const itemsData = [
            { id: 1, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false },
        ];
        // QueryParams are preserved from existing tab state (not updated by frontend)
        const existingQueryParams = tabs.value.find(t => t.id === 1)?.queryParams || {};

        updateActiveTab(itemsData);

        const activeTab = tabs.value.find(t => t.id === 1);
        expect(activeTab).toBeDefined();
        expect(activeTab?.itemsData).toEqual(itemsData);
        // QueryParams should be preserved (not updated by frontend)
        expect(activeTab?.queryParams).toEqual(existingQueryParams);

        // Wait for debounce
        await new Promise(resolve => setTimeout(resolve, 600));

        // QueryParams are managed by the backend (Browser.php), not sent from frontend
        expect(mockAxios.put).toHaveBeenCalledWith('/api/tabs/1', {
            label: 'Tab 1',
            position: 0,
            // query_params are not sent - backend manages them
        });
    });

    it('loads items for a specific tab', async () => {
        const { tabs, loadTabItems } = useTabs();

        // Set up tabs manually
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
        ];

        const mockItemsData = [
            { id: 1, width: 100, height: 100, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 200, height: 200, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
        ];

        // Clear previous mocks and set up specific mock for items endpoint
        mockAxios.get.mockReset();
        mockAxios.get.mockImplementation((url: string) => {
            if (url === '/api/tabs/1/items' || url.includes('/api/tabs/1/items')) {
                return Promise.resolve({
                    data: {
                        items: mockItemsData,
                    },
                });
            }
            return Promise.resolve({ data: [] });
        });

        const result = await loadTabItems(1);

        expect(mockAxios.get).toHaveBeenCalledWith('/api/tabs/1/items');
        expect(result).toEqual(mockItemsData);

        const tab = tabs.value.find(t => t.id === 1);
        expect(tab?.itemsData).toEqual(mockItemsData);
    });

    it('handles load tab items error gracefully', async () => {
        const { tabs, loadTabItems } = useTabs();

        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
        ];

        const error = new Error('Network error');
        // Clear previous mocks and set up specific mock for items endpoint to reject
        mockAxios.get.mockReset();
        mockAxios.get.mockImplementation((url: string) => {
            if (url === '/api/tabs/1/items' || url.includes('/api/tabs/1/items')) {
                return Promise.reject(error);
            }
            return Promise.resolve({ data: [] });
        });

        await expect(loadTabItems(1)).rejects.toThrow('Network error');

        // Tab should remain unchanged on error
        const tab = tabs.value.find(t => t.id === 1);
        expect(tab?.itemsData).toEqual([]);
    });

    it('handles load tabs error gracefully', async () => {
        const error = new Error('Network error');
        // Clear any existing mocks and set up error mock
        mockAxios.get.mockReset();
        mockAxios.get.mockRejectedValue(error);

        const { loadTabs, isLoadingTabs, tabs } = useTabs();

        await expect(loadTabs()).rejects.toThrow('Network error');
        expect(isLoadingTabs.value).toBe(false);
        expect(tabs.value).toEqual([]); // Should remain empty on error
    });

    it('handles create tab error gracefully', async () => {
        mockAxios.get.mockResolvedValueOnce({ data: [] });
        const error = new Error('Network error');
        mockAxios.post.mockRejectedValueOnce(error);

        const { createTab } = useTabs();

        await expect(createTab()).rejects.toThrow('Network error');
    });

    it('handles close tab error gracefully', async () => {
        const { tabs, closeTab } = useTabs();

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, itemsData: [], position: 0, isActive: false },
        ];

        const error = new Error('Network error');
        mockAxios.delete.mockRejectedValueOnce(error);

        await expect(closeTab(1)).rejects.toThrow('Network error');
        // Tab should still exist since deletion failed
        expect(tabs.value).toHaveLength(1);
    });
});

