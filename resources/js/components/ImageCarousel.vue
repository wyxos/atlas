<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue';
import { TransitionGroup } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import type { MasonryItem } from '../composables/useBrowseTabs';

interface Props {
    items: MasonryItem[];
    currentItemIndex: number | null;
    visible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    visible: false,
});

const emit = defineEmits<{
    next: [];
    previous: [];
    'item-click': [item: MasonryItem];
}>();

// Box dimensions and spacing
const BOX_WIDTH = 192;
const BOX_GAP = 16; // gap-4 = 16px
const BOX_SIZE = BOX_WIDTH + BOX_GAP;
const CENTER_BOX_INDEX = 5; // 6th box (0-indexed)

// Track scroll position for smooth sliding
const scrollPosition = ref(0);
const containerRef = ref<HTMLElement | null>(null);
const isTransitioning = ref(false);
const previousIndex = ref<number | null>(null);

// Computed property to calculate which items to show in drawer boxes
const drawerItems = computed(() => {
    if (props.currentItemIndex === null || props.items.length === 0) {
        return Array(11).fill(null);
    }

    const items = Array(11).fill(null);
    const currentIndex = props.currentItemIndex;
    const totalItems = props.items.length;

    // Determine where to place the current item
    let currentItemBoxIndex: number;
    if (currentIndex > 4) {
        // If index > 4, place in center (6th box)
        currentItemBoxIndex = CENTER_BOX_INDEX;
    } else {
        // If index <= 4, place at corresponding box index
        currentItemBoxIndex = currentIndex;
    }

    // Place current item
    items[currentItemBoxIndex] = props.items[currentIndex];

    // Fill boxes before current item
    let sourceIndex = currentIndex - 1;
    for (let boxIndex = currentItemBoxIndex - 1; boxIndex >= 0 && sourceIndex >= 0; boxIndex--) {
        items[boxIndex] = props.items[sourceIndex];
        sourceIndex--;
    }

    // Fill boxes after current item
    sourceIndex = currentIndex + 1;
    for (let boxIndex = currentItemBoxIndex + 1; boxIndex < 11 && sourceIndex < totalItems; boxIndex++) {
        items[boxIndex] = props.items[sourceIndex];
        sourceIndex++;
    }

    return items;
});

// Calculate scroll position to center the current item
const calculateScrollPosition = (animateFromPrevious = false): void => {
    if (props.currentItemIndex === null || props.items.length === 0 || !containerRef.value) {
        scrollPosition.value = 0;
        return;
    }

    const currentIndex = props.currentItemIndex;
    let targetBoxIndex: number;

    if (currentIndex > 4) {
        // If index > 4, center box (index 5) should show this item
        targetBoxIndex = CENTER_BOX_INDEX;
    } else {
        // If index <= 4, item is at its own index
        targetBoxIndex = currentIndex;
    }

    // Calculate the scroll position to center the target box
    // Account for padding (p-4 = 16px on each side, px-16 = 64px on each side)
    const paddingLeft = 64; // px-16 = 64px
    const containerWidth = containerRef.value.clientWidth;
    const containerCenter = containerWidth / 2;

    // Position of the target box's center relative to the start of the flex container
    // Each box is BOX_SIZE wide (192px + 16px gap)
    const targetBoxLeft = targetBoxIndex * BOX_SIZE;
    const targetBoxCenter = targetBoxLeft + BOX_WIDTH / 2;

    // Calculate scroll position: we want the target box center to align with container center
    // Account for the padding on the left side
    const newScrollPosition = targetBoxCenter + paddingLeft - containerCenter;

    // If we're moving forward/backward but ending at the same scroll position (items 7+),
    // create a sliding effect by temporarily offsetting the position
    if (animateFromPrevious && previousIndex.value !== null && previousIndex.value !== currentIndex) {
        const direction = currentIndex > previousIndex.value ? 1 : -1;
        const offset = direction * BOX_SIZE; // Slide one box width in the direction of movement

        // Start from offset position
        scrollPosition.value = newScrollPosition + offset;

        // Then animate to final position
        nextTick(() => {
            requestAnimationFrame(() => {
                scrollPosition.value = newScrollPosition;
            });
        });
    } else {
        // Normal case: just update the position
        scrollPosition.value = newScrollPosition;
    }

    // Update previous index
    previousIndex.value = currentIndex;
};

