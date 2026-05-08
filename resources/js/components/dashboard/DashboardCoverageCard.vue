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
    <section class="flex h-full flex-col gap-5 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-regal-navy-100">Moderation coverage</h2>
                <p class="text-sm text-twilight-indigo-200">
                    Library progress from unseen files to decisions and removals.
                </p>
            </div>

            <div class="flex shrink-0 flex-wrap items-start justify-end gap-x-6 gap-y-2 text-right">
                <div class="min-w-20">
                    <div class="text-xs text-twilight-indigo-300">Total files</div>
                    <div class="text-lg font-semibold tabular-nums text-regal-navy-100">
                        {{ formatDashboardCount(coverage.total) }}
                    </div>
                </div>
                <div class="min-w-20">
                    <div class="text-xs text-twilight-indigo-300">Decision coverage</div>
                    <div class="text-lg font-semibold tabular-nums text-success-300">
                        {{ coverage.moderatedPercent }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton class="h-7 w-full" />
            <Skeleton class="h-16 w-full" />
        </div>

        <div v-else class="space-y-5">
            <div v-for="distribution in coverage.distributions" :key="distribution.key" class="space-y-2">
                <div class="text-sm font-semibold text-regal-navy-100" :title="distribution.meta">
                    {{ distribution.label }}
                </div>

                <div class="flex h-3 w-full overflow-hidden rounded-sm bg-prussian-blue-900">
                    <div
                        v-for="segment in distribution.segments"
                        :key="segment.key"
                        class="h-full transition-[width]"
                        :class="{ 'min-w-px': segment.value > 0 }"
                        :style="{ width: `${segment.barPercent ?? 0}%`, backgroundColor: segment.color }"
                        :title="`${segment.label}: ${formatDashboardCount(segment.value)} (${formatDashboardPercent(segment.barPercent ?? 0)})`"
                    />
                </div>

                <div class="grid gap-2 sm:grid-cols-2">
                    <div
                        v-for="segment in distribution.segments"
                        :key="segment.key"
                        class="rounded-sm border border-twilight-indigo-500/30 bg-prussian-blue-700/60 p-3"
                    >
                        <div class="flex items-center gap-2">
                            <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: segment.color }" />
                            <span class="text-sm text-twilight-indigo-200">{{ segment.label }}</span>
                        </div>
                        <div class="mt-2 flex items-baseline justify-between gap-3">
                            <span class="text-base font-semibold tabular-nums text-regal-navy-100">
                                {{ formatDashboardCount(segment.value) }}
                            </span>
                            <span class="text-sm tabular-nums text-twilight-indigo-300">
                                {{ formatDashboardPercent(segment.barPercent ?? 0) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
