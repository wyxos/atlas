<script setup lang="ts">
import DashboardMetricDistributionBlock from '@/components/dashboard/DashboardMetricDistributionBlock.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardCoverage } from '@/types/dashboard';
import { formatDashboardCount } from '@/utils/dashboard';

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
                <h2 class="text-base font-semibold text-regal-navy-100">Review coverage</h2>
                <p class="text-sm text-twilight-indigo-200">
                    Preview and reaction progress for active library files.
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
                    <div class="text-xs text-twilight-indigo-300">Previewed</div>
                    <div class="text-lg font-semibold tabular-nums text-success-300">
                        {{ coverage.previewedPercent }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton class="h-7 w-full" />
            <Skeleton class="h-16 w-full" />
        </div>

        <div v-else class="space-y-5">
            <DashboardMetricDistributionBlock
                v-for="distribution in coverage.distributions"
                :key="distribution.key"
                :distribution="distribution"
            />
        </div>
    </section>
</template>
