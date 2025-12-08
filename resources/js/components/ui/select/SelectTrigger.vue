<script setup lang="ts">
import { computed, inject } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { PopoverTrigger } from '../popover';
import { SelectProvider } from './SelectContext';

const context = inject(SelectProvider);
if (!context) {
    throw new Error('SelectTrigger must be used within Select');
}

const disabled = computed(() => context.value.disabled);
// Display value will be handled by SelectValue slot if provided
const displayValue = computed(() => context.value.modelValue || 'Select...');

</script>

<template>
    <PopoverTrigger as-child>
        <button
            type="button"
            :disabled="disabled"
            :class="[
                'flex h-9 w-full items-center justify-between rounded-md border border-twilight-indigo-500 bg-prussian-blue-600 px-3 py-2 text-sm text-twilight-indigo-100 shadow-sm transition-colors',
                'hover:bg-prussian-blue-700 focus:outline-none focus:ring-2 focus:ring-smart-blue-400 focus:ring-offset-2',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'dark:border-twilight-indigo-400 dark:bg-prussian-blue-700 dark:text-twilight-indigo-50',
            ]"
            data-test="select-trigger"
        >
            <slot>
                <span class="truncate">{{ displayValue }}</span>
            </slot>
            <ChevronDown :size="16" class="ml-2 h-4 w-4 opacity-50 transition-transform" :class="context.value?.open && 'rotate-180'" />
        </button>
    </PopoverTrigger>
</template>

