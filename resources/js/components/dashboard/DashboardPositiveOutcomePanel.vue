<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
import type { DashboardPositiveOutcomes } from '@/types/dashboard';
import { formatDashboardCount, formatDashboardPercent } from '@/utils/dashboard';

interface Props {
    outcomes: DashboardPositiveOutcomes;
    isLoading: boolean;
}

defineProps<Props>();
</script>

<template>
    <section class="flex h-full flex-col gap-5 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-5">
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

        <div v-else class="space-y-2">
            <div class="text-sm font-semibold text-regal-navy-100">Reaction split</div>

            <div class="flex h-3 overflow-hidden rounded-sm bg-prussian-blue-900">
                <div
                    v-for="row in outcomes.rows"
                    :key="row.key"
                    class="h-full transition-[width]"
                    :class="{ 'min-w-px': row.value > 0 }"
                    :style="{ width: `${row.barPercent ?? 0}%`, backgroundColor: row.color }"
                    :title="`${row.label}: ${formatDashboardCount(row.value)} (${formatDashboardPercent(row.barPercent ?? 0)})`"
                />
            </div>

            <div class="grid gap-2 sm:grid-cols-3">
                <div
                    v-for="row in outcomes.rows"
                    :key="row.key"
                    class="rounded-sm border border-twilight-indigo-500/30 bg-prussian-blue-700/60 p-3"
                >
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-sm" :style="{ backgroundColor: row.color }" />
                        <span class="truncate text-sm text-twilight-indigo-200">{{ row.label }}</span>
                    </div>
                    <div class="mt-2 flex items-baseline justify-between gap-3">
                        <span class="text-base font-semibold tabular-nums text-regal-navy-100">
                            {{ formatDashboardCount(row.value) }}
                        </span>
                        <span class="text-sm tabular-nums text-twilight-indigo-300">
                            {{ formatDashboardPercent(row.barPercent ?? 0) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>
