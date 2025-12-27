<script setup lang="ts">
import { computed } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import Pill from './ui/Pill.vue';
import type { BackfillState } from '@/composables/useBackfill';
import type { MasonryItem } from '@/composables/useTabs';
import type { MasonryInstance } from '@wyxos/vibe';

interface Props {
    items: MasonryItem[];
    masonry: MasonryInstance | null;
    tab: { params?: { page?: string | number; next?: string | number | null } } | null;
    isLoading?: boolean;
    backfill: BackfillState;
    visible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isLoading: false,
    visible: true,
});

// Display page value - masonry.currentPage already contains the cursor string for cursor-based pagination.
// Fallback to tab.params.page when masonry isn't initialized yet.
const displayPage = computed(() => props.masonry?.currentPage ?? props.tab?.params?.page ?? 1);

// Get next cursor directly from masonry - the last item in paginationHistory is the next page/cursor
const nextCursor = computed(() => {
    const history = props.masonry?.paginationHistory;
    if (!history || history.length === 0) {
        return null;
    }
    // The last item in paginationHistory is the next page/cursor to load
    return history[history.length - 1] ?? null;
});
</script>

<template>
    <div v-if="visible" class="my-2 flex flex-wrap items-center justify-center gap-3" data-test="pagination-info">
        <p class="text-white">
            {{ masonry?.paginationHistory }} test
        </p>
        <!-- Count Pill -->
        <Pill label="Items" :value="items.length" variant="primary" reversed data-test="items-pill" />
        <!-- Current Page Pill -->
        <Pill label="Page" :value="displayPage" variant="neutral" reversed data-test="page-pill" />
        <!-- Next Page Pill -->
        <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed data-test="next-pill" />
        <!-- Status Pill -->
        <Pill :label="'Status'" :value="isLoading ? 'Loading...' : 'Ready'" :variant="isLoading ? 'danger' : 'success'"
            reversed data-test="status-pill">
            <template #value>
                <span v-if="isLoading" class="flex items-center gap-2">
                    <Loader2 :size="14" class="animate-spin" />
                    <span>Loading...</span>
                </span>
                <span v-else>Ready</span>
            </template>
        </Pill>
        <!-- Backfill Progress Pills -->
        <span class="inline-flex items-stretch rounded overflow-hidden border"
            :class="backfill.active ? 'border-warning-500' : 'border-twilight-indigo-500/30'"
            data-test="backfill-active-pill">
            <span class="px-3 py-1 text-xs font-medium transition-colors flex items-center gap-2"
                :class="backfill.active ? 'bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500' : 'bg-prussian-blue-700/50 text-twilight-indigo-400 border-r border-twilight-indigo-500/30'">
                <Loader2 v-if="backfill.active" :size="14" class="animate-spin" />
                <span>{{ backfill.active ? (backfill.waiting ? 'Waiting' : 'Filling') : 'Backfill' }}</span>
            </span>
            <span class="px-3 py-1 text-xs font-semibold transition-colors flex items-center gap-3"
                :class="backfill.active ? 'bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100' : 'bg-prussian-blue-700/30 text-twilight-indigo-400'">
                <template v-if="backfill.active">
                    <span v-if="!backfill.waiting">
                        {{ backfill.fetched }} / {{ backfill.target }} ({{ backfill.calls }} call{{ backfill.calls !== 1
                            ? 's' : '' }})
                    </span>
                    <template v-else>
                        <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                            <div class="h-full bg-warning-500 transition-[width] duration-100" :style="{
                                width: Math.max(0, 100 - Math.round((backfill.waitRemainingMs / Math.max(1, backfill.waitTotalMs)) * 100)) + '%',
                            }" />
                        </div>
                        <span v-if="backfill.waitRemainingMs > 0" class="text-xs text-warning-100">next in {{
                            (backfill.waitRemainingMs /
                                1000).toFixed(1) }}s</span>
                        <span v-else class="text-xs text-warning-100">Executing request...</span>
                    </template>
                </template>
                <span v-else class="text-xs">Inactive</span>
            </span>
        </span>
        <span class="inline-flex items-stretch rounded overflow-hidden border"
            :class="backfill.retryActive ? 'border-warning-500' : 'border-twilight-indigo-500/30'"
            data-test="backfill-retry-pill">
            <span class="px-3 py-1 text-xs font-medium transition-colors flex items-center gap-2"
                :class="backfill.retryActive ? 'bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500' : 'bg-prussian-blue-700/50 text-twilight-indigo-400 border-r border-twilight-indigo-500/30'">
                <Loader2 v-if="backfill.retryActive" :size="14" class="animate-spin" />
                <span>Retry</span>
            </span>
            <span class="px-3 py-1 text-xs font-semibold transition-colors flex items-center gap-3"
                :class="backfill.retryActive ? 'bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100' : 'bg-prussian-blue-700/30 text-twilight-indigo-400'">
                <template v-if="backfill.retryActive">
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
                </template>
                <span v-else class="text-xs">Inactive</span>
            </span>
        </span>
    </div>
</template>
