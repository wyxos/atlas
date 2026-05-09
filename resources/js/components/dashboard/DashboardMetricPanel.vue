<script setup lang="ts">
import DashboardMetricDistributionBlock from '@/components/dashboard/DashboardMetricDistributionBlock.vue';
import DashboardMetricValue from '@/components/dashboard/DashboardMetricValue.vue';
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardMetricPanel } from '@/types/dashboard';

interface Props {
    panel: DashboardMetricPanel;
    isLoading: boolean;
}

defineProps<Props>();
</script>

<template>
    <section class="flex h-full flex-col gap-5 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-regal-navy-100">{{ panel.title }}</h2>
                <p class="text-sm text-twilight-indigo-200">{{ panel.description }}</p>
            </div>

            <div v-if="panel.summaryRows?.length" class="flex shrink-0 flex-wrap items-start justify-end gap-x-6 gap-y-2 text-right">
                <div v-for="row in panel.summaryRows" :key="row.key" class="min-w-20">
                    <div class="text-xs text-twilight-indigo-300">{{ row.label }}</div>
                    <div class="text-lg font-semibold tabular-nums">
                        <DashboardMetricValue :value="row.value" :denominator="row.denominator" :color="row.color" />
                    </div>
                    <div v-if="row.meta" class="text-xs text-twilight-indigo-300">
                        {{ row.meta }}
                    </div>
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton v-for="index in 4" :key="index" class="h-10 w-full" />
        </div>

        <div v-else class="space-y-5">
            <DashboardMetricDistributionBlock
                v-for="distribution in panel.distributions ?? []"
                :key="distribution.key"
                :distribution="distribution"
            />

            <div v-for="row in panel.rows" :key="row.key" class="space-y-2">
                <div class="flex items-baseline justify-between gap-4">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-regal-navy-100">{{ row.label }}</div>
                        <div v-if="row.meta" class="text-xs text-twilight-indigo-300">{{ row.meta }}</div>
                    </div>
                    <div class="shrink-0 text-sm font-semibold tabular-nums">
                        <DashboardMetricValue :value="row.value" :denominator="row.denominator" :color="row.color" />
                    </div>
                </div>

                <div v-if="row.barPercent !== undefined" class="h-2 overflow-hidden rounded-sm bg-prussian-blue-900">
                    <div
                        class="h-full rounded-sm"
                        :class="{ 'min-w-px': row.value > 0 }"
                        :style="{ width: `${row.barPercent}%`, backgroundColor: row.color }"
                    />
                </div>
            </div>
        </div>
    </section>
</template>
