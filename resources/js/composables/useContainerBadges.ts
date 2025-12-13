import { ref, computed } from 'vue';
import type { MasonryItem } from './useBrowseTabs';

type PillVariant = 'primary' | 'secondary' | 'success' | 'warning' | 'danger' | 'info' | 'neutral';

/**
 * Composable for managing container badges and their hover states.
 */
export function useContainerBadges(items: import('vue').Ref<MasonryItem[]>) {
    const hoveredContainerId = ref<number | null>(null);

    // Get containers for a specific item (returns full container data including referrer)
    function getContainersForItem(item: MasonryItem): Array<{ id: number; type: string; source?: string; source_id?: string; referrer?: string }> {
        const containers = (item as any).containers || [];
        return containers.filter((container: { id?: number; type?: string }) => container?.id && container?.type);
    }

    // Count items that have a container with the same container ID
    function getItemCountForContainerId(containerId: number): number {
        let count = 0;
        items.value.forEach((item) => {
            const containers = (item as any).containers || [];
            if (containers.some((container: { id?: number }) => container?.id === containerId)) {
                count++;
            }
        });
        return count;
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

    // Check if an item is a sibling (has the same container ID as the hovered one)
    function isSiblingItem(item: MasonryItem, hoveredContainerId: number | null): boolean {
        if (hoveredContainerId === null) {
            return false;
        }
        const containers = getContainersForItem(item);
        return containers.some((container) => container.id === hoveredContainerId);
    }

    // Get the variant for the hovered container type
    function getHoveredContainerVariant(): PillVariant | null {
        if (hoveredContainerId.value === null) {
            return null;
        }
        // Find the container type for the hovered container ID
        for (const item of items.value) {
            const containers = getContainersForItem(item);
            const container = containers.find((c) => c.id === hoveredContainerId.value);
            if (container) {
                return getVariantForContainerType(container.type);
            }
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

