<script setup lang="ts">
import { X, FileText } from 'lucide-vue-next';

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
            'group flex items-center justify-between w-full px-3 py-2 rounded-lg transition-all cursor-pointer border border-transparent select-none',
            isActive
                ? 'bg-smart-blue-600 text-white border-smart-blue-500 shadow-md'
                : 'text-twilight-indigo-100 hover:bg-smart-blue-700/50 hover:text-white',
        ]"
        role="button"
        tabindex="0"
        @keydown.enter="handleClick"
        @keydown.space.prevent="handleClick"
    >
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <FileText class="w-5 h-5 flex-shrink-0" />
            <Transition name="fade">
                <span v-if="!isMinimized" class="text-sm font-medium whitespace-nowrap truncate">
                    {{ label }}
                </span>
            </Transition>
        </div>
        <Transition name="fade">
            <button
                v-if="!isMinimized"
                type="button"
                @click.stop="handleClose"
                class="flex items-center justify-center h-6 w-6 rounded text-current opacity-100 md:opacity-0 md:group-hover:opacity-100 transition-all hover:bg-black/20 focus:opacity-100 focus:outline-none flex-shrink-0"
                aria-label="Close tab"
            >
                <X :size="14" />
            </button>
        </Transition>
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

