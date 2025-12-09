<script setup lang="ts">
import { computed, ref, watch, nextTick } from 'vue';
import { TransitionGroup } from 'vue';
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-vue-next';
import type { MasonryItem } from '../composables/useBrowseTabs';

interface Props {
    items: MasonryItem[];
    currentItemIndex: number | null;
    visible?: boolean;
    hasMore?: boolean;
    isLoading?: boolean;
    onLoadMore?: () => Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
    visible: false,
    hasMore: false,
    isLoading: false,
    onLoadMore: undefined,
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
const previousItemsCount = ref(0);
const imageLoadingStates = ref<Record<number, boolean>>({}); // Track which images are loading
const imageLoadedStates = ref<Record<number, boolean>>({}); // Track which images are loaded
const newItemsAnimationDelay = ref<Record<number, number>>({}); // Track animation delays for new items

const STAGGER_DELAY = 100; // Delay between each item animation in ms

// Preload an image and update loading state
async function preloadImage(item: MasonryItem, skipLoadingState = false): Promise<void> {
    const imageUrl = item.src || item.thumbnail || '';
    if (!imageUrl || imageLoadedStates.value[item.id]) {
        return; // Already loaded or no URL
    }

    if (!skipLoadingState) {
        imageLoadingStates.value[item.id] = true;
    }

    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => {
            imageLoadingStates.value[item.id] = false;
            imageLoadedStates.value[item.id] = true;
            resolve();
        };
        img.onerror = () => {
            imageLoadingStates.value[item.id] = false;
            imageLoadedStates.value[item.id] = true; // Mark as "loaded" even on error to stop spinner
            resolve();
        };
        img.src = imageUrl;
    });
}

// Preload new items with staggered delays
async function preloadNewItems(newItems: MasonryItem[], startIndex: number): Promise<void> {
    for (let i = 0; i < newItems.length; i++) {
        const item = newItems[i];
        const delay = i * STAGGER_DELAY;
        newItemsAnimationDelay.value[item.id] = delay;

        // Mark as loading immediately so spinner shows, then start preloading
        imageLoadingStates.value[item.id] = true;
        preloadImage(item, true); // Skip setting loading state since we already set it
    }

    // Clear animation delays after animation completes (500ms + max delay)
    const maxDelay = (newItems.length - 1) * STAGGER_DELAY;
    setTimeout(() => {
        newItems.forEach(item => {
            delete newItemsAnimationDelay.value[item.id];
        });
    }, 500 + maxDelay);
}

// Preload items that are currently visible in the carousel
function preloadVisibleItems(): void {
    if (props.currentItemIndex === null || props.items.length === 0) {
        return;
    }

    const currentIndex = props.currentItemIndex;
    const totalItems = props.items.length;

    // Preload items around the current item (items that might be visible)
    const startIndex = Math.max(0, currentIndex - 5);
    const endIndex = Math.min(totalItems, currentIndex + 6);

    for (let i = startIndex; i < endIndex; i++) {
        const item = props.items[i];
        if (item && !imageLoadedStates.value[item.id] && !imageLoadingStates.value[item.id]) {
            preloadImage(item);
        }
    }
}

// Computed property to calculate which items to show in drawer boxes
const drawerItems = computed(() => {
    if (props.currentItemIndex === null || props.items.length === 0) {
        return Array(11).fill(null);
    }

    const items: Array<MasonryItem | null | 'loading' | 'hidden'> = Array(11).fill(null);
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
    let nextBoxIndex = currentItemBoxIndex + 1;

    // Calculate how many items we have available after the current item
    const itemsAfterCurrent = totalItems - (currentIndex + 1);
    // Calculate how many boxes we need to fill (up to 11 total boxes)
    const boxesAfterCurrent = 11 - (currentItemBoxIndex + 1);

    // Only fill boxes if we have items, and hide empty boxes when near the end
    for (let boxIndex = nextBoxIndex; boxIndex < 11; boxIndex++) {
        if (sourceIndex < totalItems) {
            items[boxIndex] = props.items[sourceIndex];
            sourceIndex++;
        } else {
            // Hide empty boxes when we don't have more items
            items[boxIndex] = 'hidden';
        }
    }

    return items;
});

// Track newly added item IDs to trigger animations
const newlyAddedItemIds = ref<Set<number>>(new Set());