// Watch for changes in currentItemIndex and update scroll position
watch(() => props.currentItemIndex, (newIndex, oldIndex) => {
    // Enable transition for smooth sliding animation
    isTransitioning.value = true;

    // Check if we need to animate from previous position (when moving between items that both target center box)
    const needsOffsetAnimation = oldIndex !== null &&
        oldIndex !== undefined &&
        ((oldIndex > 4 && newIndex !== null && newIndex > 4) ||
            (oldIndex <= 4 && newIndex !== null && newIndex <= 4 && oldIndex !== newIndex));

    nextTick(() => {
        // Use requestAnimationFrame to ensure calculation happens after DOM updates
        requestAnimationFrame(() => {
            calculateScrollPosition(needsOffsetAnimation);
            // Reset transitioning flag after transition completes
            setTimeout(() => {
                isTransitioning.value = false;
            }, 500); // Match transition duration
        });
    });
}, { immediate: true });

// Watch for visibility changes to recalculate when panel opens
watch(() => props.visible, (newVal) => {
    if (newVal) {
        nextTick(() => {
            calculateScrollPosition();
        });
    }
});

// Helper to check if an item is the currently selected one
function isSelectedItem(item: MasonryItem | null, boxIndex: number): boolean {
    if (!item || props.currentItemIndex === null) return false;

    // Check if this item is at the current index in the items array
    const itemArrayIndex = props.items.indexOf(item);
    return itemArrayIndex === props.currentItemIndex;
}

function handlePrevious(): void {
    emit('previous');
}

function handleNext(): void {
    emit('next');
}

function handleItemClick(item: MasonryItem): void {
    emit('item-click', item);
}
</script>

<template>
    <!-- Bottom panel (slides up from bottom) -->
    <div :class="[
        'bg-prussian-blue-900 border-t border-smart-blue-500 transition-all duration-500 ease-in-out overflow-hidden z-40 relative',
        visible ? 'opacity-100' : 'opacity-0'
    ]" data-test="image-carousel" :style="{
        height: visible ? '200px' : '0px',
        transform: visible ? 'translateY(0)' : 'translateY(100%)',
    }">
        <!-- Previous button -->
        <button @click="handlePrevious" :disabled="currentItemIndex === null || currentItemIndex <= 0"
            class="absolute left-4 top-1/2 -translate-y-1/2 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="Previous" data-test="carousel-previous-button">
            <ChevronLeft :size="20" />
        </button>

        <!-- Squares container with sliding animation -->
        <div ref="containerRef" class="h-full overflow-hidden px-16 py-4 relative">
            <TransitionGroup tag="div" class="flex items-center gap-4 h-full" :class="{
                'transition-transform duration-500 ease-in-out': isTransitioning
            }" :style="{
                    transform: `translateX(${-scrollPosition}px)`,
                }" move-class="transition-transform duration-500 ease-in-out" name="carousel-item">
                <div v-for="(item, boxIndex) in drawerItems" :key="item?.id ? `item-${item.id}` : `empty-${boxIndex}`"
                    :class="[
                        'shrink-0 rounded overflow-hidden carousel-item',
                        isSelectedItem(item, boxIndex) ? 'border-4 border-smart-blue-500' : 'border-2 border-smart-blue-500/50',
                        item ? 'cursor-pointer' : 'bg-smart-blue-500/20'
                    ]" :style="{
                        width: '192px',
                        height: '192px',
                    }" :data-test="`carousel-box-${boxIndex}`"
                    @click="item && !isSelectedItem(item, boxIndex) ? handleItemClick(item) : null">
                    <img v-if="item" :src="item.src || item.thumbnail || ''" :alt="`Preview ${item.id}`" :class="[
                        'w-full h-full object-cover transition-opacity duration-300',
                        isSelectedItem(item, boxIndex) ? '' : 'opacity-50'
                    ]" :data-test="`carousel-preview-${boxIndex}`" />
                </div>
            </TransitionGroup>
        </div>

        <!-- Next button -->
        <button @click="handleNext" :disabled="currentItemIndex === null || currentItemIndex >= items.length - 1"
            class="absolute right-4 top-1/2 -translate-y-1/2 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="Next" data-test="carousel-next-button">
            <ChevronRight :size="20" />
        </button>
    </div>
</template>
