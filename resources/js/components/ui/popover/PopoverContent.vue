<script setup lang="ts">
// Teleport is used in template but linter doesn't recognize template usage
// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { inject, onMounted, onUnmounted, watch, computed, ref, Teleport, useAttrs } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    align?: 'start' | 'end';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    align: 'start',
    class: '',
});

defineOptions({
    inheritAttrs: false,
});

const attrs = useAttrs();

const popoverOpen = inject<{ value: boolean } | { get: () => boolean; set: (value: boolean) => void }>('popoverOpen');
const setPopoverOpen = inject<(value: boolean) => void>('setPopoverOpen');

const isOpen = computed(() => {
    if (!popoverOpen) {
        return false;
    }
    if ('value' in popoverOpen) {
        return popoverOpen.value;
    }
    return popoverOpen.get();
});

const contentRef = ref<HTMLElement | null>(null);
const triggerRef = inject<{ value: HTMLElement | null } | undefined>('popoverTriggerRef', undefined);
const popoverStyle = ref<{ top?: string; left?: string; right?: string }>({});
const mouseDownHandlerRef = ref<((e: MouseEvent) => void) | null>(null);
const touchEndHandlerRef = ref<((e: TouchEvent) => void) | null>(null);

function handleClickOutside(event: MouseEvent | TouchEvent): void {
    // Don't process if popover is already closed
    if (!isOpen.value) {
        return;
    }
    
    const target = event.target as Node | null;
    if (!target) {
        return;
    }
    
    const clickedInsideContent = contentRef.value?.contains(target);
    const clickedInsideTrigger = triggerRef && 'value' in triggerRef ? triggerRef.value?.contains(target) : false;
    
    // Close if clicked outside both content and trigger
    // Use setTimeout to ensure this runs after other click handlers (like trigger toggle)
    if (!clickedInsideContent && !clickedInsideTrigger) {
        // Use a small delay to allow trigger toggle to run first
        setTimeout(() => {
            // Double-check that popover is still open before closing
            // This prevents race conditions with trigger toggle
            if (isOpen.value) {
                setPopoverOpen?.(false);
            }
        }, 1);
    }
}

function updatePosition(): void {
    if (!isOpen.value || !contentRef.value) {
        return;
    }
    
    const trigger = triggerRef && 'value' in triggerRef ? triggerRef.value : null;
    if (!trigger) {
        return;
    }
    
    const triggerRect = trigger.getBoundingClientRect();
    const contentRect = contentRef.value.getBoundingClientRect();
    const align = props.align === 'start' ? 'left' : 'right';
    
    let top = triggerRect.bottom + 8;
    let left: number | undefined;
    let right: number | undefined;
    
    if (align === 'left') {
        left = triggerRect.left;
        // Ensure it doesn't go off screen
        if (left + contentRect.width > window.innerWidth) {
            left = window.innerWidth - contentRect.width - 16;
        }
        if (left < 16) {
            left = 16;
        }
    } else {
        right = window.innerWidth - triggerRect.right;
        // Ensure it doesn't go off screen
        if (right + contentRect.width > window.innerWidth) {
            right = window.innerWidth - contentRect.width - 16;
        }
        if (right < 16) {
            right = 16;
        }
    }
    
    // Ensure it doesn't go off bottom of screen
    if (top + contentRect.height > window.innerHeight) {
        top = triggerRect.top - contentRect.height - 8;
        if (top < 16) {
            top = 16;
        }
    }
    
    popoverStyle.value = {
        top: `${top}px`,
        ...(left !== undefined ? { left: `${left}px` } : {}),
        ...(right !== undefined ? { right: `${right}px` } : {}),
    };
}

watch(isOpen, (isOpenValue) => {
    if (isOpenValue) {
        // Attach listeners immediately when popover opens
        // Use mousedown instead of click to catch events earlier
        // This ensures we catch clicks even if stopPropagation is called
        const handleMouseDown = (e: MouseEvent) => {
            handleClickOutside(e);
        };
        const handleTouchEnd = (e: TouchEvent) => {
            handleClickOutside(e);
        };
        
        // Store handlers so we can remove them later
        mouseDownHandlerRef.value = handleMouseDown;
        touchEndHandlerRef.value = handleTouchEnd;
        
        document.addEventListener('mousedown', handleMouseDown);
        document.addEventListener('touchend', handleTouchEnd);
        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
    } else {
        // Remove stored handlers
        if (mouseDownHandlerRef.value) {
            document.removeEventListener('mousedown', mouseDownHandlerRef.value);
            mouseDownHandlerRef.value = null;
        }
        if (touchEndHandlerRef.value) {
            document.removeEventListener('touchend', touchEndHandlerRef.value);
            touchEndHandlerRef.value = null;
        }
        window.removeEventListener('resize', updatePosition);
        window.removeEventListener('scroll', updatePosition, true);
    }
});

watch(() => [isOpen.value, triggerRef && 'value' in triggerRef ? triggerRef.value : null], () => {
    if (isOpen.value) {
        setTimeout(updatePosition, 100);
    }
}, { deep: true });

onMounted(() => {
    if (isOpen.value) {
        const handleMouseDown = (e: MouseEvent) => {
            handleClickOutside(e);
        };
        const handleTouchEnd = (e: TouchEvent) => {
            handleClickOutside(e);
        };
        
        mouseDownHandlerRef.value = handleMouseDown;
        touchEndHandlerRef.value = handleTouchEnd;
        
        document.addEventListener('mousedown', handleMouseDown);
        document.addEventListener('touchend', handleTouchEnd);
        updatePosition();
        window.addEventListener('resize', updatePosition);
        window.addEventListener('scroll', updatePosition, true);
    }
});

onUnmounted(() => {
    if (mouseDownHandlerRef.value) {
        document.removeEventListener('mousedown', mouseDownHandlerRef.value);
        mouseDownHandlerRef.value = null;
    }
    if (touchEndHandlerRef.value) {
        document.removeEventListener('touchend', touchEndHandlerRef.value);
        touchEndHandlerRef.value = null;
    }
    window.removeEventListener('resize', updatePosition);
    window.removeEventListener('scroll', updatePosition, true);
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 translate-y-1"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-150"
            leave-from-class="opacity-100 translate-y-0"
            leave-to-class="opacity-0 translate-y-1"
        >
            <div
                v-if="isOpen"
                ref="contentRef"
                :class="cn(
                    'fixed z-[100] min-w-[8rem] max-w-[90vw] rounded-lg border-2 border-twilight-indigo-500 bg-prussian-blue-600 p-1 shadow-lg pointer-events-auto',
                    props.class
                )"
                v-bind="attrs"
                :style="popoverStyle"
                @click.stop
                @touchend.stop
                @mousedown.stop
                @mouseup.stop
            >
                <slot />
            </div>
        </Transition>
    </Teleport>
</template>
