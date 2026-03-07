<script setup lang="ts">
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';
import type { DownloadQueueSortDirection } from '@/types/downloadQueue';

interface Props {
    label: string;
    direction: DownloadQueueSortDirection | null;
    ariaLabel: string;
}

defineProps<Props>();

defineEmits<{
    click: [];
}>();
</script>

<template>
    <button
        type="button"
        class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
        :aria-label="ariaLabel"
        @click="$emit('click')"
    >
        <span>{{ label }}</span>
        <ArrowUp v-if="direction === 'asc'" :size="12" class="text-blue-slate-400" />
        <ArrowDown v-else-if="direction === 'desc'" :size="12" class="text-blue-slate-400" />
        <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
    </button>
</template>
