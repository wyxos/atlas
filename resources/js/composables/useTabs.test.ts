import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTabs } from './useTabs';

const { mockAxios, mockToast } = vi.hoisted(() => {
    const toast = vi.fn();
    toast.dismiss = vi.fn();
    toast.error = vi.fn();
    toast.info = vi.fn();
    toast.success = vi.fn();
    toast.warning = vi.fn();

    return {
        mockAxios: {
            get: vi.fn(),
            post: vi.fn(),
            put: vi.fn(),
            patch: vi.fn(),
        },
        mockToast: toast,
    };
});

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
}));

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    mockAxios.get.mockResolvedValue({ data: [] });
});

describe('useTabs', () => {
    it('initializes with empty state', () => {
        const { tabs, activeTabId, isLoadingTabs } = useTabs();

        expect(tabs.value).toEqual([]);
        expect(activeTabId.value).toBeNull();
        expect(isLoadingTabs.value).toBe(false);
    });

    it('loads tabs sorted by position and captures updatedAt', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: [
                {
                    id: 2,
                    label: 'Tab 2',
                    params: { page: 2, feed: 'local' },
                    position: 1,
                    is_active: false,
                    updated_at: '2024-01-02T00:00:00Z',
                },
                {
                    id: 1,
                    label: 'Tab 1',
                    custom_label: 'Pinned',
                    params: { page: 1 },
                    position: 0,
                    is_active: true,
                    updated_at: '2024-01-01T00:00:00Z',
                },
            ],
        });

        const { tabs, activeTabId, loadTabs } = useTabs();
        await loadTabs();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/tabs', {
            headers: {
                'Cache-Control': 'no-cache',
                Pragma: 'no-cache',
            },
        });
        expect(tabs.value.map(tab => tab.id)).toEqual([1, 2]);
        expect(tabs.value[0].customLabel).toBe('Pinned');
        expect(tabs.value[0].updatedAt).toBe('2024-01-01T00:00:00Z');
        expect(tabs.value[1].feed).toBe('local');
        expect(activeTabId.value).toBe(1);
    });

    it('creates and activates a new tab without an external switch callback', async () => {
        mockAxios.post.mockResolvedValueOnce({
            data: {
                id: 10,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: false,
                updated_at: '2024-01-01T00:00:00Z',
            },
        });
        mockAxios.patch.mockResolvedValueOnce({
            data: {
                id: 10,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: true,
                updated_at: '2024-01-02T00:00:00Z',
            },
        });

        const { tabs, activeTabId, createTab } = useTabs();
        const newTab = await createTab();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/tabs', {
            label: 'Browse 1',
            custom_label: null,
            params: {},
            position: 0,
        });
        expect(mockAxios.patch).toHaveBeenCalledWith('/api/tabs/10/active');
        expect(newTab.id).toBe(10);
        expect(tabs.value).toHaveLength(1);
        expect(activeTabId.value).toBe(10);
    });

    it('duplicates a background tab and places the copy next to the source tab', async () => {
        const { loadTabs, duplicateTab, tabs, activeTabId } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: { page: 1 }, position: 0, is_active: true, updated_at: '2024-01-01T00:00:00Z' },
                {
                    id: 2,
                    label: 'Tab 2',
                    custom_label: 'Saved Search',
                    params: { page: 4, service: 'civit-ai-images', serviceFilters: ['anime'] },
                    position: 1,
                    is_active: false,
                    updated_at: '2024-01-02T00:00:00Z',
                },
                { id: 3, label: 'Tab 3', params: { page: 7 }, position: 2, is_active: false, updated_at: '2024-01-03T00:00:00Z' },
            ],
        });

        await loadTabs();

        mockAxios.post.mockImplementation((url: string) => {
            if (url === '/api/tabs') {
                return Promise.resolve({
                    data: {
                        id: 4,
                        label: 'Tab 2',
                        custom_label: 'Saved Search',
                        params: { page: 4, service: 'civit-ai-images', serviceFilters: ['anime'] },
                        position: 3,
                        is_active: false,
                        updated_at: '2024-01-04T00:00:00Z',
                    },
                });
            }

            if (url === '/api/tabs/reorder') {
                return Promise.resolve({
                    data: {
                        ordered_ids: [1, 2, 4, 3],
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        const duplicate = await duplicateTab(2);

        expect(mockAxios.post).toHaveBeenNthCalledWith(1, '/api/tabs', {
            label: 'Tab 2',
            custom_label: 'Saved Search',
            params: { page: 4, service: 'civit-ai-images', serviceFilters: ['anime'] },
            position: 3,
        });
        expect(mockAxios.post).toHaveBeenNthCalledWith(2, '/api/tabs/reorder', {
            ordered_ids: [1, 2, 4, 3],
        });
        expect(duplicate?.id).toBe(4);
        expect(tabs.value.map(tab => tab.id)).toEqual([1, 2, 4, 3]);
        expect(activeTabId.value).toBe(1);
    });

    it('closes the active tab and activates the most recently focused surviving tab', async () => {
        const { tabs, activeTabId, closeTab, loadTabs, setActiveTab } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: false, updated_at: '2024-01-01T00:00:00Z' },
                { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: false, updated_at: '2024-01-02T00:00:00Z' },
                { id: 3, label: 'Tab 3', params: {}, position: 2, is_active: true, updated_at: '2024-01-03T00:00:00Z' },
            ],
        });

        await loadTabs();

        mockAxios.patch.mockResolvedValueOnce({
            data: { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: true, updated_at: '2024-01-04T00:00:00Z' },
        });
        await setActiveTab(2);

        mockAxios.patch.mockResolvedValueOnce({
            data: { id: 3, label: 'Tab 3', params: {}, position: 2, is_active: true, updated_at: '2024-01-05T00:00:00Z' },
        });
        await setActiveTab(3);

        mockAxios.post.mockResolvedValueOnce({
            data: {
                deleted_ids: [3],
                active_tab_id: 2,
            },
        });

        await closeTab(3);

        expect(mockAxios.post).toHaveBeenCalledWith('/api/tabs/bulk-delete', {
            ids: [3],
            next_active_id: 2,
        });
        expect(tabs.value.map(tab => tab.id)).toEqual([1, 2]);
        expect(activeTabId.value).toBe(2);
    });

    it('keeps the active tab when closing a background tab', async () => {
        const { closeTab, loadTabs, activeTabId, tabs } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: false, updated_at: '2024-01-01T00:00:00Z' },
                { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: true, updated_at: '2024-01-02T00:00:00Z' },
            ],
        });

        await loadTabs();

        mockAxios.post.mockResolvedValueOnce({
            data: {
                deleted_ids: [1],
                active_tab_id: 2,
            },
        });

        await closeTab(1);

        expect(mockAxios.post).toHaveBeenCalledWith('/api/tabs/bulk-delete', {
            ids: [1],
            next_active_id: null,
        });
        expect(activeTabId.value).toBe(2);
        expect(tabs.value.map(tab => tab.id)).toEqual([2]);
    });

    it('creates a replacement tab when the last tab is closed', async () => {
        const { closeTab, loadTabs, activeTabId, tabs } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: true, updated_at: '2024-01-01T00:00:00Z' },
            ],
        });

        await loadTabs();

        mockAxios.post.mockImplementation((url: string) => {
            if (url === '/api/tabs/bulk-delete') {
                return Promise.resolve({
                    data: {
                        deleted_ids: [1],
                        active_tab_id: null,
                    },
                });
            }

            if (url === '/api/tabs') {
                return Promise.resolve({
                    data: {
                        id: 9,
                        label: 'Browse 1',
                        params: {},
                        position: 0,
                        is_active: false,
                        updated_at: '2024-01-02T00:00:00Z',
                    },
                });
            }

            return Promise.resolve({ data: {} });
        });

        mockAxios.patch.mockResolvedValueOnce({
            data: {
                id: 9,
                label: 'Browse 1',
                params: {},
                position: 0,
                is_active: true,
                updated_at: '2024-01-03T00:00:00Z',
            },
        });

        await closeTab(1);

        expect(tabs.value).toHaveLength(1);
        expect(tabs.value[0].id).toBe(9);
        expect(activeTabId.value).toBe(9);
    });

    it('reorders tabs and persists the full ordered id list', async () => {
        const { loadTabs, reorderTabs, tabs } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: true, updated_at: '2024-01-01T00:00:00Z' },
                { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: false, updated_at: '2024-01-02T00:00:00Z' },
                { id: 3, label: 'Tab 3', params: {}, position: 2, is_active: false, updated_at: '2024-01-03T00:00:00Z' },
            ],
        });

        await loadTabs();

        mockAxios.post.mockResolvedValueOnce({
            data: {
                ordered_ids: [3, 1, 2],
            },
        });

        await reorderTabs([3, 1, 2]);

        expect(mockAxios.post).toHaveBeenCalledWith('/api/tabs/reorder', {
            ordered_ids: [3, 1, 2],
        });
        expect(tabs.value.map(tab => ({ id: tab.id, position: tab.position }))).toEqual([
            { id: 3, position: 0 },
            { id: 1, position: 1 },
            { id: 2, position: 2 },
        ]);
    });

    it('rolls back close state and shows a toast when bulk close fails', async () => {
        const { closeTab, loadTabs, tabs, activeTabId } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: true, updated_at: '2024-01-01T00:00:00Z' },
                { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: false, updated_at: '2024-01-02T00:00:00Z' },
            ],
        });

        await loadTabs();

        const error = new Error('Network error');
        mockAxios.post.mockRejectedValueOnce(error);

        await expect(closeTab(1)).rejects.toThrow('Network error');

        expect(tabs.value.map(tab => tab.id)).toEqual([1, 2]);
        expect(activeTabId.value).toBe(1);
        expect(mockToast).toHaveBeenCalled();
    });

    it('rolls back reorder state and shows a toast when reorder fails', async () => {
        const { loadTabs, reorderTabs, tabs } = useTabs();

        mockAxios.get.mockResolvedValueOnce({
            data: [
                { id: 1, label: 'Tab 1', params: {}, position: 0, is_active: true, updated_at: '2024-01-01T00:00:00Z' },
                { id: 2, label: 'Tab 2', params: {}, position: 1, is_active: false, updated_at: '2024-01-02T00:00:00Z' },
            ],
        });

        await loadTabs();

        const error = new Error('Reorder failed');
        mockAxios.post.mockRejectedValueOnce(error);

        await expect(reorderTabs([2, 1])).rejects.toThrow('Reorder failed');

        expect(tabs.value.map(tab => ({ id: tab.id, position: tab.position }))).toEqual([
            { id: 1, position: 0 },
            { id: 2, position: 1 },
        ]);
        expect(mockToast).toHaveBeenCalled();
    });

    it('debounces repeated tab label updates to the latest payload', async () => {
        vi.useFakeTimers();

        try {
            mockAxios.get.mockResolvedValueOnce({
                data: [
                    {
                        id: 52,
                        label: 'Local - Reacted (Random) - 8',
                        custom_label: 'Reacted - random',
                        params: { feed: 'local' },
                        position: 6,
                        is_active: true,
                        updated_at: '2024-01-01T00:00:00Z',
                    },
                ],
            });
            mockAxios.put.mockResolvedValueOnce({
                data: {
                    id: 52,
                    label: 'Local Files - Reacted (Random) - 9',
                    custom_label: 'Reacted - random',
                    params: { feed: 'local' },
                    position: 6,
                    is_active: true,
                    updated_at: '2024-01-02T00:00:00Z',
                },
            });

            const { loadTabs, updateTabLabel } = useTabs();
            await loadTabs();

            updateTabLabel(52, 'Local Files - Reacted (Random) - 8');
            updateTabLabel(52, 'Local Files - Reacted (Random) - 9');
            updateTabLabel(52, 'Local Files - Reacted (Random) - 9');

            await vi.advanceTimersByTimeAsync(499);
            expect(mockAxios.put).not.toHaveBeenCalled();

            await vi.advanceTimersByTimeAsync(1);
            expect(mockAxios.put).toHaveBeenCalledTimes(1);
            expect(mockAxios.put).toHaveBeenCalledWith('/api/tabs/52', {
                label: 'Local Files - Reacted (Random) - 9',
                custom_label: 'Reacted - random',
                position: 6,
            });
        } finally {
            vi.useRealTimers();
        }
    });
});
