<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { Button } from '@/components/ui/button';

const isOpen = ref(false);
const triggerRef = ref<HTMLElement | null>(null);
const menuRef = ref<HTMLElement | null>(null);

function toggle(): void {
    isOpen.value = !isOpen.value;
}

function handleClickOutside(event: MouseEvent): void {
    if (
        menuRef.value &&
        triggerRef.value &&
        !menuRef.value.contains(event.target as Node) &&
        !triggerRef.value.contains(event.target as Node)
    ) {
        isOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="relative inline-block text-left">
        <Button
            ref="triggerRef"
            variant="ghost"
            size="sm"
            @click="toggle"
            class="inline-flex items-center gap-2 cursor-pointer text-smart-blue-100"
        >
            <slot name="trigger" />
        </Button>
        
        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div
                v-if="isOpen"
                ref="menuRef"
                class="absolute right-0 mt-2 w-56 rounded-lg shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none z-50 bg-prussian-blue-400 border border-twilight-indigo-500"
            >
                <div class="py-1">
                    <slot />
                </div>
            </div>
        </Transition>
    </div>
</template>
