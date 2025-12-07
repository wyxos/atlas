<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

interface Props {
    modelValue?: boolean;
    disabled?: boolean;
    class?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: false,
    disabled: false,
});

const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

const isChecked = computed({
    get: () => props.modelValue,
    set: (value: boolean) => emit('update:modelValue', value),
});

function toggle(): void {
    if (!props.disabled) {
        isChecked.value = !isChecked.value;
    }
}
</script>

<template>
    <button type="button" role="switch" :aria-checked="isChecked" :disabled="disabled" :class="cn(
        'peer inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-smart-blue-400 focus-visible:ring-offset-2 focus-visible:ring-offset-prussian-blue-800 disabled:cursor-not-allowed disabled:opacity-50',
        isChecked ? 'bg-smart-blue-600' : 'bg-twilight-indigo-500',
        props.class
    )" @click="toggle">
        <span :class="cn(
            'pointer-events-none block h-5 w-5 rounded-full bg-white shadow-lg ring-0 transition-transform',
            isChecked ? 'translate-x-5' : 'translate-x-0'
        )" />
    </button>
</template>
