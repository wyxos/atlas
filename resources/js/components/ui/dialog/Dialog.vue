<script setup lang="ts">
import { provide, computed, ref } from 'vue';

interface Props {
    open?: boolean;
    modelValue?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    open: undefined,
    modelValue: undefined,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:modelValue': [value: boolean];
}>();

// Internal state for uncontrolled usage
const internalOpen = ref(false);

// Support both v-model:open and v-model (modelValue)
// Priority: open > modelValue > internal
const dialogOpen = computed({
    get: () => {
        if (props.open !== undefined) {
            return props.open;
        }
        if (props.modelValue !== undefined) {
            return props.modelValue;
        }
        return internalOpen.value;
    },
    set: (value: boolean) => {
        if (props.open !== undefined) {
            emit('update:open', value);
        } else if (props.modelValue !== undefined) {
            emit('update:modelValue', value);
        } else {
            internalOpen.value = value;
        }
    },
});

function setDialogOpen(value: boolean): void {
    dialogOpen.value = value;
}

// Provide to DialogContent and other children
provide('dialogOpen', dialogOpen);
provide('setDialogOpen', setDialogOpen);
</script>

<template>
    <slot />
</template>
