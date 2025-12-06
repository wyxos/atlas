<script setup lang="ts">
import { computed } from 'vue';
import { PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

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

function toggleMinimize(): void {
    isMinimized.value = !isMinimized.value;
}
</script>

<template>
    <!-- Minimized Button (Always visible when minimized) -->
    <Transition name="fade">
        <Button
            v-if="isOpen && isMinimized"
            variant="ghost"
            size="icon"
            @click="toggleMinimize"
            aria-label="Open panel"
            class="absolute left-0 top-4 z-[60] text-twilight-indigo-100 bg-prussian-blue-900 border-r border-b border-t border-twilight-indigo-500 rounded-r-lg hover:bg-prussian-blue-800"
        >
            <PanelLeftOpen :size="20" />
        </Button>
    </Transition>
    
    <!-- Spacer when minimized to keep layout consistent -->
    <div v-if="isOpen && isMinimized" class="w-0 shrink-0"></div>

    <!-- Panel Content (Slides from left) -->
    <Transition name="slide-width">
        <div
            v-if="isOpen && !isMinimized"
            class="relative top-0 bottom-0 z-[60] w-72 bg-prussian-blue-900 border-r border-twilight-indigo-500 shadow-2xl overflow-hidden shrink-0"
        >
            <div class="flex flex-col h-full">
                <!-- Tab Header Bar -->
                <div class="flex items-center justify-between h-16 px-4 border-b border-twilight-indigo-500">
                    <span class="text-lg font-bold text-smart-blue-100">Tabs</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        @click="toggleMinimize"
                        aria-label="Minimize panel"
                        class="text-twilight-indigo-100 hover:text-smart-blue-100 hover:bg-twilight-indigo-500/20"
                    >
                        <PanelLeftClose :size="20" />
                    </Button>
                </div>
                <!-- Content -->
                <div class="flex-1 flex flex-col gap-2 overflow-y-auto p-4">
                    <slot name="tabs" />
                    <slot />
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.slide-width-enter-active {
    transition: width 0.3s ease-out, opacity 0.3s ease-out;
}

.slide-width-leave-active {
    transition: width 0.3s ease-in, opacity 0.3s ease-in;
}

.slide-width-enter-from {
    width: 0;
    opacity: 0;
}

.slide-width-enter-to {
    width: 18rem;
    opacity: 1;
}

.slide-width-leave-from {
    width: 18rem;
    opacity: 1;
}

.slide-width-leave-to {
    width: 0;
    opacity: 0;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>

