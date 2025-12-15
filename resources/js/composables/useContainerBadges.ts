import { ref, computed, watch } from 'vue';
import type { MasonryItem } from './useBrowseTabs';

type PillVariant = 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Composable for managing container badges and their hover states.
 * Optimized for large item arrays (3k+) with caching and Map-based lookups.
 */
export function useContainerBadges(items: import('vue').Ref<MasonryItem[]>) {
    const hoveredContainerId = ref<number | null>(null);

    // Cache: Map<containerId, count> - O(1) lookup instead of O(n) iteration
    const containerCountCache = ref<Map<number, number>>(new Map());
    
    // Cache: Map<containerId, containerType> - O(1) lookup for container type
    const containerTypeCache = ref<Map<number, string>>(new Map());
    
    // Cache: Map<itemId, Set<containerId>> - O(1) lookup for item's containers
    const itemContainersCache = ref<Map<number, Set<number>>>(new Map());

    // Rebuild all caches when items change
    function rebuildCaches(): void {
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
    }

    // Watch items array and rebuild caches when it changes
    watch(
        () => items.value.length,
        () => {
            rebuildCaches();
        },
        { immediate: true }
    );

    // Also watch for item changes (when items are added/removed/updated)
    watch(
        () => items.value.map((item) => item.id),
        () => {
            rebuildCaches();
        },
        { deep: false }
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
    function getHoveredContainerVariant(): PillVariant | null {
        if (hoveredContainerId.value === null) {
            return null;
        }
        const containerType = containerTypeCache.value.get(hoveredContainerId.value);
        if (containerType) {
            return getVariantForContainerType(containerType);
        }
        return null;
    }

    // Get classes for masonry item based on hover state
    const getMasonryItemClasses = computed(() => (item: MasonryItem) => {
        const classes: string[] = [];

        if (hoveredContainerId.value !== null && isSiblingItem(item, hoveredContainerId.value)) {
            const variant = getHoveredContainerVariant() || 'primary';
            classes.push(`border-2 ${getBorderColorClassForVariant(variant)}`);
        } else {
            classes.push('border-2 border-transparent');
        }

        if (hoveredContainerId.value !== null && !isSiblingItem(item, hoveredContainerId.value)) {
            classes.push('opacity-50');
        } else {
            classes.push('opacity-100');
        }

        return classes.join(' ');
    });

    return {
        hoveredContainerId,
        getContainersForItem,
        getItemCountForContainerId,
        getVariantForContainerType,
        getBorderColorClassForVariant,
        isSiblingItem,
        getHoveredContainerVariant,
        getMasonryItemClasses,
    };
}

