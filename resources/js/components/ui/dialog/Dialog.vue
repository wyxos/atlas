<script setup lang="ts">
import { provide, computed } from 'vue';

interface Props {
    modelValue?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

const open = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value),
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

