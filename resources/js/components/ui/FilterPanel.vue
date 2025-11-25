<script setup lang="ts">
// Teleport is used in template but linter doesn't recognize template usage
// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { computed, watch, Teleport } from 'vue';
import { X } from 'lucide-vue-next';
import Button from './Button.vue';

interface Props {
    modelValue?: boolean;
    title?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    title: 'Filters',
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
    'apply': [];
    'reset': [];
}>();

const isOpen = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value),
});

function close(): void {
    isOpen.value = false;
}

function handleBackdropClick(event: MouseEvent): void {
    if (event.target === event.currentTarget) {
        close();
    }
}

function handleEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape' && isOpen.value) {
        close();
    }
}

watch(isOpen, (isOpenValue) => {
    if (isOpenValue) {
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', handleEscape);
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', handleEscape);
    }
});
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-300"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="fixed inset-0 z-50 bg-black/50"
                @click="handleBackdropClick"
            />
        </Transition>

        <!-- Panel -->
        <Transition name="slide-right">
            <div
                v-if="isOpen"
                class="fixed top-0 right-0 z-[60] w-96 max-w-[90vw] md:w-[32rem] lg:w-[36rem] xl:w-[40rem] h-full bg-prussian-blue-100 border-l-2 border-twilight-indigo-500 shadow-2xl overflow-y-auto"
                @click.stop
            >
                    <div class="flex flex-col h-full">
                        <!-- Header -->
                        <div class="flex items-center justify-between p-6 border-b-2 border-twilight-indigo-500">
                            <h2 class="text-2xl font-semibold text-regal-navy-900">
                                {{ title }}
                            </h2>
                            <button
                                @click="close"
                                class="p-2 rounded-lg transition-colors hover:bg-smart-blue-300 focus:outline-none focus:ring-2 focus:ring-smart-blue-500 focus:ring-offset-2"
                                aria-label="Close filters"
                            >
                                <X class="h-5 w-5 text-twilight-indigo-900" />
                            </button>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 p-6">
                            <slot />
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-end gap-4 p-6 border-t-2 border-twilight-indigo-500">
                            <Button
                                variant="outline"
                                @click="$emit('reset')"
                                class="border-twilight-indigo-500 text-twilight-indigo-900 hover:bg-smart-blue-300 hover:border-smart-blue-600 hover:text-smart-blue-900"
                            >
                                Reset
                            </Button>
                            <Button
                                variant="default"
                                @click="$emit('apply')"
                                class="bg-smart-blue-500 hover:bg-smart-blue-600 text-white"
                            >
                                Apply Filters
                            </Button>
                        </div>
                    </div>
                </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.slide-right-enter-active {
    transition: transform 0.3s ease-out;
}

.slide-right-leave-active {
    transition: transform 0.3s ease-in;
}

.slide-right-enter-from {
    transform: translateX(100%);
}

.slide-right-enter-to {
    transform: translateX(0);
}

.slide-right-leave-from {
    transform: translateX(0);
}

.slide-right-leave-to {
    transform: translateX(100%);
}
</style>

