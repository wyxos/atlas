import { ref, type Ref, nextTick, triggerRef } from 'vue';
import type { MasonryItem } from './useTabs';
import { items as browseItems } from '@/actions/App/Http/Controllers/BrowseController';

/**
 * Composable for virtualizing masonry items - loading minimal data initially,
 * then loading full data on-demand when items come into viewport.
 */
export function useItemVirtualization(items: Ref<MasonryItem[]>) {
    // Cache of fully loaded items by ID
    const fullItemsCache = ref<Map<number, MasonryItem>>(new Map());
    
    // Set of item IDs that are currently being loaded
    const loadingItemIds = ref<Set<number>>(new Set());
    
    // Batch of item IDs waiting to be loaded
    const pendingLoadIds = ref<Set<number>>(new Set());
    
    // Debounce timer for batch loading
    let loadDebounceTimer: ReturnType<typeof setTimeout> | null = null;
    const LOAD_DEBOUNCE_MS = 300; // Wait 300ms after scroll stops before loading
    
    /**
     * Check if an item has full data loaded.
     * An item is considered to have full data if:
     * 1. It's in the cache, OR
     * 2. It has originalUrl (indicates it was loaded from database with full data)
     */
    function hasFullData(itemId: number): boolean {
        if (fullItemsCache.value.has(itemId)) {
            return true;
        }
        // Check if item already has full data (e.g., loaded from database)
        const item = items.value.find((i) => i.id === itemId);
        // If item has originalUrl, it means it was loaded with full data from database
        // No need to load it again via /api/browse/items
        return item?.originalUrl !== undefined && item.originalUrl !== null;
    }
    
    /**
     * Get full item data (from cache or return minimal item).
     */
    function getFullItem(item: MasonryItem): MasonryItem {
        const fullItem = fullItemsCache.value.get(item.id);
        return fullItem || item;
    }
    
    /**
     * Load full data for a batch of item IDs.
     */
    async function loadFullItems(itemIds: number[]): Promise<void> {
        // Filter out already loaded items and items currently loading
        const idsToLoad = itemIds.filter(
            (id) => !fullItemsCache.value.has(id) && !loadingItemIds.value.has(id)
        );
        
        if (idsToLoad.length === 0) {
            return;
        }
        
        // Mark as loading
        idsToLoad.forEach((id) => loadingItemIds.value.add(id));
        
        try {
            const response = await window.axios.post<{ items: Record<number, MasonryItem> }>(
                browseItems.url(),
                { ids: idsToLoad }
            );
            
            // Cache the loaded items
            const itemsData = response.data?.items;
            if (!itemsData) {
                return;
            }
            
            for (const [idStr, fullItem] of Object.entries(itemsData)) {
                const id = Number.parseInt(idStr, 10);
                fullItemsCache.value.set(id, fullItem);
                
                // Update the item in the items array
                // Note: items uses shallowRef, but since all properties exist initially (normalized),
                // we can now use direct assignment which is more efficient than splice
                const itemIndex = items.value.findIndex((item) => item.id === id);
                if (itemIndex !== -1) {
                    // Merge full data into existing item (preserve layout properties like key, index)
                    // Backend formatter already includes all properties, so no normalization needed
                    const existingItem = items.value[itemIndex];
                    const updatedItem = { 
                        ...fullItem, 
                        key: existingItem.key, 
                        index: existingItem.index 
                    };
                    
                    // Use splice to replace the element - Vue tracks splice() mutations better than direct assignment
                    // This ensures Masonry's v-for sees the change and updates the slot prop
                    items.value.splice(itemIndex, 1, updatedItem);
                    
                    // Manually trigger reactivity for shallowRef (still needed)
                    triggerRef(items);
                }
            }
        } catch (error) {
            console.error('Failed to load full item data:', error);
        } finally {
            // Remove from loading set
            idsToLoad.forEach((id) => loadingItemIds.value.delete(id));
        }
    }
    
    /**
     * Queue item IDs for batch loading (debounced).
     */
    function queueLoadItems(itemIds: number[]): void {
        itemIds.forEach((id) => pendingLoadIds.value.add(id));
        
        // Clear existing timer
        if (loadDebounceTimer) {
            clearTimeout(loadDebounceTimer);
        }
        
        // Debounce loading until scroll stops
        loadDebounceTimer = setTimeout(() => {
            const idsToLoad = Array.from(pendingLoadIds.value);
            pendingLoadIds.value.clear();
            if (idsToLoad.length > 0) {
                loadFullItems(idsToLoad);
            }
            loadDebounceTimer = null;
        }, LOAD_DEBOUNCE_MS);
    }
    
    /**
     * Load full data for items currently in viewport.
     */
    function loadVisibleItems(): void {
        // Get all items that don't have full data yet
        const itemsNeedingData = items.value.filter(
            (item) => !hasFullData(item.id) && !loadingItemIds.value.has(item.id)
        );
        
        if (itemsNeedingData.length === 0) {
            return;
        }
        
        // Find items that are in or near viewport
        const visibleItemIds: number[] = [];
        
        // Use IntersectionObserver would be better, but for now use scroll position
        // This is a simplified version - in production, use IntersectionObserver
        for (const item of itemsNeedingData) {
            // For now, load first 100 items and items near viewport
            // In production, use actual DOM element positions
            if (visibleItemIds.length < 100) {
                visibleItemIds.push(item.id);
            }
        }
        
        if (visibleItemIds.length > 0) {
            queueLoadItems(visibleItemIds);
        }
    }
    
    /**
     * Initialize: load full data for initially visible items.
     * Only loads items that don't already have full data (e.g., from database).
     */
    function initialize(): void {
        // Wait for DOM to be ready
        nextTick(() => {
            // Load first batch of items immediately (first 50 items)
            // But only if they don't already have full data
            const initialBatch = items.value
                .slice(0, 50)
                .filter((item) => !hasFullData(item.id))
                .map((item) => item.id);
            if (initialBatch.length > 0) {
                loadFullItems(initialBatch);
            }
            
            // Set up scroll listener to load more items when scrolling stops
            let scrollTimeout: ReturnType<typeof setTimeout> | null = null;
            const handleScroll = () => {
                if (scrollTimeout) {
                    clearTimeout(scrollTimeout);
                }
                scrollTimeout = setTimeout(() => {
                    loadVisibleItems();
                    scrollTimeout = null;
                }, 200);
            };
            
            window.addEventListener('scroll', handleScroll, { passive: true });
            
            // Also load visible items on initial mount
            loadVisibleItems();
        });
    }
    
    return {
        hasFullData,
        getFullItem,
        loadFullItems,
        queueLoadItems,
        loadVisibleItems,
        initialize,
        fullItemsCache,
    };
}

