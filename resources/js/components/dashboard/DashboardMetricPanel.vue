<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardMetricPanel } from '@/types/dashboard';
import { formatDashboardCount } from '@/utils/dashboard';

interface Props {
    panel: DashboardMetricPanel;
    isLoading: boolean;
}

defineProps<Props>();
</script>

<template>
    <section class="space-y-5 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-5">
        <div>
            <h2 class="text-base font-semibold text-regal-navy-100">{{ panel.title }}</h2>
            <p class="text-sm text-twilight-indigo-200">{{ panel.description }}</p>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton v-for="index in 4" :key="index" class="h-10 w-full" />
        </div>

        <div v-else class="space-y-4">
            <div v-for="row in panel.rows" :key="row.key" class="space-y-2">
                <div class="flex items-baseline justify-between gap-4">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-regal-navy-100">{{ row.label }}</div>
                        <div v-if="row.meta" class="text-xs text-twilight-indigo-300">{{ row.meta }}</div>
                    </div>
                    <div class="shrink-0 text-sm font-semibold tabular-nums text-regal-navy-100">
                        {{ formatDashboardCount(row.value) }}
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
