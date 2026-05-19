<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    SearchableDropdownGroup,
    SearchableDropdownOption,
    SearchableDropdownValue,
} from '@/types/searchableDropdown';

const props = withDefaults(defineProps<{
    modelValue?: SearchableDropdownValue;
    options?: SearchableDropdownOption[];
    groups?: SearchableDropdownGroup[];
    disabled?: boolean;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyMessage?: string;
    triggerClass?: string;
    contentClass?: string;
    align?: 'start' | 'end';
    dataTest?: string;
}>(), {
    align: 'start',
    contentClass: '',
    dataTest: undefined,
    disabled: false,
    emptyMessage: 'No options found.',
    groups: undefined,
    options: undefined,
    placeholder: 'Select...',
    searchPlaceholder: 'Search...',
    triggerClass: 'w-full',
});

const emit = defineEmits<{
    'update:modelValue': [value: SearchableDropdownValue];
}>();

const open = ref(false);
const searchInput = ref<HTMLInputElement | null>(null);
const searchQuery = ref('');

const normalizedGroups = computed<SearchableDropdownGroup[]>(() => {
    if (props.groups && props.groups.length > 0) {
        return props.groups;
    }

    return [{ options: props.options ?? [] }];
});

const flatOptions = computed(() => normalizedGroups.value.flatMap((group) => group.options));
const selectedOption = computed(() => flatOptions.value.find((option) => valuesMatch(option.value, props.modelValue)) ?? null);
const selectedLabel = computed(() => selectedOption.value?.label ?? props.placeholder);
const normalizedSearch = computed(() => searchQuery.value.trim().toLowerCase());
const filteredGroups = computed<SearchableDropdownGroup[]>(() => {
    if (normalizedSearch.value === '') {
        return normalizedGroups.value;
    }

    return normalizedGroups.value
        .map((group) => ({
            ...group,
            options: group.options.filter((option) => {
                const label = option.label.toLowerCase();
                const value = String(option.value ?? '').toLowerCase();

                return label.includes(normalizedSearch.value) || value.includes(normalizedSearch.value);
            }),
        }))
        .filter((group) => group.options.length > 0);
});
const hasFilteredOptions = computed(() => filteredGroups.value.some((group) => group.options.length > 0));

watch(open, async (isOpen) => {
    if (!isOpen) {
        searchQuery.value = '';

        return;
    }

    await nextTick();
    searchInput.value?.focus();
});

function valuesMatch(left: SearchableDropdownValue | undefined, right: SearchableDropdownValue | undefined): boolean {
    return valueKey(left) === valueKey(right);
}

function valueKey(value: SearchableDropdownValue | undefined): string {
    if (value === undefined) {
        return 'undefined:';
    }

    if (value === null) {
        return 'null:';
    }

    return `${typeof value}:${String(value)}`;
}

function updateSearch(event: Event): void {
    searchQuery.value = (event.target as HTMLInputElement | null)?.value ?? '';
}

function selectOption(option: SearchableDropdownOption): void {
    if (option.disabled || props.disabled) {
        return;
    }

    emit('update:modelValue', option.value);
    open.value = false;
}

function badgeClass(option: SearchableDropdownOption): string {
    if (option.badgeVariant === 'danger') {
        return 'border-danger-400/40 bg-danger-500/15 text-danger-100';
    }

    if (option.badgeVariant === 'warning') {
        return 'border-yellow-300/40 bg-yellow-500/15 text-yellow-100';
    }

    return 'border-twilight-indigo-300/30 bg-prussian-blue-500/70 text-twilight-indigo-100';
}
</script>

<template>
    <Popover v-model="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                role="combobox"
                :aria-expanded="open"
                :disabled="disabled"
                :class="cn(buttonVariants({ variant: 'outline' }), 'justify-between px-3 text-left', triggerClass)"
                :data-test="dataTest"
            >
                <span class="min-w-0 truncate">{{ selectedLabel }}</span>
                <ChevronsUpDown :size="14" class="ml-2 shrink-0 opacity-70" />
            </button>
        </PopoverTrigger>

        <PopoverContent
            :align="align"
            :class="cn(
                'w-[var(--popover-trigger-width)] min-w-[var(--popover-trigger-width)] border-twilight-indigo-500 bg-prussian-blue-600 p-0 text-twilight-indigo-100',
                contentClass,
            )"
        >
            <div class="flex items-center gap-2 border-b border-twilight-indigo-500/70 px-3 py-2">
                <Search :size="14" class="shrink-0 text-twilight-indigo-300" />
                <input
                    ref="searchInput"
                    :value="searchQuery"
                    type="search"
                    class="h-8 min-w-0 flex-1 bg-transparent text-sm text-twilight-indigo-100 outline-none placeholder:text-twilight-indigo-400"
                    :placeholder="searchPlaceholder"
                    data-test="searchable-dropdown-search"
                    @input="updateSearch"
                    @keydown.stop
                >
            </div>

            <div class="max-h-72 overflow-y-auto p-1" role="listbox">
                <template v-for="group in filteredGroups" :key="group.label ?? 'ungrouped'">
                    <div
                        v-if="group.label"
                        class="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[0.14em] text-twilight-indigo-400/75"
                    >
                        {{ group.label }}
                    </div>

                    <button
                        v-for="option in group.options"
                        :key="valueKey(option.value)"
                        type="button"
                        role="option"
                        :aria-selected="valuesMatch(option.value, modelValue)"
                        :disabled="disabled || option.disabled"
                        class="flex min-h-9 w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm text-twilight-indigo-100 hover:bg-smart-blue-700/50 focus:bg-smart-blue-700/50 focus:outline-none disabled:pointer-events-none disabled:opacity-50"
                        data-test="searchable-dropdown-item"
                        @click="selectOption(option)"
                    >
                        <Check
                            :size="14"
                            :class="[
                                'shrink-0 text-smart-blue-200',
                                valuesMatch(option.value, modelValue) ? 'opacity-100' : 'opacity-0',
                            ]"
                        />
                        <span class="min-w-0 flex-1 truncate">{{ option.label }}</span>
                        <span
                            v-if="option.badge"
                            :class="[
                                'shrink-0 rounded-full border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.08em]',
                                badgeClass(option),
                            ]"
                        >
                            {{ option.badge }}
                        </span>
                    </button>
                </template>

                <p
                    v-if="!hasFilteredOptions"
                    class="px-3 py-6 text-center text-sm text-twilight-indigo-300"
                    data-test="searchable-dropdown-empty"
                >
                    {{ emptyMessage }}
                </p>
            </div>
        </PopoverContent>
    </Popover>
</template>
