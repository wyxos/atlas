<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    formatLocalSourceSelectionLabel,
    isLocalSourceSelected,
    normalizeLocalSourceOptions,
    toggleLocalSourceSelection,
    type LocalSourceSelection,
} from '@/utils/localSourceSelection';
import type { ServiceFilterOption } from '@/lib/browseCatalog';

const props = withDefaults(defineProps<{
    modelValue: LocalSourceSelection;
    options: ServiceFilterOption[];
    disabled?: boolean;
    placeholder?: string;
    triggerClass?: string;
    contentClass?: string;
    align?: 'start' | 'center' | 'end';
}>(), {
    align: 'start',
    contentClass: '',
    disabled: false,
    placeholder: 'Select sources...',
    triggerClass: 'w-full',
});

const emit = defineEmits<{
    'update:modelValue': [value: string[]];
}>();

const sourceOptions = computed(() => normalizeLocalSourceOptions(props.options));
const selectionLabel = computed(() => formatLocalSourceSelectionLabel(
    props.modelValue,
    sourceOptions.value,
    props.placeholder,
));

function updateSource(value: string, checked: boolean): void {
    emit('update:modelValue', toggleLocalSourceSelection(props.modelValue, value, checked));
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="outline"
                :disabled="disabled"
                :class="['justify-between', triggerClass]"
                data-test="source-select-trigger"
            >
                <span class="min-w-0 truncate text-left">{{ selectionLabel }}</span>
                <ChevronDown :size="14" class="shrink-0 opacity-70" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            :align="align"
            :class="[
                'w-64 border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100',
                contentClass,
            ]"
        >
            <DropdownMenuLabel class="text-smart-blue-100">
                Sources
            </DropdownMenuLabel>
            <DropdownMenuSeparator class="bg-twilight-indigo-500" />
            <DropdownMenuCheckboxItem
                v-for="option in sourceOptions"
                :key="option.value"
                :model-value="isLocalSourceSelected(modelValue, option.value)"
                :disabled="disabled"
                class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                data-test="source-select-item"
                @select.prevent
                @update:model-value="(checked) => updateSource(option.value, checked)"
            >
                <span class="truncate">{{ option.label }}</span>
            </DropdownMenuCheckboxItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
