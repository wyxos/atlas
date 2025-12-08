<script setup lang="ts">
import { computed, inject } from 'vue';
import { SelectProvider } from './SelectContext';

interface Props {
    placeholder?: string;
}

const props = withDefaults(defineProps<Props>(), {
    placeholder: 'Select...',
});

const context = inject(SelectProvider);
if (!context) {
    throw new Error('SelectValue must be used within Select');
}

const displayValue = computed(() => context.value.modelValue || props.placeholder);
</script>

<template>
    <span class="truncate">{{ displayValue }}</span>
</template>

