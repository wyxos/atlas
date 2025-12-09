<script setup lang="ts">
import { computed } from 'vue';
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

// Computed property to calculate which items to show in drawer boxes
const drawerItems = computed(() => {
    if (props.currentItemIndex === null || props.items.length === 0) {
        return Array(11).fill(null);
    }

    const items = Array(11).fill(null);
    const currentIndex = props.currentItemIndex;
    const totalItems = props.items.length;

    // Determine the center box index (6th box, index 5)
    const centerBoxIndex = 5;

    // Determine where to place the current item
    let currentItemBoxIndex: number;
    if (currentIndex > 4) {
        // If index > 4, place in center (6th box)
        currentItemBoxIndex = centerBoxIndex;
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
        <button
            @click="handlePrevious"
            :disabled="currentItemIndex === null || currentItemIndex <= 0"
            class="absolute left-4 top-1/2 -translate-y-1/2 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="Previous"
            data-test="carousel-previous-button"
        >
            <ChevronLeft :size="20" />
        </button>

        <!-- Squares container -->
        <div class="h-full p-4 flex items-center justify-center gap-4 overflow-x-auto px-16">
            <div
                v-for="(item, boxIndex) in drawerItems"
                :key="boxIndex"
                :class="[
                    'shrink-0 rounded overflow-hidden',
                    isSelectedItem(item, boxIndex) ? 'border-4 border-smart-blue-500' : 'border-2 border-smart-blue-500/50',
                    item ? 'cursor-pointer' : 'bg-smart-blue-500/20'
                ]"
                :style="{
                    width: '192px',
                    height: '192px',
                }"
                :data-test="`carousel-box-${boxIndex}`"
                @click="item && !isSelectedItem(item, boxIndex) ? handleItemClick(item) : null"
            >
                <img
                    v-if="item"
                    :src="item.src || item.thumbnail || ''"
                    :alt="`Preview ${item.id}`"
                    :class="[
                        'w-full h-full object-cover transition-all duration-300',
                        isSelectedItem(item, boxIndex) ? '' : 'opacity-50'
                    ]"
                    :data-test="`carousel-preview-${boxIndex}`"
                />
            </div>
        </div>

        <!-- Next button -->
        <button
            @click="handleNext"
            :disabled="currentItemIndex === null || currentItemIndex >= items.length - 1"
            class="absolute right-4 top-1/2 -translate-y-1/2 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="Next"
            data-test="carousel-next-button"
        >
            <ChevronRight :size="20" />
        </button>
    </div>
</template>

