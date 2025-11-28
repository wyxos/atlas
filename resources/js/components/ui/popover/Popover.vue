<script setup lang="ts">
import { provide, computed, ref } from 'vue';

interface Props {
    modelValue?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: undefined,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

// Internal state for uncontrolled usage
const internalOpen = ref(false);

// Use modelValue if provided (controlled), otherwise use internal state (uncontrolled)
const open = computed({
    get: () => props.modelValue !== undefined ? props.modelValue : internalOpen.value,
    set: (value: boolean) => {
        if (props.modelValue !== undefined) {
            emit('update:modelValue', value);
        } else {
            internalOpen.value = value;
        }
    },
});

function setOpen(value: boolean): void {
    open.value = value;
}

function close(): void {
    setOpen(false);
}

const triggerRef = ref<HTMLElement | null>(null);

provide('popoverOpen', open);
provide('setPopoverOpen', setOpen);
provide('popoverClose', close);
provide('popoverTriggerRef', triggerRef);
</script>

<template>
    <slot :close="close" />
</template>
