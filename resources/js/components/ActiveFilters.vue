<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { Listing } from '../lib/Listing';

interface Props {
    listing: Listing<Record<string, unknown>>;
}

defineProps<Props>();
</script>

<template>
    <div v-if="listing.activeFilters.length > 0" class="mb-6 flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-twilight-indigo-300">Active filters:</span>
        <span v-for="filter in listing.activeFilters" :key="filter.key"
            class="inline-flex items-stretch rounded overflow-hidden border border-smart-blue-500">
            <span class="px-3 py-1 text-xs font-medium bg-smart-blue-600 text-white hover:bg-smart-blue-500 transition-colors">{{ filter.label }}</span>
            <span class="px-3 py-1 text-xs font-semibold bg-prussian-blue-700 text-twilight-indigo-100 border-l border-smart-blue-500 hover:bg-prussian-blue-600 transition-colors truncate max-w-xs">{{ filter.value }}</span>
            <button
                type="button"
                @click="() => listing.removeFilter(filter.key)"
                aria-label="`Remove ${filter.label} filter`"
                class="px-2 text-xs font-bold border-l border-smart-blue-500 bg-transparent text-twilight-indigo-300 hover:bg-smart-blue-600/40 hover:text-twilight-indigo-100 transition-colors"
            >
                <X :size="12" />
            </button>
        </span>
        <Button variant="destructive" size="sm" @click="() => listing.resetFilters()">
            Clear
        </Button>
    </div>
</template>
