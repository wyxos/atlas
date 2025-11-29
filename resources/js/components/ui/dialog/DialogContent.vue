<script setup lang="ts">
// Teleport is used in template but linter doesn't recognize template usage
// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { inject, onMounted, onUnmounted, watch, computed, Teleport } from 'vue';
import { cn } from '@/lib/utils';
import { X } from 'lucide-vue-next';

interface Props {
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    class: '',
});

const dialogOpen = inject<{ value: boolean } | { get: () => boolean; set: (value: boolean) => void }>('dialogOpen');
const setDialogOpen = inject<(value: boolean) => void>('setDialogOpen');

const isOpen = computed(() => {
    if (!dialogOpen) {
        return false;
    }
    if ('value' in dialogOpen) {
        return dialogOpen.value;
    }
    return dialogOpen.get();
});

function handleEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isOpen.value) {
        setDialogOpen?.(false);
    }
}

function handleBackdropClick(event: MouseEvent): void {
    if (event.target === event.currentTarget) {
        setDialogOpen?.(false);
    }
}

watch(isOpen, (isOpenValue) => {
    if (isOpenValue) {
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = '';
    }
});

onMounted(() => {
    document.addEventListener('keydown', handleEscape);
    if (isOpen.value) {
        document.body.style.overflow = 'hidden';
    }
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-50 flex items-center justify-center"
            >
                <!-- Backdrop -->
                <div 
                    class="fixed inset-0 bg-black/50 -z-10" 
                    @click="handleBackdropClick"
                />

                <!-- Dialog Content -->
                <Transition
                    enter-active-class="transition-all duration-200"
                    enter-from-class="opacity-0 scale-95"
                    enter-to-class="opacity-100 scale-100"
                    leave-active-class="transition-all duration-200"
                    leave-from-class="opacity-100 scale-100"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-if="isOpen"
                        :class="cn(
                            'relative z-50 w-full max-w-lg rounded-lg border border-twilight-indigo-500 bg-prussian-blue-600 p-6 shadow-lg focus:outline-none',
                            props.class
                        )"
                        @click.stop
                    >
                        <button
                            v-if="setDialogOpen"
                            @click="setDialogOpen(false)"
                            class="absolute right-4 top-4 rounded-sm opacity-70 ring-offset-background transition-opacity hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-smart-blue-500 focus:ring-offset-2 disabled:pointer-events-none"
                        >
                            <X :size="16" class="text-twilight-indigo-100" />
                            <span class="sr-only">Close</span>
                        </button>
                        <slot />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

