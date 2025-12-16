import { ref, computed, watch, nextTick } from 'vue';
import type { MasonryItem } from './useBrowseTabs';

type PillVariant = 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Composable for managing container badges and their hover states.
 * Optimized for large item arrays (3k+) with caching and Map-based lookups.
 */
export function useContainerBadges(items: import('vue').Ref<MasonryItem[]>) {
    const hoveredContainerId = ref<number | null>(null);
    const debouncedHoveredContainerId = ref<number | null>(null);

    // Debounce hover state changes to reduce rapid recalculations (50ms delay)
    let hoverDebounceTimer: ReturnType<typeof setTimeout> | null = null;
    const HOVER_DEBOUNCE_MS = 50;

    // Cache: Map<containerId, count> - O(1) lookup instead of O(n) iteration
    const containerCountCache = ref<Map<number, number>>(new Map());

    // Cache: Map<containerId, containerType> - O(1) lookup for container type
    const containerTypeCache = ref<Map<number, string>>(new Map());

    // Cache: Map<itemId, Set<containerId>> - O(1) lookup for item's containers
    const itemContainersCache = ref<Map<number, Set<number>>>(new Map());

    // Track previous items to detect removals for incremental updates
    const previousItems = ref<Map<number, MasonryItem>>(new Map());

    // Incrementally update caches when items are removed (much faster than full rebuild)
    function updateCachesForRemovedItems(removedItemIds: number[]): void {
        for (const itemId of removedItemIds) {
            const itemContainers = itemContainersCache.value.get(itemId);
            if (itemContainers) {
                // Decrement count for each container
                for (const containerId of itemContainers) {
                    const currentCount = containerCountCache.value.get(containerId) || 0;
                    if (currentCount > 1) {
                        containerCountCache.value.set(containerId, currentCount - 1);
                    } else {
                        containerCountCache.value.delete(containerId);
                        containerTypeCache.value.delete(containerId);
                    }
                }
                // Remove item from cache
                itemContainersCache.value.delete(itemId);
            }
            previousItems.value.delete(itemId);
        }
    }

    // Rebuild all caches when items change (only when needed, e.g., initial load or large changes)
    function rebuildCaches(): void {
        const itemCount = items.value.length;
        const previousCount = previousItems.value.size;
        const isRemoval = itemCount < previousCount;
        
        // If items were removed and we have previous state, use incremental update
        if (isRemoval && previousCount > 0 && itemCount > 0) {
            const currentItemIds = new Set(items.value.map(item => item.id));
            const removedItemIds: number[] = [];
            
            for (const itemId of previousItems.value.keys()) {
                if (!currentItemIds.has(itemId)) {
                    removedItemIds.push(itemId);
                }
            }
            
            if (removedItemIds.length > 0 && removedItemIds.length < itemCount) {
                // Incremental update is faster for small removals
                updateCachesForRemovedItems(removedItemIds);
                
                // Update previousItems to match current
                previousItems.value.clear();
                for (const item of items.value) {
                    previousItems.value.set(item.id, item);
                }
                return;
            }
        }
        
        // Full rebuild for additions or large changes
        const countMap = new Map<number, number>();
        const typeMap = new Map<number, string>();
        const itemContainersMap = new Map<number, Set<number>>();

        for (const item of items.value) {
            const containers = (item as any).containers || [];
            const itemContainerIds = new Set<number>();

            for (const container of containers) {
                if (container?.id && container?.type) {
                    // Update count cache
                    countMap.set(container.id, (countMap.get(container.id) || 0) + 1);

                    // Update type cache (only store first occurrence, all should be same type)
                    if (!typeMap.has(container.id)) {
                        typeMap.set(container.id, container.type);
                    }

                    // Track this container for this item
                    itemContainerIds.add(container.id);
                }
            }

            if (itemContainerIds.size > 0) {
                itemContainersMap.set(item.id, itemContainerIds);
            }
        }

        containerCountCache.value = countMap;
        containerTypeCache.value = typeMap;
        itemContainersCache.value = itemContainersMap;
        
        // Update previousItems
        previousItems.value.clear();
        for (const item of items.value) {
            previousItems.value.set(item.id, item);
        }
    }

    // Watch items array length and rebuild caches when it changes
    // Defer cache updates to nextTick to avoid blocking animations
    watch(
        () => items.value.length,
        () => {
            // Defer cache rebuild to nextTick to avoid blocking removal animations
            // This allows Vibe's FLIP animations to run smoothly
            nextTick(() => {
                rebuildCaches();
            });
        },
        { immediate: true }
    );

    // Get containers for a specific item (returns full container data including referrer)
    function getContainersForItem(item: MasonryItem): Array<{ id: number; type: string; source?: string; source_id?: string; referrer?: string }> {
        const containers = (item as any).containers || [];
        return containers.filter((container: { id?: number; type?: string }) => container?.id && container?.type);
    }

    // Count items that have a container with the same container ID - O(1) lookup from cache
    function getItemCountForContainerId(containerId: number): number {
        return containerCountCache.value.get(containerId) || 0;
    }

    // Get color variant for a container type (deterministic mapping)
    function getVariantForContainerType(containerType: string): PillVariant {
        const variants: PillVariant[] = [
            'primary',
            'secondary',
            'success',
            'warning',
            'danger',
            'info',
            'neutral',
        ];

        // Simple hash function to deterministically assign variant based on container type
        let hash = 0;
        for (let i = 0; i < containerType.length; i++) {
            hash = ((hash << 5) - hash) + containerType.charCodeAt(i);
            hash = hash & hash; // Convert to 32-bit integer
        }

        // Use absolute value and modulo to get index
        const index = Math.abs(hash) % variants.length;
        return variants[index];
    }

    // Get border color class for a container type variant (matches Pill border colors)
    function getBorderColorClassForVariant(variant: PillVariant): string {
        const borderColors: Record<string, string> = {
            primary: 'border-smart-blue-500',
            secondary: 'border-sapphire-500',
            success: 'border-success-500',
            warning: 'border-warning-500',
            danger: 'border-danger-500',
            info: 'border-info-500',
            neutral: 'border-twilight-indigo-500',
        };
        return borderColors[variant] || 'border-smart-blue-500';
    }

    // Check if an item is a sibling (has the same container ID as the hovered one) - O(1) lookup
    function isSiblingItem(item: MasonryItem, hoveredContainerId: number | null): boolean {
        if (hoveredContainerId === null) {
            return false;
        }
        const itemContainers = itemContainersCache.value.get(item.id);
        return itemContainers ? itemContainers.has(hoveredContainerId) : false;
    }

    // Get the variant for the hovered container type - O(1) lookup from cache
    // Uses debounced value to avoid rapid recalculations
    function getHoveredContainerVariant(): PillVariant | null {
        const hoveredId = debouncedHoveredContainerId.value;
        if (hoveredId === null) {
            return null;
        }
        const containerType = containerTypeCache.value.get(hoveredId);
        if (containerType) {
            return getVariantForContainerType(containerType);
        }
        return null;
    }

    // Debounced setter for hovered container ID
    function setHoveredContainerId(containerId: number | null): void {
        hoveredContainerId.value = containerId;

        // Clear existing timer
        if (hoverDebounceTimer) {
            clearTimeout(hoverDebounceTimer);
        }

        // If setting to null, update immediately (no debounce for clearing)
        if (containerId === null) {
            debouncedHoveredContainerId.value = null;
            hoverDebounceTimer = null;
            return;
        }

        // Debounce setting a container ID
        hoverDebounceTimer = setTimeout(() => {
            debouncedHoveredContainerId.value = containerId;
            hoverDebounceTimer = null;
        }, HOVER_DEBOUNCE_MS);
    }

    // Get classes for masonry item based on hover state (uses debounced value)
    // Optimized: Only changes opacity (compositor-friendly) and border (instant, no transition)
    const getMasonryItemClasses = computed(() => (item: MasonryItem) => {
        const classes: string[] = [];
        const hoveredId = debouncedHoveredContainerId.value;

        // Border changes are instant (no transition) for performance
        // Using border instead of box-shadow to avoid additional rendering cost
        if (hoveredId !== null && isSiblingItem(item, hoveredId)) {
            const variant = getHoveredContainerVariant() || 'primary';
            classes.push(`border-2 ${getBorderColorClassForVariant(variant)}`);
        } else {
            classes.push('border-2 border-transparent');
        }

        // Opacity changes are smooth (compositor-friendly transition)
        if (hoveredId !== null && !isSiblingItem(item, hoveredId)) {
            classes.push('opacity-50');
        } else {
            classes.push('opacity-100');
        }

        return classes.join(' ');
    });

    return {
        hoveredContainerId,
        setHoveredContainerId,
        getContainersForItem,
        getItemCountForContainerId,
        getVariantForContainerType,
        getBorderColorClassForVariant,
        isSiblingItem,
        getHoveredContainerVariant,
        getMasonryItemClasses,
    };
}

