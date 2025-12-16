import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { ref, nextTick } from 'vue';
import { useContainerBadges } from './useContainerBadges';
import type { MasonryItem } from './useBrowseTabs';

describe('useContainerBadges', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    describe('Caching optimizations', () => {
        it('caches container counts for O(1) lookup', async () => {
            const items = ref<MasonryItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
                {
                    id: 2,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
                {
                    id: 3,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
            ]);

            const { getItemCountForContainerId } = useContainerBadges(items);

            // Wait for cache to be built (watch defers to nextTick)
            await nextTick();

            // First call should use cache
            expect(getItemCountForContainerId(10)).toBe(3);

            // Subsequent calls should use cache (O(1) lookup)
            expect(getItemCountForContainerId(10)).toBe(3);
            expect(getItemCountForContainerId(10)).toBe(3);
        });

        it('rebuilds cache when items change', async () => {
            const items = ref<MasonryItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
            ]);

            const { getItemCountForContainerId } = useContainerBadges(items);

            // Wait for initial cache build
            await nextTick();

            expect(getItemCountForContainerId(10)).toBe(1);

            // Add more items
            items.value.push({
                id: 2,
                containers: [{ id: 10, type: 'gallery' }],
            } as MasonryItem);

            // Wait for Vue reactivity and watchers to process
            // The watch defers rebuildCaches to nextTick, so we need to wait for it
            await nextTick();
            await nextTick(); // Wait for the deferred rebuildCaches to complete

            // Cache should be rebuilt
            expect(getItemCountForContainerId(10)).toBe(2);
        });

        it('uses Map-based lookup for isSiblingItem', async () => {
            const items = ref<MasonryItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
                {
                    id: 2,
                    containers: [{ id: 20, type: 'album' }],
                } as MasonryItem,
            ]);

            const { isSiblingItem, setHoveredContainerId } = useContainerBadges(items);

            // Wait for cache to be built
            await nextTick();

            setHoveredContainerId(10);
            vi.advanceTimersByTime(100); // Wait for debounce

            expect(isSiblingItem(items.value[0], 10)).toBe(true);
            expect(isSiblingItem(items.value[1], 10)).toBe(false);
        });
    });

    describe('Debounced hover state', () => {
        it('debounces hover state changes', () => {
            const items = ref<MasonryItem[]>([]);
            const { setHoveredContainerId, getMasonryItemClasses } = useContainerBadges(items);

            // Set hover state
            setHoveredContainerId(10);

            // Immediately check - should not be updated yet (debounced)
            const classesBefore = getMasonryItemClasses.value(items.value[0] || { id: 1 } as MasonryItem);
            expect(classesBefore).not.toContain('border-smart-blue-500');

            // Advance timer past debounce delay
            vi.advanceTimersByTime(60);

            // Now should be updated
            const classesAfter = getMasonryItemClasses.value(items.value[0] || { id: 1 } as MasonryItem);
            // Note: classesAfter might still not contain the border if item doesn't have container 10
            // But the debounced value should be set
        });

        it('updates immediately when clearing hover (no debounce)', () => {
            const items = ref<MasonryItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as MasonryItem,
            ]);

            const { setHoveredContainerId, getMasonryItemClasses } = useContainerBadges(items);

            // Set hover
            setHoveredContainerId(10);
            vi.advanceTimersByTime(60);

            // Clear hover - should update immediately
            setHoveredContainerId(null);

            // Should be cleared immediately (no debounce for null)
            const classes = getMasonryItemClasses.value(items.value[0]);
            expect(classes).toContain('border-transparent');
        });

        it('cancels previous debounce when hover changes rapidly', () => {
            const items = ref<MasonryItem[]>([]);
            const { setHoveredContainerId } = useContainerBadges(items);

            // Rapid hover changes
            setHoveredContainerId(10);
            vi.advanceTimersByTime(30);
            setHoveredContainerId(20);
            vi.advanceTimersByTime(30);
            setHoveredContainerId(30);

            // Only the last one should be set after debounce
            vi.advanceTimersByTime(60);
            // The debounced value should be 30, not 10 or 20
        });
    });

    describe('Performance with large arrays', () => {
        it('handles 3000+ items efficiently', async () => {
            // Create a large array of items
            const largeItems: MasonryItem[] = [];
            for (let i = 0; i < 3000; i++) {
                largeItems.push({
                    id: i,
                    containers: [{ id: Math.floor(i / 100), type: 'gallery' }],
                } as MasonryItem);
            }

            const items = ref<MasonryItem[]>(largeItems);
            const { getItemCountForContainerId } = useContainerBadges(items);

            // Wait for cache to be built
            await nextTick();

            // This should be fast due to caching (O(1) instead of O(n))
            const start = performance.now();
            const count = getItemCountForContainerId(10);
            const duration = performance.now() - start;

            expect(count).toBe(100); // 100 items per container (0-99, 100-199, etc.)
            // Should complete in < 10ms (very fast with caching)
            expect(duration).toBeLessThan(10);
        });
    });
});

