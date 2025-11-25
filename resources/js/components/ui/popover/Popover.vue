<script setup lang="ts">
import { provide, computed, ref } from 'vue';

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

const triggerRef = ref<HTMLElement | null>(null);

provide('popoverOpen', open);
provide('setPopoverOpen', setOpen);
provide('popoverTriggerRef', triggerRef);
</script>

<template>
    <slot />
</template>

