<script setup lang="ts">
import { inject, computed, ref, watch, nextTick } from 'vue';

interface Props {
    asChild?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    asChild: false,
});

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

function handleTouchEnd(): void {
    touchHandled = true;
    toggle();
    setTimeout(() => {
        touchHandled = false;
    }, 400);
}

function handleClick(event: MouseEvent): void {
    if (touchHandled) {
        event.preventDefault();
        event.stopPropagation();
        return;
    }
    toggle();
}

// Set the trigger ref when component mounts
const triggerElement = ref<HTMLElement | null>(null);
const wrapperRef = ref<HTMLElement | null>(null);

// For as-child, we need to find the actual child element
async function updateTriggerRef(): Promise<void> {
    if (!triggerRef || !('value' in triggerRef)) {
        return;
    }
    
    await nextTick();
    
    if (props.asChild) {
        // For as-child, find the first actual DOM element child
        if (wrapperRef.value) {
            const child = wrapperRef.value.firstElementChild as HTMLElement | null;
            if (child) {
                triggerRef.value = child;
                return;
            }
        }
        // Fallback: if no child found, use wrapper itself
        triggerRef.value = wrapperRef.value;
    } else {
        // For non-as-child, use the wrapper div
        triggerRef.value = triggerElement.value;
    }
}

watch([triggerElement, wrapperRef], updateTriggerRef, { immediate: true, flush: 'post' });
</script>

<template>
    <div 
        v-if="!asChild"
        ref="triggerElement" 
        @click="handleClick" 
        @touchend="handleTouchEnd"
    >
        <slot />
    </div>
    <span
        v-else
        ref="wrapperRef"
        @click="handleClick"
        @touchend="handleTouchEnd"
        style="display: inline-block;"
    >
        <slot />
    </span>
</template>
