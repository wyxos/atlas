<template>
    <div class="flex flex-wrap items-center gap-4">
        <!-- Container Dropdown -->
        <div class="flex items-center gap-2">
            <label class="text-sm font-medium">Container:</label>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button class="min-w-[120px] justify-between" variant="outline">
                        {{ currentContainerLabel }}
                        <ChevronDown class="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem
                        v-for="option in containerOptions"
                        :key="option.value"
                        :class="{ 'bg-accent': filters.container === option.value }"
                        class="cursor-pointer"
                        @click="$emit('containerChange', option.value)"
                    >
                        {{ option.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Sort Dropdown -->
        <div class="flex items-center gap-2">
            <label class="text-sm font-medium">Sort:</label>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button class="min-w-[140px] justify-between" variant="outline">
                        {{ currentSortLabel }}
                        <ChevronDown class="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem
                        v-for="option in sortOptions"
                        :key="option.value"
                        :class="{ 'bg-accent': filters.sort === option.value }"
                        class="cursor-pointer"
                        @click="$emit('sortChange', option.value)"
                    >
                        {{ option.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Period Dropdown -->
        <div class="flex items-center gap-2">
            <label class="text-sm font-medium">Period:</label>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button class="min-w-[100px] justify-between" variant="outline">
                        {{ currentPeriodLabel }}
                        <ChevronDown class="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem
                        v-for="option in periodOptions"
                        :key="option.value"
                        :class="{ 'bg-accent': filters.period === option.value }"
                        class="cursor-pointer"
                        @click="$emit('periodChange', option.value)"
                    >
                        {{ option.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- Limit Dropdown -->
        <div class="flex items-center gap-2">
            <label class="text-sm font-medium">Limit:</label>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button class="min-w-[80px] justify-between" variant="outline">
                        {{ currentLimitLabel }}
                        <ChevronDown class="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                    <DropdownMenuItem
                        v-for="option in limitOptions"
                        :key="option.value"
                        :class="{ 'bg-accent': filters.limit === option.value }"
                        class="cursor-pointer"
                        @click="$emit('limitChange', option.value)"
                    >
                        {{ option.label }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>

        <!-- NSFW Checkbox -->
        <div class="flex items-center gap-2">
            <Checkbox :id="'nsfw-checkbox'" :model-value="filters.nsfw" @update:model-value="$emit('nsfwChange', $event)" />
            <label class="cursor-pointer text-sm font-medium" for="nsfw-checkbox">Show NSFW</label>
        </div>

        <!-- Auto Next Checkbox -->
        <div class="flex items-center gap-2">
            <Checkbox :id="'auto-next-checkbox'" :model-value="filters.autoNext" @update:model-value="$emit('autoNextChange', $event)" />
            <label class="cursor-pointer text-sm font-medium" for="auto-next-checkbox">Auto Next</label>
        </div>

        <!-- Back to First Page Button -->
        <Button variant="outline" @click="$emit('backToFirst')">Back to First</Button>

        <!-- Undo Blacklist Button -->
        <Button variant="outline" @click="$emit('undoBlacklist')">
            <Undo class="mr-2 h-4 w-4" />
            Undo Blacklist
        </Button>

        <!-- Next Button -->
        <Button @click="$emit('loadNext')">Next+</Button>
    </div>
</template>

<script lang="ts" setup>
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { CONTAINER_OPTIONS, LIMIT_OPTIONS, PERIOD_OPTIONS, SORT_OPTIONS } from '@/constants/browse';
import type { BrowseFilters } from '@/types/browse';
import { ChevronDown, Undo } from 'lucide-vue-next';
import { computed } from 'vue';

interface Props {
    filters: BrowseFilters;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    containerChange: [value: string];
    sortChange: [value: string];
    periodChange: [value: string];
    limitChange: [value: number];
    nsfwChange: [value: boolean];
    autoNextChange: [value: boolean];
    loadNext: [];
    backToFirst: [];
    undoBlacklist: [];
}>();

const containerOptions = CONTAINER_OPTIONS;
const sortOptions = SORT_OPTIONS;
const periodOptions = PERIOD_OPTIONS;
const limitOptions = LIMIT_OPTIONS;

const currentSortLabel = computed(() => {
    return sortOptions.find((option) => option.value === props.filters.sort)?.label || props.filters.sort;
});

const currentPeriodLabel = computed(() => {
    return periodOptions.find((option) => option.value === props.filters.period)?.label || props.filters.period;
});

const currentContainerLabel = computed(() => {
    return containerOptions.find((option) => option.value === props.filters.container)?.label || props.filters.container;
});

const currentLimitLabel = computed(() => {
    return limitOptions.find((option) => option.value === props.filters.limit)?.label || props.filters.limit.toString();
});
</script>
