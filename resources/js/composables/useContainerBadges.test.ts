import { describe, it, expect } from 'vitest';
import { nextTick, ref } from 'vue';
import { useContainerBadges } from './useContainerBadges';
import type { FeedItem } from './useTabs';

describe('useContainerBadges', () => {
    describe('Caching optimizations', () => {
        it('caches container counts for O(1) lookup', async () => {
            const items = ref<FeedItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as FeedItem,
                {
                    id: 2,
                    containers: [{ id: 10, type: 'gallery' }],
                } as FeedItem,
                {
                    id: 3,
                    containers: [{ id: 10, type: 'gallery' }],
                } as FeedItem,
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
            const items = ref<FeedItem[]>([
                {
                    id: 1,
                    containers: [{ id: 10, type: 'gallery' }],
                } as FeedItem,
            ]);

            const { getItemCountForContainerId } = useContainerBadges(items);

            // Wait for initial cache build
            await nextTick();

            expect(getItemCountForContainerId(10)).toBe(1);

            // Add more items
            items.value.push({
                id: 2,
                containers: [{ id: 10, type: 'gallery' }],
            } as FeedItem);

            // Wait for Vue reactivity and watchers to process
            // The watch defers rebuildCaches to nextTick, so we need to wait for it
            await nextTick();
            await nextTick(); // Wait for the deferred rebuildCaches to complete

            // Cache should be rebuilt
            expect(getItemCountForContainerId(10)).toBe(2);
        });
    });

    describe('Performance with large arrays', () => {
        it('handles 3000+ items efficiently', async () => {
            // Create a large array of items
            const largeItems: FeedItem[] = [];
            for (let i = 0; i < 3000; i++) {
                largeItems.push({
                    id: i,
                    containers: [{ id: Math.floor(i / 100), type: 'gallery' }],
                } as FeedItem);
            }

            const items = ref<FeedItem[]>(largeItems);
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

    describe('Container ordering', () => {
        it('sorts containers by type priority (Post before User)', () => {
            const items = ref<FeedItem[]>([
                {
                    id: 1,
                    containers: [
                        { id: 2, type: 'Post' },
                        { id: 1, type: 'User' },
                    ],
                } as FeedItem,
            ]);

            const { getContainersForItem } = useContainerBadges(items);

            const containers = getContainersForItem(items.value[0]);

            // Post should come before User regardless of ID order
            expect(containers[0].type).toBe('Post');
            expect(containers[0].id).toBe(2);
            expect(containers[1].type).toBe('User');
            expect(containers[1].id).toBe(1);
        });

        it('sorts containers by ID when types have same priority', () => {
            const items = ref<FeedItem[]>([
                {
                    id: 1,
                    containers: [
                        { id: 3, type: 'gallery' },
                        { id: 1, type: 'album' },
                        { id: 2, type: 'gallery' },
                    ],
                } as FeedItem,
            ]);

            const { getContainersForItem } = useContainerBadges(items);

            const containers = getContainersForItem(items.value[0]);

            // Same priority types should sort by ID
            expect(containers[0].type).toBe('album');
            expect(containers[0].id).toBe(1);
            expect(containers[1].type).toBe('gallery');
            expect(containers[1].id).toBe(2);
            expect(containers[2].type).toBe('gallery');
            expect(containers[2].id).toBe(3);
        });

        it('sorts Post before User even when Post has lower ID', () => {
            const items = ref<FeedItem[]>([
                {
                    id: 1,
                    containers: [
                        { id: 1, type: 'Post' },
                        { id: 2, type: 'User' },
                    ],
                } as FeedItem,
            ]);

            const { getContainersForItem } = useContainerBadges(items);

            const containers = getContainersForItem(items.value[0]);

            // Post should come first even though it has a lower ID
            expect(containers[0].type).toBe('Post');
            expect(containers[0].id).toBe(1);
            expect(containers[1].type).toBe('User');
            expect(containers[1].id).toBe(2);
        });
    });
});
