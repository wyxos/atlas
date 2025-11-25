<script setup lang="ts">
// Teleport is used in template but linter doesn't recognize template usage
// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { inject, onMounted, onUnmounted, watch, computed, ref, Teleport } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    align?: 'start' | 'end';
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    align: 'start',
    class: '',
});

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

function handleClickOutside(event: MouseEvent | TouchEvent): void {
    const target = event.target as Node;
    const clickedInsideContent = contentRef.value?.contains(target);
    const clickedInsideTrigger = triggerRef && 'value' in triggerRef ? triggerRef.value?.contains(target) : false;
    
    if (!clickedInsideContent && !clickedInsideTrigger) {
        setPopoverOpen?.(false);
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
        setTimeout(() => {
            document.addEventListener('click', handleClickOutside);
            document.addEventListener('touchend', handleClickOutside);
            updatePosition();
            window.addEventListener('resize', updatePosition);
            window.addEventListener('scroll', updatePosition, true);
        }, 0);
    } else {
        document.removeEventListener('click', handleClickOutside);
        document.removeEventListener('touchend', handleClickOutside);
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
        setTimeout(() => {
            document.addEventListener('click', handleClickOutside);
            document.addEventListener('touchend', handleClickOutside);
            updatePosition();
            window.addEventListener('resize', updatePosition);
            window.addEventListener('scroll', updatePosition, true);
        }, 0);
    }
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    document.removeEventListener('touchend', handleClickOutside);
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
                    'fixed z-50 min-w-[8rem] max-w-[90vw] rounded-lg border-2 border-twilight-indigo-500 bg-prussian-blue-600 p-1 shadow-lg',
                    props.class
                )"
                :style="popoverStyle"
                @click.stop
                @touchend.stop
            >
                <slot />
            </div>
        </Transition>
    </Teleport>
</template>
