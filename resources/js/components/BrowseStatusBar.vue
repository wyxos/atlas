<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';
import Pill from './ui/Pill.vue';
import type { BackfillState } from '../composables/useBackfill';
import type { MasonryItem } from '../composables/useBrowseTabs';

interface Props {
    items: MasonryItem[];
    displayPage: string | number;
    nextCursor: string | number | null;
    isLoading?: boolean;
    backfill: BackfillState;
    visible?: boolean;
    queuedReactionsCount?: number;
    queuedAutoDislikeCount?: number;
}

withDefaults(defineProps<Props>(), {
    isLoading: false,
    visible: true,
    queuedReactionsCount: 0,
    queuedAutoDislikeCount: 0,
});
</script>

<template>
    <div v-if="visible"
        class="my-2 flex flex-wrap items-center justify-center gap-3" data-test="pagination-info">
        <!-- Queued Reactions Pill -->
        <Pill v-if="queuedReactionsCount > 0" label="Queued" :value="queuedReactionsCount" variant="warning" reversed
            data-test="queued-reactions-pill" />
        <!-- Auto-Dislike Queue Pill -->
        <Pill v-if="queuedAutoDislikeCount > 0" label="Dislike" :value="queuedAutoDislikeCount" variant="danger" reversed
            data-test="auto-dislike-queue-pill" />
        <!-- Count Pill -->
        <Pill label="Items" :value="items.length" variant="primary" reversed data-test="items-pill" />
        <!-- Current Page Pill -->
        <Pill label="Page" :value="displayPage" variant="neutral" reversed data-test="page-pill" />
        <!-- Next Page Pill -->
        <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed
            data-test="next-pill" />
        <!-- Status Pill -->
        <Pill :label="'Status'" :value="isLoading ? 'Loading...' : 'Ready'"
            :variant="isLoading ? 'danger' : 'success'" reversed data-test="status-pill">
            <template #value>
                <span v-if="isLoading" class="flex items-center gap-2">
                    <Loader2 :size="14" class="animate-spin" />
                    <span>Loading...</span>
                </span>
                <span v-else>Ready</span>
            </template>
        </Pill>
        <!-- Backfill Progress Pills -->
        <span v-if="backfill.active"
            class="inline-flex items-stretch rounded overflow-hidden border border-warning-500"
            data-test="backfill-active-pill">
            <span
                class="px-3 py-1 text-xs font-medium transition-colors bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500 flex items-center gap-2">
                <Loader2 :size="14" class="animate-spin" />
                <span>{{ backfill.waiting ? 'Waiting' : 'Filling' }}</span>
            </span>
            <span
                class="px-3 py-1 text-xs font-semibold transition-colors bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100 flex items-center gap-3">
                <span v-if="!backfill.waiting">
                    {{ backfill.fetched }} / {{ backfill.target }} ({{ backfill.calls }} calls)
                </span>
                <template v-else>
                    <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                        <div class="h-full bg-warning-500 transition-[width] duration-100" :style="{
                            width: Math.max(0, 100 - Math.round((backfill.waitRemainingMs / Math.max(1, backfill.waitTotalMs)) * 100)) + '%',
                        }" />
                    </div>
                    <span class="text-xs text-warning-100">next in {{ (backfill.waitRemainingMs /
                        1000).toFixed(1) }}s</span>
                </template>
            </span>
        </span>
        <span v-if="backfill.retryActive"
            class="inline-flex items-stretch rounded overflow-hidden border border-warning-500"
            data-test="backfill-retry-pill">
            <span
                class="px-3 py-1 text-xs font-medium transition-colors bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500 flex items-center gap-2">
                <Loader2 :size="14" class="animate-spin" />
                <span>Retry</span>
            </span>
            <span
                class="px-3 py-1 text-xs font-semibold transition-colors bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100 flex items-center gap-3">
                <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                    <div class="h-full bg-warning-500 transition-[width] duration-100" :style="{
                        width:
                            Math.max(
                                0,
                                100 - Math.round((backfill.retryWaitRemainingMs / Math.max(1, backfill.retryWaitTotalMs)) * 100),
                            ) + '%',
                    }" />
                </div>
                <span class="text-xs text-warning-100">
                    retry {{ backfill.retryAttempt }} / {{ backfill.retryMax }} in {{
                        (backfill.retryWaitRemainingMs / 1000).toFixed(1) }}s
                </span>
            </span>
        </span>
    </div>
</template>

