<script setup lang="ts">
import { X } from 'lucide-vue-next';

interface Props {
    id: number | string;
    label: string;
    isActive?: boolean;
}

withDefaults(defineProps<Props>(), {
    isActive: false,
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
            'group flex items-center justify-between w-full px-4 py-1 rounded transition-all cursor-pointer border border-transparent select-none',
            isActive
                ? 'bg-smart-blue-600 text-white border-smart-blue-500 shadow-md'
                : 'text-twilight-indigo-300 hover:bg-prussian-blue-800 hover:text-twilight-indigo-100 hover:border-twilight-indigo-500/30',
        ]"
        role="button"
        tabindex="0"
        @keydown.enter="handleClick"
        @keydown.space.prevent="handleClick"
    >
        <span class="text-sm font-medium truncate flex-1 pr-2">{{ label }}</span>
        <button
            type="button"
            @click.stop="handleClose"
            class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none"
            aria-label="Close tab"
        >
            <X :size="14" />
        </button>
    </div>
</template>

