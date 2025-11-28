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

provide('dialogOpen', open);
provide('setDialogOpen', setOpen);
</script>

<template>
    <slot />
</template>
