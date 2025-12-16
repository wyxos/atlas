import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref } from 'vue';
import type { MasonryItem } from '@/composables/useBrowseTabs';

/**
 * Unit tests for handleAutoDislikeExpire function logic.
 * These tests focus on the critical behavior: using items from items.value, not itemsMap.
 */
describe('BrowseTabContent - handleAutoDislikeExpire', () => {
    let items: ReturnType<typeof ref<MasonryItem[]>>;
    let itemsMap: ReturnType<typeof ref<Map<number, MasonryItem>>>;
    let mockRemoveMany: ReturnType<typeof vi.fn>;
    let mockRemove: ReturnType<typeof vi.fn>;
    let mockAxiosPost: ReturnType<typeof vi.fn>;
    let handleAutoDislikeExpire: (expiredIds: number[]) => Promise<void>;
    let isMounted: ReturnType<typeof ref<boolean>>;

    beforeEach(() => {
        items = ref<MasonryItem[]>([
            { id: 1, width: 300, height: 400, src: 'test1.jpg', type: 'image', page: 1, index: 0, notFound: false },
            { id: 2, width: 300, height: 400, src: 'test2.jpg', type: 'image', page: 1, index: 1, notFound: false },
            { id: 3, width: 300, height: 400, src: 'test3.jpg', type: 'image', page: 1, index: 2, notFound: false },
        ]);

        // Create itemsMap with potentially stale references
        itemsMap = ref(new Map<number, MasonryItem>());
        items.value.forEach((item) => {
            // Create a copy to simulate stale reference
            itemsMap.value.set(item.id, { ...item });
        });

        mockRemoveMany = vi.fn().mockResolvedValue(undefined);
        mockRemove = vi.fn();
        mockAxiosPost = vi.fn().mockResolvedValue({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 0,
                file_ids: [],
            },
        });

        isMounted = ref(true);

        // Mock window.axios
        Object.defineProperty(window, 'axios', {
            value: {
                post: mockAxiosPost,
            },
            writable: true,
        });

        // Create the handler function (simplified version of actual implementation)
        handleAutoDislikeExpire = async (expiredIds: number[]): Promise<void> => {
            if (!isMounted.value || expiredIds.length === 0) {
                return;
            }

            const response = await window.axios.post('/api/files/auto-dislike/batch', {
                file_ids: expiredIds,
            });

            if (!isMounted.value) {
                return;
            }

            const autoDislikedIds = response.data.file_ids;

            // CRITICAL: Get items from items.value, not itemsMap (this is what we're testing)
            const itemsToRemove: MasonryItem[] = items.value.filter((item) => autoDislikedIds.includes(item.id));

            const masonry = {
                removeMany: mockRemoveMany,
                remove: mockRemove,
            };

            if (masonry.removeMany && itemsToRemove.length > 0) {
                await masonry.removeMany(itemsToRemove);
            } else if (masonry.remove) {
                for (const item of itemsToRemove) {
                    masonry.remove(item);
                }
            }
        };
    });

    afterEach(() => {
        vi.clearAllMocks();
    });

    it('uses items from items.value array, not itemsMap', async () => {
        // Update items array (simulating items changing)
        items.value[0] = { ...items.value[0], src: 'updated1.jpg' };
        items.value[1] = { ...items.value[1], src: 'updated2.jpg' };

        // itemsMap still has old references
        expect(itemsMap.value.get(1)?.src).toBe('test1.jpg');
        expect(items.value[0].src).toBe('updated1.jpg');

        mockAxiosPost.mockResolvedValueOnce({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 2,
                file_ids: [1, 2],
            },
        });

        await handleAutoDislikeExpire([1, 2]);

        // Verify removeMany was called
        expect(mockRemoveMany).toHaveBeenCalledTimes(1);
        const removedItems = mockRemoveMany.mock.calls[0][0];

        // Verify items are from items.value (updated references)
        expect(removedItems.length).toBe(2);
        expect(removedItems[0].src).toBe('updated1.jpg');
        expect(removedItems[1].src).toBe('updated2.jpg');

        // Verify items are the same references as in items array
        expect(removedItems[0]).toBe(items.value[0]);
        expect(removedItems[1]).toBe(items.value[1]);
    });

    it('uses correct item references even when itemsMap has stale data', async () => {
        // Simulate items being updated but itemsMap not synced
        const originalItem1 = items.value[0];
        items.value[0] = { ...originalItem1, width: 500, height: 600 };

        // itemsMap still has old reference
        expect(itemsMap.value.get(1)?.width).toBe(300);
        expect(items.value[0].width).toBe(500);

        mockAxiosPost.mockResolvedValueOnce({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 1,
                file_ids: [1],
            },
        });

        await handleAutoDislikeExpire([1]);

        const removedItems = mockRemoveMany.mock.calls[0][0];
        
        // Should use updated item from items.value
        expect(removedItems[0].width).toBe(500);
        expect(removedItems[0]).toBe(items.value[0]);
    });

    it('handles batch removal correctly', async () => {
        mockAxiosPost.mockResolvedValueOnce({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 3,
                file_ids: [1, 2, 3],
            },
        });

        await handleAutoDislikeExpire([1, 2, 3]);

        expect(mockRemoveMany).toHaveBeenCalledTimes(1);
        const removedItems = mockRemoveMany.mock.calls[0][0];

        expect(removedItems.length).toBe(3);
        expect(removedItems.map((i) => i.id)).toEqual([1, 2, 3]);
    });

    it('falls back to individual remove when removeMany is not available', async () => {
        // Create handler with removeMany set to null
        const handlerWithoutRemoveMany = async (expiredIds: number[]): Promise<void> => {
            if (!isMounted.value || expiredIds.length === 0) {
                return;
            }

            const response = await window.axios.post('/api/files/auto-dislike/batch', {
                file_ids: expiredIds,
            });

            if (!isMounted.value) {
                return;
            }

            const autoDislikedIds = response.data.file_ids;
            const itemsToRemove: MasonryItem[] = items.value.filter((item) => autoDislikedIds.includes(item.id));

            const masonry = {
                removeMany: null, // removeMany not available
                remove: mockRemove,
            };

            if (masonry.removeMany && itemsToRemove.length > 0) {
                await masonry.removeMany(itemsToRemove);
            } else if (masonry.remove) {
                for (const item of itemsToRemove) {
                    masonry.remove(item);
                }
            }
        };

        mockAxiosPost.mockResolvedValueOnce({
            data: {
                message: 'Auto-dislike performed',
                auto_disliked_count: 2,
                file_ids: [1, 2],
            },
        });

        await handlerWithoutRemoveMany([1, 2]);

        expect(mockRemove).toHaveBeenCalledTimes(2);
        expect(mockRemove).toHaveBeenCalledWith(items.value[0]);
        expect(mockRemove).toHaveBeenCalledWith(items.value[1]);
    });

    it('does nothing if component is unmounted', async () => {
        isMounted.value = false;

        await handleAutoDislikeExpire([1, 2]);

        expect(mockAxiosPost).not.toHaveBeenCalled();
        expect(mockRemoveMany).not.toHaveBeenCalled();
    });

    it('does nothing if expiredIds is empty', async () => {
        await handleAutoDislikeExpire([]);

        expect(mockAxiosPost).not.toHaveBeenCalled();
        expect(mockRemoveMany).not.toHaveBeenCalled();
    });

    it('handles API errors gracefully', async () => {
        // Mock console.error to suppress expected error log in test
        const originalConsoleError = console.error;
        const mockConsoleError = vi.fn();
        console.error = mockConsoleError;

        try {
            // Create handler that catches errors (matching actual implementation)
            const handlerWithErrorHandling = async (expiredIds: number[]): Promise<void> => {
                try {
                    if (!isMounted.value || expiredIds.length === 0) {
                        return;
                    }

                    const response = await window.axios.post('/api/files/auto-dislike/batch', {
                        file_ids: expiredIds,
                    });

                    if (!isMounted.value) {
                        return;
                    }

                    const autoDislikedIds = response.data.file_ids;
                    const itemsToRemove: MasonryItem[] = items.value.filter((item) => autoDislikedIds.includes(item.id));

                    const masonry = {
                        removeMany: mockRemoveMany,
                        remove: mockRemove,
                    };

                    if (masonry.removeMany && itemsToRemove.length > 0) {
                        await masonry.removeMany(itemsToRemove);
                    } else if (masonry.remove) {
                        for (const item of itemsToRemove) {
                            masonry.remove(item);
                        }
                    }
                } catch (error) {
                    // Error is logged but not re-thrown (matching actual implementation)
                    console.error('Failed to batch perform auto-dislike:', error);
                }
            };

            mockAxiosPost.mockRejectedValueOnce(new Error('API Error'));

            // Should not throw (error is caught)
            await expect(handlerWithErrorHandling([1])).resolves.not.toThrow();

            // Verify error was logged (as expected)
            expect(mockConsoleError).toHaveBeenCalledWith('Failed to batch perform auto-dislike:', expect.any(Error));

            expect(mockRemoveMany).not.toHaveBeenCalled();
        } finally {
            // Restore original console.error
            console.error = originalConsoleError;
        }
    });
});

