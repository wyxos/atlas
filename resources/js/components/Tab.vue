<script setup lang="ts">
import { X, Layers, Loader2 } from 'lucide-vue-next';

interface Props {
    id: number | string;
    label: string;
    isActive?: boolean;
    isMinimized?: boolean;
    isLoading?: boolean; // Tab data loading (spinner)
    isMasonryLoading?: boolean; // Masonry loading (pill)
}

withDefaults(defineProps<Props>(), {
    isActive: false,
    isMinimized: false,
    isLoading: false,
    isMasonryLoading: false,
});

const emit = defineEmits<{
    click: [];
    close: [];
}>();

function handleClick(event: MouseEvent | KeyboardEvent): void {
    // Middle click (button 1) should close the tab
    if (event instanceof MouseEvent && event.button === 1) {
        event.preventDefault();
        event.stopPropagation();
        emit('close');
        return;
    }
    emit('click');
}

function handleClose(event: MouseEvent): void {
    event.stopPropagation();
    emit('close');
}

function handleMouseDown(event: MouseEvent): void {
    // Prevent default middle click behavior (opening link in new tab)
    if (event.button === 1) {
        event.preventDefault();
    }
}
</script>

<template>
    <div
        @click="handleClick"
        @mousedown="handleMouseDown"
        :class="[
            'group flex items-center justify-between w-full px-2.5 py-1.5 h-8 rounded-md transition-all cursor-pointer select-none',
            isActive
                ? 'bg-smart-blue-600/80 text-white'
                : 'text-twilight-indigo-200 hover:bg-prussian-blue-700/50 hover:text-twilight-indigo-100',
        ]"
        role="button"
        tabindex="0"
        @keydown.enter="handleClick"
        @keydown.space.prevent="handleClick"
    >
        <div class="flex items-center gap-2 flex-1 min-w-0">
            <Layers class="w-4 h-4 shrink-0" />
            <span
                v-show="!isMinimized"
                class="text-xs font-normal whitespace-nowrap truncate transition-opacity duration-200"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
            >
                {{ label }}
            </span>
            <!-- Tab data loading spinner (loading files associated with tab) -->
            <Loader2
                v-if="isLoading"
                :size="12"
                class="animate-spin shrink-0 transition-opacity duration-200"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                data-test="tab-loading-indicator"
            />
            <!-- Masonry loading pill (loading more items from service) -->
            <div
                v-if="isMasonryLoading"
                class="flex items-center gap-1 px-1.5 py-0.5 rounded-full bg-smart-blue-500/20 text-smart-blue-300 text-[10px] font-medium shrink-0"
                :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                data-test="tab-masonry-loading-indicator"
            >
                <Loader2 :size="10" class="animate-spin" />
            </div>
        </div>
        <button
            v-show="!isMinimized"
            type="button"
            @click.stop="handleClose"
            class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none shrink-0"
            :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
            aria-label="Close tab"
        >
            <X :size="14" />
        </button>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>

