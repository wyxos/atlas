<script setup lang="ts">
import { computed } from 'vue';
import { Switch } from '@/components/ui/switch';

interface Props {
    modelValue?: boolean; // isOpen
    isMinimized?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: true,
    isMinimized: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    'update:isMinimized': [value: boolean];
}>();

const isOpen = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value),
});

const isMinimized = computed({
    get: () => props.isMinimized,
    set: (value: boolean) => emit('update:isMinimized', value),
});

// Inverted switch state: switch is "on" when panel is expanded (not minimized)
const switchState = computed({
    get: () => !props.isMinimized,
    set: (value: boolean) => emit('update:isMinimized', !value),
});
</script>

<template>
    <!-- Panel Content (Always rendered, transitions width like navigation menu) -->
    <div :class="[
        'absolute lg:relative top-0 bottom-0 z-60 bg-prussian-blue-900 border-r border-twilight-indigo-500 shadow-2xl shrink-0 transition-all duration-300 ease-in-out overflow-hidden flex flex-col',
        isOpen && !isMinimized ? 'w-72 translate-x-0' : 'w-72 -translate-x-full lg:w-16 lg:translate-x-0'
    ]">
        <!-- Switch Button (Centered at top) -->
        <div class="flex justify-center pt-4">
            <Switch v-model="switchState" :aria-label="isMinimized ? 'Expand panel' : 'Minimize panel'" />
        </div>

        <!-- Tabs List (Scrollable) -->
        <div class="flex-1 flex flex-col gap-2 overflow-y-auto py-4 px-2">
            <slot name="tabs" :isMinimized="isMinimized" />
        </div>

        <!-- New Tab Button (Fixed at bottom, always visible) -->
        <div class="p-4">
            <slot name="footer" :isMinimized="isMinimized" />
        </div>
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
