<script setup lang="ts">
import { computed, inject } from 'vue';
import { SelectProvider } from './SelectContext';

interface Props {
    value: string;
    disabled?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    disabled: false,
});

const context = inject(SelectProvider);
if (!context) {
    throw new Error('SelectItem must be used within Select');
}

const isSelected = computed(() => context.value.modelValue === props.value);

function handleClick() {
    if (!props.disabled && !context.value.disabled) {
        context.value.onValueChange(props.value);
    }
}
</script>

<template>
    <div
        :class="[
            'relative flex w-full cursor-pointer select-none items-center rounded-sm px-2 py-1.5 text-sm outline-none',
            'hover:bg-smart-blue-500/20 focus:bg-smart-blue-500/20',
            isSelected ? 'bg-smart-blue-500/30 text-smart-blue-200' : 'text-twilight-indigo-100',
            props.disabled && 'pointer-events-none opacity-50',
        ]"
        @click="handleClick"
        data-test="select-item"
    >
        <slot />
    </div>
</template>

