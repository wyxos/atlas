<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardPositiveOutcomes } from '@/types/dashboard';
import { formatDashboardCount } from '@/utils/dashboard';

interface Props {
    outcomes: DashboardPositiveOutcomes;
    isLoading: boolean;
}

defineProps<Props>();
</script>

<template>
    <section class="space-y-5 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-regal-navy-100">{{ outcomes.title }}</h2>
                <p class="text-sm text-twilight-indigo-200">{{ outcomes.description }}</p>
            </div>
            <div class="text-right">
                <div class="text-xs text-twilight-indigo-300">Total</div>
                <div class="text-lg font-semibold tabular-nums text-regal-navy-100">
                    {{ formatDashboardCount(outcomes.total) }}
                </div>
            </div>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton v-for="index in 3" :key="index" class="h-11 w-full" />
        </div>

        <div v-else class="space-y-4">
            <div v-for="row in outcomes.rows" :key="row.key" class="grid gap-2 sm:grid-cols-[120px_1fr_90px] sm:items-center">
                <div class="text-sm font-medium text-regal-navy-100">{{ row.label }}</div>
                <div class="h-3 overflow-hidden rounded-sm bg-prussian-blue-900">
                    <div
                        class="h-full rounded-sm"
                        :class="{ 'min-w-px': row.value > 0 }"
                        :style="{ width: `${row.barPercent ?? 0}%`, backgroundColor: row.color }"
                    />
                </div>
                <div class="text-left text-sm font-semibold tabular-nums text-regal-navy-100 sm:text-right">
                    {{ formatDashboardCount(row.value) }}
                </div>
                <div v-if="row.meta" class="text-xs text-twilight-indigo-300 sm:col-start-2">
                    {{ row.meta }}
                </div>
            </div>
        </div>
    </section>
</template>
