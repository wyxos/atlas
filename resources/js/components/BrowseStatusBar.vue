<script setup lang="ts">
import { computed } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import Pill from './ui/Pill.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { BackfillStats, MasonryInstance } from '@wyxos/vibe';

interface Props {
    items: FeedItem[];
    masonry: MasonryInstance | null;
    tab: { params?: { page?: string | number; next?: string | number | null } } | null;
    isLoading?: boolean;
    visible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isLoading: false,
    visible: true,
});

// Display page value - Vibe exposes the loaded page tokens.
// Fallback to tab.params.page when masonry isn't initialized yet.
const displayPage = computed(() => {
    const pagesLoaded = props.masonry?.pagesLoaded;
    const lastLoaded = Array.isArray(pagesLoaded) && pagesLoaded.length > 0
        ? pagesLoaded[pagesLoaded.length - 1]
        : null;

    return lastLoaded ?? props.tab?.params?.page ?? 1;
});

// Next cursor/page token comes directly from Vibe.
const nextCursor = computed(() => props.masonry?.nextPage ?? props.tab?.params?.next ?? null);

const backfillStats = computed<BackfillStats | null>(() => props.masonry?.backfillStats ?? null);

const backfillActive = computed(() => backfillStats.value?.isBackfillActive ?? false);
const backfillWaiting = computed(() => {
    const stats = backfillStats.value;
    if (!stats || !stats.isBackfillActive) {
        return false;
    }

    return stats.cooldownMsRemaining > 0 && !stats.isRequestInFlight;
});

const backfillLabel = computed(() => {
    const stats = backfillStats.value;
    if (!stats || !stats.enabled) {
        return 'Backfill';
    }

    if (!stats.isBackfillActive) {
        return 'Backfill';
    }

    if (stats.isRequestInFlight) {
        return 'Fetching';
    }

    return backfillWaiting.value ? 'Waiting' : 'Filling';
});

const backfillCooldownWidth = computed(() => {
    const stats = backfillStats.value;
    if (!stats) {
        return '0%';
    }

    const total = Math.max(1, stats.cooldownMsTotal);
    const remaining = Math.max(0, stats.cooldownMsRemaining);
    const pct = Math.max(0, 100 - Math.round((remaining / total) * 100));
    return `${pct}%`;
});
</script>

<template>
    <div v-if="visible" class="my-2 flex flex-wrap items-center justify-center gap-3" data-test="pagination-info">
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
        <!-- Backfill Status -->
        <span class="inline-flex items-stretch rounded overflow-hidden border"
            :class="backfillActive ? 'border-warning-500' : 'border-twilight-indigo-500/30'"
            data-test="backfill-active-pill">
            <span class="px-3 py-1 text-xs font-medium transition-colors flex items-center gap-2"
                :class="backfillActive ? 'bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500' : 'bg-prussian-blue-700/50 text-twilight-indigo-400 border-r border-twilight-indigo-500/30'">
                <Loader2 v-if="backfillActive" :size="14" class="animate-spin" />
                <span>{{ backfillLabel }}</span>
            </span>
            <span class="px-3 py-1 text-xs font-semibold transition-colors flex items-center gap-3"
                :class="backfillActive ? 'bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100' : 'bg-prussian-blue-700/30 text-twilight-indigo-400'">
                <template v-if="backfillActive && backfillStats">
                    <span>
                        {{ backfillStats.progress.collected }} / {{ backfillStats.progress.target }}
                    </span>

                    <template v-if="!backfillWaiting">
                        <span class="text-xs text-warning-100">
                            ({{ backfillStats.totals.pagesFetched }} page{{ backfillStats.totals.pagesFetched !== 1 ? 's' : '' }})
                        </span>
                    </template>
                    <template v-else>
                        <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                            <div
                                class="h-full bg-warning-500 transition-[width] duration-100"
                                :style="{ width: backfillCooldownWidth }"
                            />
                        </div>
                        <span v-if="backfillStats.cooldownMsRemaining > 0" class="text-xs text-warning-100">
                            next in {{ (backfillStats.cooldownMsRemaining / 1000).toFixed(1) }}s
                        </span>
                        <span v-else class="text-xs text-warning-100">Executing request...</span>
                    </template>
                </template>
                <span v-else class="text-xs">Inactive</span>
            </span>
        </span>
    </div>
</template>
