<script setup lang="ts">
import { inject, computed, ref, watch } from 'vue';

const popoverOpen = inject<{ value: boolean } | { get: () => boolean; set: (value: boolean) => void }>('popoverOpen');
const setPopoverOpen = inject<(value: boolean) => void>('setPopoverOpen');
const triggerRef = inject<{ value: HTMLElement | null }>('popoverTriggerRef');

const isOpen = computed(() => {
    if (!popoverOpen) {
        return false;
    }
    if ('value' in popoverOpen) {
        return popoverOpen.value;
    }
    return popoverOpen.get();
});

let touchHandled = false;

function toggle(): void {
    setPopoverOpen?.(!isOpen.value);
}

function handleTouchEnd(event: TouchEvent): void {
    // Mark that we're handling a touch event
    touchHandled = true;
    toggle();
    // Reset after a short delay to allow click prevention
    setTimeout(() => {
        touchHandled = false;
    }, 400);
}

function handleClick(event: MouseEvent): void {
    // If we just handled a touch event, prevent the click from also firing
    if (touchHandled) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }
    toggle();
}

// Set the trigger ref when component mounts
const triggerElement = ref<HTMLElement | null>(null);

watch(triggerElement, (el) => {
    if (triggerRef && 'value' in triggerRef) {
        triggerRef.value = el;
    }
}, { immediate: true });
</script>

<template>
    <div 
        ref="triggerElement" 
        @click="handleClick" 
        @touchend="handleTouchEnd"
    >
        <slot />
    </div>
</template>

