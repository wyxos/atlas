<script setup lang="ts">
import { X, Loader2 } from 'lucide-vue-next';
import type { Listing } from '../lib/Listing';
import { computed } from 'vue';

interface Props {
    listing: Listing<Record<string, unknown>>;
}

const props = defineProps<Props>();

const isRemovingFilter = computed(() => (key: string) => props.listing.removingFilterKey === key);
const isAnyFilterRemoving = computed(() => props.listing.removingFilterKey !== null);
</script>

<template>
    <div v-if="listing.activeFilters.length > 0" class="mb-6 flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-twilight-indigo-300">Active filters:</span>
        <span v-for="filter in listing.activeFilters" :key="filter.key"
            class="inline-flex items-stretch rounded overflow-hidden border border-smart-blue-500">
            <span
                class="px-3 py-1 text-xs font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">{{
                    filter.label }}</span>
            <span
                class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-smart-blue-500 hover:bg-prussian-blue-600 transition-colors truncate max-w-xs">{{
                    filter.value }}</span>
            <button type="button" @click="() => listing.removeFilter(filter.key)"
                :disabled="isRemovingFilter(filter.key) || isAnyFilterRemoving"
                :aria-label="`Remove ${filter.label} filter`"
                class="px-2 text-xs font-bold border-l border-smart-blue-500 bg-transparent text-twilight-indigo-300 hover:bg-smart-blue-600/40 hover:text-twilight-indigo-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                <Loader2 v-if="isRemovingFilter(filter.key)" :size="12" class="animate-spin" />
                <X v-else :size="12" />
            </button>
        </span>
        <button type="button" @click="() => listing.resetFilters()"
            :disabled="isAnyFilterRemoving || listing.isResetting"
            class="inline-flex items-center rounded px-3 py-1 text-xs font-medium bg-danger-600 text-white border border-danger-500 hover:bg-danger-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
            Clear
        </button>
    </div>
</template>
