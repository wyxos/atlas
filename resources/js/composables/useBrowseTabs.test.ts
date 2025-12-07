import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useBrowseTabs } from './useBrowseTabs';

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

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => { });

    // Reset default mock implementation
    mockAxios.get.mockImplementation((url: string) => {
        return Promise.resolve({ data: [] });
    });
});

describe('useBrowseTabs', () => {
    it('initializes with empty state', () => {
        const { tabs, activeTabId, isLoadingTabs } = useBrowseTabs();

        expect(tabs.value).toEqual([]);
        expect(activeTabId.value).toBeNull();
        expect(isLoadingTabs.value).toBe(false);
    });

    it('loads tabs from API', async () => {
        const mockTabs = [
            {
                id: 1,
                label: 'Tab 1',
                query_params: { page: 1 },
                file_ids: [],
                items_data: [],
                position: 0,
            },
            {
                id: 2,
                label: 'Tab 2',
                query_params: { page: 2 },
                file_ids: [1, 2],
                items_data: [{ id: 1, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false }],
                position: 1,
            },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs, isLoadingTabs } = useBrowseTabs();

        await loadTabs();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/browse-tabs');
        expect(tabs.value).toHaveLength(2);
        expect(tabs.value[0].id).toBe(1);
        expect(tabs.value[0].label).toBe('Tab 1');
        expect(tabs.value[1].id).toBe(2);
        expect(tabs.value[1].label).toBe('Tab 2');
        expect(isLoadingTabs.value).toBe(false);
    });

    it('sorts tabs by position', async () => {
        const mockTabs = [
            { id: 3, label: 'Tab 3', query_params: {}, file_ids: [], items_data: [], position: 2 },
            { id: 1, label: 'Tab 1', query_params: {}, file_ids: [], items_data: [], position: 0 },
            { id: 2, label: 'Tab 2', query_params: {}, file_ids: [], items_data: [], position: 1 },
        ];

        mockAxios.get.mockResolvedValueOnce({ data: mockTabs });

        const { tabs, loadTabs } = useBrowseTabs();

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
                query_params: { page: 1 },
                file_ids: [],
                position: 0,
            },
        });

        const onTabSwitch = vi.fn();
        const { tabs, createTab, activeTabId } = useBrowseTabs(onTabSwitch);

        await tabs.value; // Wait for initial state
        const newTab = await createTab();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/browse-tabs', {
            label: 'Browse 1',
            query_params: { page: 1 },
            file_ids: [],
            position: 0,
        });
        expect(newTab.id).toBe(1);
        expect(newTab.label).toBe('Browse 1');
        expect(tabs.value).toHaveLength(1);
        expect(activeTabId.value).toBe(1);
        expect(onTabSwitch).toHaveBeenCalledWith(1);
    });

    it('closes a tab and switches to remaining tab', async () => {
        const onTabSwitch = vi.fn();
        const { tabs, closeTab, activeTabId } = useBrowseTabs(onTabSwitch);

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
            { id: 2, label: 'Tab 2', queryParams: {}, fileIds: [], itemsData: [], position: 1 },
        ];
        activeTabId.value = 1; // Set active tab to the one we're closing

        mockAxios.delete.mockResolvedValueOnce({});

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/browse-tabs/1');
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
                query_params: { page: 1 },
                file_ids: [],
                position: 0,
            },
        });

        const { tabs, closeTab, activeTabId } = useBrowseTabs(onTabSwitch);

        // Set up single tab
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
        ];
        activeTabId.value = 1;

        mockAxios.delete.mockResolvedValueOnce({});

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/browse-tabs/1');
        expect(tabs.value).toHaveLength(1); // New tab created
        expect(activeTabId.value).toBe(1); // New tab ID
        expect(onTabSwitch).toHaveBeenCalledWith(1); // Should switch to new tab
    });

    it('closes a non-active tab without switching', async () => {
        const onTabSwitch = vi.fn();
        const { tabs, closeTab, activeTabId } = useBrowseTabs(onTabSwitch);

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
            { id: 2, label: 'Tab 2', queryParams: {}, fileIds: [], itemsData: [], position: 1 },
        ];
        activeTabId.value = 2; // Active tab is different from the one we're closing

        mockAxios.delete.mockResolvedValueOnce({});

        await closeTab(1);

        expect(mockAxios.delete).toHaveBeenCalledWith('/api/browse-tabs/1');
        expect(tabs.value).toHaveLength(1);
        expect(tabs.value[0].id).toBe(2);
        expect(activeTabId.value).toBe(2); // Should remain the same
        expect(onTabSwitch).not.toHaveBeenCalled(); // Should not switch
    });

    it('gets active tab', () => {
        const { tabs, activeTabId, getActiveTab } = useBrowseTabs();

        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
            { id: 2, label: 'Tab 2', queryParams: {}, fileIds: [], itemsData: [], position: 1 },
        ];
        activeTabId.value = 2;

        const activeTab = getActiveTab();

        expect(activeTab).toBeDefined();
        expect(activeTab?.id).toBe(2);
        expect(activeTab?.label).toBe('Tab 2');
    });

    it('returns undefined when no active tab', () => {
        const { getActiveTab } = useBrowseTabs();

        const activeTab = getActiveTab();

        expect(activeTab).toBeUndefined();
    });

    it('updates active tab', async () => {
        const { tabs, activeTabId, updateActiveTab } = useBrowseTabs();

        // Set up tabs manually for this test (simulating loaded state)
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
        ];
        activeTabId.value = 1;

        mockAxios.put.mockResolvedValueOnce({});

        const itemsData = [
            { id: 1, width: 100, height: 100, src: 'test.jpg', type: 'image', page: 1, index: 0, notFound: false },
        ];
        const fileIds = [1];
        const queryParams = { page: 1, next: 'cursor-123' };

        updateActiveTab(itemsData, fileIds, queryParams);

        const activeTab = tabs.value.find(t => t.id === 1);
        expect(activeTab).toBeDefined();
        expect(activeTab?.itemsData).toEqual(itemsData);
        expect(activeTab?.fileIds).toEqual(fileIds);
        expect(activeTab?.queryParams).toEqual(queryParams);

        // Wait for debounce
        await new Promise(resolve => setTimeout(resolve, 600));

        expect(mockAxios.put).toHaveBeenCalledWith('/api/browse-tabs/1', {
            label: 'Tab 1',
            query_params: queryParams,
            file_ids: fileIds,
            position: 0,
        });
    });

    it('handles load tabs error gracefully', async () => {
        const error = new Error('Network error');
        // Clear any existing mocks and set up error mock
        mockAxios.get.mockReset();
        mockAxios.get.mockRejectedValue(error);

        const { loadTabs, isLoadingTabs, tabs } = useBrowseTabs();

        await expect(loadTabs()).rejects.toThrow('Network error');
        expect(isLoadingTabs.value).toBe(false);
        expect(tabs.value).toEqual([]); // Should remain empty on error
    });

    it('handles create tab error gracefully', async () => {
        mockAxios.get.mockResolvedValueOnce({ data: [] });
        const error = new Error('Network error');
        mockAxios.post.mockRejectedValueOnce(error);

        const { createTab } = useBrowseTabs();

        await expect(createTab()).rejects.toThrow('Network error');
    });

    it('handles close tab error gracefully', async () => {
        const { tabs, closeTab } = useBrowseTabs();

        // Set up tabs manually for this test
        tabs.value = [
            { id: 1, label: 'Tab 1', queryParams: {}, fileIds: [], itemsData: [], position: 0 },
        ];

        const error = new Error('Network error');
        mockAxios.delete.mockRejectedValueOnce(error);

        await expect(closeTab(1)).rejects.toThrow('Network error');
        // Tab should still exist since deletion failed
        expect(tabs.value).toHaveLength(1);
    });
});

