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
        <div v-for="filter in listing.activeFilters" :key="filter.key"
            class="inline-flex items-stretch rounded border border-smart-blue-400 text-sm">
            <span class="bg-smart-blue-400 px-3 py-1.5 font-medium text-white">{{ filter.label }}</span>
            <span class="bg-smart-blue-700 px-3 py-1.5 text-smart-blue-100 truncate max-w-xs">{{ filter.value }}</span>
            <Button @click="() => listing.removeFilter(filter.key)" variant="destructive" size="sm"
                class="flex items-center justify-center px-1.5 border-0 rounded-br rounded-tr rounded-tl-none rounded-bl-none"
                :aria-label="`Remove ${filter.label} filter`">
                <X :size="14" />
            </Button>
        </div>
        <Button variant="destructive" size="sm" @click="() => listing.resetFilters()">
            Clear all
        </Button>
    </div>
</template>
