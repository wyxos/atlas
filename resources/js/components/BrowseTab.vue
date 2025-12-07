<script setup lang="ts">
import { X, Layers } from 'lucide-vue-next';

interface Props {
    id: number | string;
    label: string;
    isActive?: boolean;
    isMinimized?: boolean;
}

withDefaults(defineProps<Props>(), {
    isActive: false,
    isMinimized: false,
});

const emit = defineEmits<{
    click: [];
    close: [];
}>();

function handleClick(): void {
    emit('click');
}

function handleClose(event: MouseEvent): void {
    event.stopPropagation();
    emit('close');
}
</script>

<template>
    <div
        @click="handleClick"
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

