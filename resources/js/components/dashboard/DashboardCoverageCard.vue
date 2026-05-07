<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardCoverage } from '@/types/dashboard';
import { formatDashboardCount, formatDashboardPercent } from '@/utils/dashboard';

interface Props {
    coverage: DashboardCoverage;
    isLoading: boolean;
}

defineProps<Props>();
</script>

<template>
    <section class="space-y-6 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h2 class="text-lg font-semibold text-regal-navy-100">Moderation coverage</h2>
                <p class="text-sm text-twilight-indigo-200">
                    Library progress from unseen files to decisions and removals.
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4 text-right">
                <div>
                    <div class="text-xs text-twilight-indigo-300">Total files</div>
                    <div class="text-2xl font-semibold tabular-nums text-regal-navy-100">
                        {{ formatDashboardCount(coverage.total) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-twilight-indigo-300">Moderated</div>
                    <div class="text-2xl font-semibold tabular-nums text-success-300">
                        {{ coverage.moderatedPercent }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton class="h-7 w-full" />
            <Skeleton class="h-16 w-full" />
        </div>

        <template v-else>
            <div class="flex h-7 w-full overflow-hidden rounded-sm bg-prussian-blue-900">
                <div
                    v-for="segment in coverage.segments"
                    :key="segment.key"
                    class="h-full transition-[width]"
                    :class="{ 'min-w-px': segment.value > 0 }"
                    :style="{ width: `${segment.barPercent}%`, backgroundColor: segment.color }"
                    :title="`${segment.label}: ${formatDashboardCount(segment.value)} (${formatDashboardPercent(segment.barPercent)})`"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="segment in coverage.segments"
                    :key="segment.key"
                    class="rounded-sm border border-twilight-indigo-500/30 bg-prussian-blue-700/60 p-3"
                >
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: segment.color }" />
                        <span class="text-sm text-twilight-indigo-200">{{ segment.label }}</span>
                    </div>
                    <div class="mt-2 flex items-baseline justify-between gap-3">
                        <span class="text-lg font-semibold tabular-nums text-regal-navy-100">
                            {{ formatDashboardCount(segment.value) }}
                        </span>
                        <span class="text-sm tabular-nums text-twilight-indigo-300">
                            {{ formatDashboardPercent(segment.barPercent) }}
                        </span>
                    </div>
                </div>
            </div>
        </template>
    </section>
</template>