// Watch for new items being added
watch(() => props.items.length, (newLength, oldLength) => {
    const previousCount = oldLength ?? previousItemsCount.value;
    if (previousCount > 0 && newLength > previousCount) {
        // New items were added - get the new items
        const newItems = props.items.slice(previousCount);
        const startIndex = previousCount;

        // Preload new items with staggered delays
        preloadNewItems(newItems, startIndex);

        // Mark items as newly added for animation after DOM updates
        nextTick(() => {
            // Force a reflow to ensure animation triggers
            requestAnimationFrame(() => {
                newItems.forEach(item => {
                    newlyAddedItemIds.value.add(item.id);
                });

                // Clear the newly added flag after animations complete
                const maxDelay = (newItems.length - 1) * STAGGER_DELAY;
                setTimeout(() => {
                    newItems.forEach(item => {
                        newlyAddedItemIds.value.delete(item.id);
                    });
                }, 500 + maxDelay);
            });
        });
    }

    previousItemsCount.value = newLength;
}, { immediate: true });

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
watch(() => props.currentItemIndex, async (newIndex, oldIndex) => {
    // Enable transition for smooth sliding animation
    isTransitioning.value = true;

    // Check if we need to animate from previous position (when moving between items that both target center box)
    const needsOffsetAnimation = oldIndex !== null &&
        oldIndex !== undefined &&
        ((oldIndex > 4 && newIndex !== null && newIndex > 4) ||
            (oldIndex <= 4 && newIndex !== null && newIndex <= 4 && oldIndex !== newIndex));

    // Check if we're near the end and should trigger loading
    if (newIndex !== null && props.hasMore && !props.isLoading && props.onLoadMore) {
        const totalItems = props.items.length;
        // Trigger loading only when we're at the last item (totalItems - 1)
        if (newIndex === totalItems - 1) {
            await props.onLoadMore();
        }
    }

    // Preload visible items when index changes
    preloadVisibleItems();

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
            // Preload visible items when panel opens
            preloadVisibleItems();
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
                <div v-for="(item, boxIndex) in drawerItems" v-show="item !== 'hidden'"
                    :key="item === 'loading' ? `loading-${boxIndex}` : item?.id ? `item-${item.id}` : `empty-${boxIndex}`"
                    :class="[
                        'shrink-0 rounded overflow-hidden carousel-item relative',
                        item && item !== 'loading' && isSelectedItem(item, boxIndex) ? 'border-4 border-smart-blue-500' : 'border-2 border-smart-blue-500/50',
                        item && item !== 'loading' ? 'cursor-pointer' : 'bg-smart-blue-500/20',
                        item && item !== 'loading' && newlyAddedItemIds.has(item.id) ? 'carousel-item-new' : ''
                    ]" :style="{
                        width: '192px',
                        height: '192px',
                        '--animation-delay': item && item !== 'loading' && newItemsAnimationDelay[item.id] !== undefined ? `${newItemsAnimationDelay[item.id]}ms` : '0ms',
                        animation: item && item !== 'loading' && newlyAddedItemIds.has(item.id) ? `slideInFromRight 0.5s ease-out forwards` : 'none',
                        animationDelay: item && item !== 'loading' && newlyAddedItemIds.has(item.id) && newItemsAnimationDelay[item.id] !== undefined ? `${newItemsAnimationDelay[item.id]}ms` : '0ms'
                    }" :data-test="`carousel-box-${boxIndex}`"
                    @click="item && item !== 'loading' && !isSelectedItem(item, boxIndex) ? handleItemClick(item) : null">
                    <!-- Loading spinner - show while image is preloading -->
                    <div v-if="item && item !== 'loading' && imageLoadingStates[item.id] && !imageLoadedStates[item.id]"
                        class="w-full h-full flex items-center justify-center absolute inset-0 bg-smart-blue-500/20 z-10">
                        <Loader2 class="w-8 h-8 text-smart-blue-500 animate-spin" />
                    </div>
                    <!-- Image -->
                    <img v-if="item && item !== 'loading'" :src="item.src || item.thumbnail || ''"
                        :alt="`Preview ${item.id}`" :class="[
                            'w-full h-full object-cover transition-opacity duration-300',
                            // Show image if loaded, or if not being tracked (browser will handle loading)
                            // Hide only if we're actively tracking it as loading
                            imageLoadingStates[item.id] && !imageLoadedStates[item.id] ? 'opacity-0' : (isSelectedItem(item, boxIndex) ? '' : 'opacity-50')
                        ]" :style="{
                            transitionDelay: newItemsAnimationDelay[item.id] ? `${newItemsAnimationDelay[item.id]}ms` : '0ms'
                        }" :data-test="`carousel-preview-${boxIndex}`"
                        @load="imageLoadedStates[item.id] = true; imageLoadingStates[item.id] = false"
                        @error="imageLoadedStates[item.id] = true; imageLoadingStates[item.id] = false" />
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

<style scoped>
@keyframes slideInFromRight {
    from {
        opacity: 0;
        transform: translateX(30px);
    }

    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.carousel-item-new {
    animation: slideInFromRight 0.5s ease-out forwards !important;
    animation-delay: var(--animation-delay, 0ms) !important;
}
</style>
