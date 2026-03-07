<script setup lang="ts">
import { computed } from 'vue';
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import { Skeleton } from '@/components/ui/skeleton';
import {
    ChartContainer,
    ChartCrosshair,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
} from '@/components/ui/chart';
import type { DashboardChartDatum, DashboardChartSection } from '@/types/dashboard';
import { buildDashboardTickValues, formatDashboardCount } from '@/utils/dashboard';

interface Props {
    section: DashboardChartSection;
    isLoading: boolean;
}

const props = defineProps<Props>();

const tooltipClass = 'bg-prussian-blue-600 border border-twilight-indigo-500/60 text-twilight-indigo-100';

const tooltipTemplate = computed(() =>
    componentToString(props.section.config, ChartTooltipContent, {
        labelFormatter: () => props.section.tooltipLabel,
        class: tooltipClass,
    }),
);

const seriesColors = computed(() =>
    props.section.seriesKeys.map((key) => props.section.config[key]?.color ?? 'currentColor'),
);

const seriesAccessors = computed(() =>
    props.section.seriesKeys.map((key) => (datum: DashboardChartDatum) => Number(datum[key] ?? 0)),
);

const axisLabelMap = computed(() => new Map(props.section.data.map((item) => [item.index, item.label])));
const xTickValues = computed(() => props.section.data.map((item) => item.index));

const maxValue = computed(() =>
    props.section.data.reduce((currentMax, item) => {
        const itemMax = props.section.seriesKeys.reduce(
            (seriesMax, key) => Math.max(seriesMax, Number(item[key] ?? 0)),
            0,
        );
        return Math.max(currentMax, itemMax);
    }, 0),
);

const yTickValues = computed(() => buildDashboardTickValues(maxValue.value, 5));

function formatAxisLabel(value: number): string {
    return axisLabelMap.value.get(value) ?? '';
}
</script>

<template>
    <div class="space-y-6 rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6">
        <div>
            <h2 class="text-lg font-semibold text-regal-navy-100">{{ section.title }}</h2>
            <p class="text-sm text-twilight-indigo-200">{{ section.description }}</p>
        </div>

        <div v-if="isLoading" class="space-y-4">
            <Skeleton class="h-40 w-full" />
            <Skeleton class="h-4 w-1/2" />
        </div>

        <ChartContainer v-else :config="section.config" class="mx-auto h-[180px] w-full max-w-[420px]">
            <VisXYContainer :data="section.data">
                <VisStackedBar
                    :x="(datum: DashboardChartDatum) => datum.index"
                    :y="seriesAccessors"
                    :color="seriesColors"
                    :data-step="1"
                    :bar-max-width="40"
                />
                <VisAxis
                    type="x"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="false"
                    :tick-values="xTickValues"
                    :tick-format="formatAxisLabel"
                />
                <VisAxis
                    type="y"
                    :tick-line="false"
                    :domain-line="false"
                    :grid-line="true"
                    :tick-format="formatDashboardCount"
                    :tick-values="yTickValues"
                />
                <ChartTooltip />
                <ChartCrosshair :template="tooltipTemplate" :color="seriesColors" />
            </VisXYContainer>
            <ChartLegendContent class="pt-4 text-sm text-twilight-indigo-200" />
        </ChartContainer>

        <div class="mt-10 flex flex-wrap justify-center gap-8 text-sm">
            <div v-for="item in section.summary" :key="item.label" class="flex gap-2">
                <div class="text-twilight-indigo-200">{{ item.label }}</div>
                <div class="font-semibold" :style="{ color: item.color }">{{ formatDashboardCount(item.value) }}</div>
            </div>
        </div>
    </div>
</template>
