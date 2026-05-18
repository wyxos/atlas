<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
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

const open = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');

const sourceOptions = computed(() => normalizeLocalSourceOptions(props.options));
const selectionLabel = computed(() => formatLocalSourceSelectionLabel(
    props.modelValue,
    sourceOptions.value,
    props.placeholder,
));
const popoverAlign = computed(() => props.align === 'end' ? 'end' : 'start');
const normalizedSearchQuery = computed(() => searchQuery.value.trim().toLowerCase());
const filteredSourceOptions = computed(() => {
    if (normalizedSearchQuery.value === '') {
        return sourceOptions.value;
    }

    return sourceOptions.value.filter((option) => {
        const label = option.label.toLowerCase();
        const value = option.value.toLowerCase();

        return label.includes(normalizedSearchQuery.value) || value.includes(normalizedSearchQuery.value);
    });
});

watch(open, async (isOpen) => {
    if (!isOpen) {
        searchQuery.value = '';

        return;
    }

    await nextTick();
    searchInput.value?.focus();
});

function updateSearch(event: Event): void {
    searchQuery.value = (event.target as HTMLInputElement | null)?.value ?? '';
}

function updateSource(value: string): void {
    const checked = !isLocalSourceSelected(props.modelValue, value);

    emit('update:modelValue', toggleLocalSourceSelection(props.modelValue, value, checked));
}
</script>

<template>
    <Popover v-model="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                aria-label="Select library sources"
                :disabled="disabled"
                :class="['justify-between', triggerClass]"
                data-test="source-select-trigger"
            >
                <span class="min-w-0 truncate text-left">{{ selectionLabel }}</span>
                <ChevronsUpDown :size="14" class="shrink-0 opacity-70" />
            </Button>
        </PopoverTrigger>
        <PopoverContent
            :align="popoverAlign"
            :class="[
                'w-72 border-twilight-indigo-500 bg-prussian-blue-600 p-0 text-twilight-indigo-100',
                contentClass,
            ]"
        >
            <div class="flex items-center gap-2 border-b border-twilight-indigo-500/70 px-3 py-2">
                <Search :size="14" class="shrink-0 text-twilight-indigo-300" />
                <input
                    ref="searchInput"
                    :value="searchQuery"
                    type="search"
                    class="h-8 min-w-0 flex-1 bg-transparent text-sm text-twilight-indigo-100 outline-none placeholder:text-twilight-indigo-400"
                    placeholder="Search sources..."
                    data-test="source-select-search"
                    @input="updateSearch"
                    @keydown.stop
                >
            </div>
            <div
                class="max-h-72 overflow-y-auto p-1"
                role="listbox"
                aria-multiselectable="true"
            >
                <button
                    v-for="option in filteredSourceOptions"
                    :key="option.value"
                    type="button"
                    role="option"
                    :aria-selected="isLocalSourceSelected(modelValue, option.value)"
                    :disabled="disabled"
                    class="flex h-9 w-full items-center gap-2 rounded px-2 text-left text-sm text-twilight-indigo-100 hover:bg-smart-blue-700/50 focus:bg-smart-blue-700/50 focus:outline-none disabled:pointer-events-none disabled:opacity-50"
                    data-test="source-select-item"
                    @click="updateSource(option.value)"
                >
                    <Check
                        :size="14"
                        :class="[
                            'shrink-0 text-smart-blue-200',
                            isLocalSourceSelected(modelValue, option.value) ? 'opacity-100' : 'opacity-0',
                        ]"
                    />
                    <span class="min-w-0 truncate">{{ option.label }}</span>
                </button>
                <p
                    v-if="filteredSourceOptions.length === 0"
                    class="px-3 py-6 text-center text-sm text-twilight-indigo-300"
                    data-test="source-select-empty"
                >
                    No sources found.
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
