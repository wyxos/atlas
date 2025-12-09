<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
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

// Item dimensions
const ITEM_SIZE = 170; // 170px square
const ITEM_GAP = 16; // gap between items
const ITEM_TOTAL = ITEM_SIZE + ITEM_GAP;

// Container ref for calculations
const containerRef = ref<HTMLElement | null>(null);
const scrollPosition = ref(0);
const isTransitioning = ref(false);
const clickedItemId = ref<number | null>(null); // Track clicked item for immediate active state
const clickedIndex = ref<number | null>(null); // Track clicked index for scroll calculation

// Calculate scroll position to center the current item
function calculateScrollPosition(): void {
    if (props.currentItemIndex === null || props.items.length === 0 || !containerRef.value) {
        scrollPosition.value = 0;
        return;
    }

    const containerWidth = containerRef.value.clientWidth;
    const containerCenter = containerWidth / 2;

    // Position of current item's center
    const currentItemCenter = props.currentItemIndex * ITEM_TOTAL + ITEM_SIZE / 2;

    // Scroll position to center the current item
    scrollPosition.value = currentItemCenter - containerCenter;
}

// Watch for currentItemIndex changes and animate
watch(() => props.currentItemIndex, (newIndex) => {
    isTransitioning.value = true;

    // Clear clicked tracking if the new index matches what we clicked
    if (newIndex !== null && clickedIndex.value !== null && clickedIndex.value === newIndex) {
        clickedItemId.value = null;
        clickedIndex.value = null;
    }

    calculateScrollPosition();

    // Check if we're at the last item and should trigger loading
    if (newIndex !== null && props.hasMore && !props.isLoading && props.onLoadMore) {
        const totalItems = props.items.length;
        // Trigger loading only when we're at the last item (totalItems - 1)
        if (newIndex === totalItems - 1) {
            // Don't await - let it load in background without blocking carousel animation
            props.onLoadMore();
        }
    }

    // Reset transition flag after animation completes
    setTimeout(() => {
        isTransitioning.value = false;
    }, 500);
}, { immediate: true });

// Watch for visibility to recalculate on open
watch(() => props.visible, (newVal) => {
    if (newVal) {
        setTimeout(() => {
            calculateScrollPosition();
        }, 100);
    }
});

function handlePrevious(): void {
    emit('previous');
}

function handleNext(): void {
    emit('next');
}

function handleItemClick(item: MasonryItem): void {
    // Find the index of the clicked item
    const itemIndex = props.items.findIndex(i => i.id === item.id);

    // Only animate if it's not already the current item
    if (itemIndex !== -1 && itemIndex !== props.currentItemIndex) {
        // Track clicked item and index for immediate active state and scroll calculation
        clickedItemId.value = item.id;
        clickedIndex.value = itemIndex;

        // Animate immediately to center the clicked item
        isTransitioning.value = true;
        calculateScrollPosition();

        // Reset transition flag after animation completes
        setTimeout(() => {
            isTransitioning.value = false;
        }, 500);

        // Check if clicking on last item and should trigger loading
        const totalItems = props.items.length;
        if (itemIndex === totalItems - 1 && props.hasMore && !props.isLoading && props.onLoadMore) {
            // Trigger loading when clicking on last item
            props.onLoadMore();
        }

        // Emit the event - parent will update currentItemIndex
        emit('item-click', item);
    }
}

function isCurrentItem(item: MasonryItem): boolean {
    // Check if this is the clicked item (immediate active state)
    if (clickedItemId.value === item.id) return true;

    // Check if this is the current item from props
    if (props.currentItemIndex === null) return false;
    return props.items[props.currentItemIndex]?.id === item.id;
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

        <!-- Items container -->
        <div ref="containerRef" class="h-full overflow-hidden px-16 py-4 relative">
            <div class="h-full flex items-center gap-4" :class="{
                'transition-transform duration-500 ease-in-out': isTransitioning
            }" :style="{
                transform: `translateX(${-scrollPosition}px)`,
            }">
                <div v-for="(item, index) in items" :key="item.id" :class="[
                    'shrink-0 rounded overflow-hidden cursor-pointer border-2 transition-all duration-300',
                    isCurrentItem(item) ? 'border-smart-blue-500 border-4 scale-110' : 'border-smart-blue-500/50 opacity-50'
                ]" :style="{
                    width: `${ITEM_SIZE}px`,
                    height: `${ITEM_SIZE}px`,
                }" :data-test="`carousel-item-${index}`" @click="handleItemClick(item)">
                    <img :src="item.src || item.thumbnail || ''" :alt="`Preview ${item.id}`"
                        class="w-full h-full object-cover" :data-test="`carousel-preview-${index}`" />
                </div>
            </div>
        </div>

        <!-- Next button -->
        <button @click="handleNext" :disabled="currentItemIndex === null || currentItemIndex >= items.length - 1"
            class="absolute right-4 top-1/2 -translate-y-1/2 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="Next" data-test="carousel-next-button">
            <ChevronRight :size="20" />
        </button>
    </div>
</template>
