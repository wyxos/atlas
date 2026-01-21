<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { VisAxis, VisStackedBar, VisXYContainer } from '@unovis/vue';
import PageLayout from '../components/PageLayout.vue';
import { Skeleton } from '@/components/ui/skeleton';
import {
    ChartContainer,
    ChartCrosshair,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
    componentToString,
    type ChartConfig,
} from '@/components/ui/chart';

type DashboardMetrics = {
    files: {
        total: number;
        reactions: {
            love: number;
            like: number;
            dislike: number;
            funny: number;
        };
        blacklisted: {
            total: number;
            manual: number;
            auto: number;
        };
        unreacted_not_blacklisted: number;
    };
};

type ReactionChartDatum = {
    index: number;
    label: string;
    love: number;
    like: number;
    dislike: number;
    funny: number;
    unreacted: number;
};

type BlacklistChartDatum = {
    index: number;
    label: string;
    total: number;
    manual: number;
    auto: number;
};

const metrics = ref<DashboardMetrics | null>(null);
const isLoading = ref(true);
const loadError = ref<string | null>(null);

const reactionChartConfig = {
    love: { label: 'Favorite', color: '#ef4444' },
    like: { label: 'Like', color: 'var(--color-smart-blue-500)' },
    dislike: { label: 'Dislike', color: '#6b7280' },
    funny: { label: 'Funny', color: '#eab308' },
    unreacted: { label: 'Unreacted', color: 'var(--color-blue-slate-400)' },
} satisfies ChartConfig;

const blacklistChartConfig = {
    total: { label: 'Total', color: 'var(--color-blue-slate-200)' },
    manual: { label: 'Manual', color: 'var(--color-smart-blue-500)' },
    auto: { label: 'Auto', color: '#6b7280' },
} satisfies ChartConfig;

const reactionChartData = computed<ReactionChartDatum[]>(() => [
    {
        index: 0,
        label: 'Favorite',
        love: metrics.value?.files.reactions.love ?? 0,
        like: 0,
        dislike: 0,
        funny: 0,
        unreacted: 0,
    },
    {
        index: 1,
        label: 'Like',
        love: 0,
        like: metrics.value?.files.reactions.like ?? 0,
        dislike: 0,
        funny: 0,
        unreacted: 0,
    },
    {
        index: 2,
        label: 'Dislike',
        love: 0,
        like: 0,
        dislike: metrics.value?.files.reactions.dislike ?? 0,
        funny: 0,
        unreacted: 0,
    },
    {
        index: 3,
        label: 'Funny',
        love: 0,
        like: 0,
        dislike: 0,
        funny: metrics.value?.files.reactions.funny ?? 0,
        unreacted: 0,
    },
    {
        index: 4,
        label: 'Unreacted',
        love: 0,
        like: 0,
        dislike: 0,
        funny: 0,
        unreacted: metrics.value?.files.unreacted_not_blacklisted ?? 0,
    },
]);

const blacklistChartData = computed<BlacklistChartDatum[]>(() => [
    {
        index: 0,
        label: 'Total',
        total: (metrics.value?.files.blacklisted.manual ?? 0) + (metrics.value?.files.blacklisted.auto ?? 0),
        manual: 0,
        auto: 0,
    },
    {
        index: 1,
        label: 'Manual',
        total: 0,
        manual: metrics.value?.files.blacklisted.manual ?? 0,
        auto: 0,
    },
    {
        index: 2,
        label: 'Auto',
        manual: 0,
        total: 0,
        auto: metrics.value?.files.blacklisted.auto ?? 0,
    },
]);

const tooltipClass = 'bg-prussian-blue-600 border border-twilight-indigo-500/60 text-twilight-indigo-100';

const reactionTooltip = componentToString(reactionChartConfig, ChartTooltipContent, {
    labelFormatter: () => 'Reactions',
    class: tooltipClass,
});

const blacklistTooltip = componentToString(blacklistChartConfig, ChartTooltipContent, {
    labelFormatter: () => 'Blacklisted',
    class: tooltipClass,
});

const totalFiles = computed(() => metrics.value?.files.total ?? 0);
const totalBlacklisted = computed(() => metrics.value?.files.blacklisted.total ?? 0);

const reactionSummary = computed(() => [
    { label: 'Favorite', value: metrics.value?.files.reactions.love ?? 0, color: reactionChartConfig.love.color },
    { label: 'Like', value: metrics.value?.files.reactions.like ?? 0, color: reactionChartConfig.like.color },
    { label: 'Dislike', value: metrics.value?.files.reactions.dislike ?? 0, color: reactionChartConfig.dislike.color },
    { label: 'Funny', value: metrics.value?.files.reactions.funny ?? 0, color: reactionChartConfig.funny.color },
    {
        label: 'Unreacted',
        value: metrics.value?.files.unreacted_not_blacklisted ?? 0,
        color: reactionChartConfig.unreacted.color,
    },
]);

const blacklistSummary = computed(() => [
    {
        label: 'Total',
        value: (metrics.value?.files.blacklisted.manual ?? 0) + (metrics.value?.files.blacklisted.auto ?? 0),
        color: blacklistChartConfig.total.color,
    },
    { label: 'Manual', value: metrics.value?.files.blacklisted.manual ?? 0, color: blacklistChartConfig.manual.color },
    { label: 'Auto', value: metrics.value?.files.blacklisted.auto ?? 0, color: blacklistChartConfig.auto.color },
]);

const formatCount = (value: number) => value.toLocaleString();
const buildTickValues = (maxValue: number, steps = 5) => {
    if (maxValue <= 0) {
        return [0];
    }

    const rawStep = maxValue / steps;
    const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const normalized = rawStep / magnitude;
    const niceNormalized = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
    const niceStep = niceNormalized * magnitude;
    const top = Math.ceil(maxValue / niceStep) * niceStep;

    return Array.from({ length: steps + 1 }, (_, index) => index * niceStep).filter((value) => value <= top);
};

const reactionMax = computed(() =>
    Math.max(
        metrics.value?.files.reactions.love ?? 0,
        metrics.value?.files.reactions.like ?? 0,
        metrics.value?.files.reactions.dislike ?? 0,
        metrics.value?.files.reactions.funny ?? 0,
        metrics.value?.files.unreacted_not_blacklisted ?? 0,
    ),
);
const blacklistMax = computed(() =>
    Math.max(
        (metrics.value?.files.blacklisted.manual ?? 0) + (metrics.value?.files.blacklisted.auto ?? 0),
        metrics.value?.files.blacklisted.manual ?? 0,
        metrics.value?.files.blacklisted.auto ?? 0,
    ),
);
const reactionTicks = computed(() => buildTickValues(reactionMax.value, 5));
const blacklistTicks = computed(() => buildTickValues(blacklistMax.value, 5));
const reactionAxisLabel = (value: number) =>
    reactionChartData.value.find((item) => item.index === value)?.label ?? '';
const blacklistAxisLabel = (value: number) =>
    blacklistChartData.value.find((item) => item.index === value)?.label ?? '';

const fetchMetrics = async () => {
    isLoading.value = true;
    loadError.value = null;
    try {
        const { data } = await window.axios.get<DashboardMetrics>('/api/dashboard/metrics');
        metrics.value = data;
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'Unable to load dashboard metrics.';
    } finally {
        isLoading.value = false;
    }
};

onMounted(fetchMetrics);
</script>

<template>
    <PageLayout>
        <div class="w-full space-y-8">
            <div class="text-center">
                <h4 class="text-xl font-semibold text-regal-navy-100">
                    Dashboard
                </h4>
                <p class="text-sm text-blue-slate-300">
                    File volume and moderation impact at a glance.
                </p>
            </div>

            <div v-if="loadError" class="rounded-lg border border-danger-600/60 bg-danger-700/20 p-4 text-sm text-danger-100">
                {{ loadError }}
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-2">
                    <div class="text-sm text-twilight-indigo-200">Total files</div>
                    <div v-if="isLoading" class="space-y-2">
                        <Skeleton class="h-6 w-28" />
                        <Skeleton class="h-4 w-40" />
                    </div>
                    <div v-else class="space-y-1">
                        <div class="text-xl font-semibold text-regal-navy-100">
                            {{ formatCount(totalFiles) }}
                        </div>
                        <div class="text-xs text-twilight-indigo-200">
                            Records indexed across all sources.
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-2">
                    <div class="text-sm text-twilight-indigo-200">Blacklisted files</div>
                    <div v-if="isLoading" class="space-y-2">
                        <Skeleton class="h-6 w-28" />
                        <Skeleton class="h-4 w-40" />
                    </div>
                    <div v-else class="space-y-1">
                        <div class="text-xl font-semibold text-regal-navy-100">
                            {{ formatCount(totalBlacklisted) }}
                        </div>
                        <div class="text-xs text-twilight-indigo-200">
                            Manual plus moderation-rule blacklists.
                        </div>
                    </div>
                </div>

            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-regal-navy-100">Reactions</h2>
                        <p class="text-sm text-twilight-indigo-200">
                            Files with at least one reaction by type.
                        </p>
                    </div>

                    <div v-if="isLoading" class="space-y-4">
                        <Skeleton class="h-40 w-full" />
                        <Skeleton class="h-4 w-1/2" />
                    </div>

                    <ChartContainer v-else :config="reactionChartConfig" class="h-[180px] w-full max-w-[420px] mx-auto">
                        <VisXYContainer :data="reactionChartData">
                            <VisStackedBar
                                :x="(d: ReactionChartDatum) => d.index"
                                :y="[
                                    (d: ReactionChartDatum) => d.love,
                                    (d: ReactionChartDatum) => d.like,
                                    (d: ReactionChartDatum) => d.dislike,
                                    (d: ReactionChartDatum) => d.funny,
                                    (d: ReactionChartDatum) => d.unreacted,
                                ]"
                                :color="[
                                    reactionChartConfig.love.color,
                                    reactionChartConfig.like.color,
                                    reactionChartConfig.dislike.color,
                                    reactionChartConfig.funny.color,
                                    reactionChartConfig.unreacted.color,
                                ]"
                                :data-step="1"
                                :bar-max-width="40"
                            />
                            <VisAxis
                                type="x"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="false"
                                :tick-values="reactionChartData.map((item) => item.index)"
                                :tick-format="reactionAxisLabel"
                            />
                            <VisAxis
                                type="y"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="true"
                                :tick-format="formatCount"
                                :tick-values="reactionTicks"
                            />
                            <ChartTooltip />
                            <ChartCrosshair
                                :template="reactionTooltip"
                                :color="[
                                    reactionChartConfig.love.color,
                                    reactionChartConfig.like.color,
                                    reactionChartConfig.dislike.color,
                                    reactionChartConfig.funny.color,
                                    reactionChartConfig.unreacted.color,
                                ]"
                            />
                        </VisXYContainer>
                        <ChartLegendContent class="pt-4 text-sm text-twilight-indigo-200" />
                    </ChartContainer>

                    <div class="flex justify-center gap-10 text-sm mt-10">
                        <div v-for="item in reactionSummary" :key="item.label" class="flex gap-2">
                            <div class="text-twilight-indigo-200">{{ item.label }}</div>
                            <div class="font-semibold" :style="{ color: item.color }">{{ formatCount(item.value) }}</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-regal-navy-100">Blacklist breakdown</h2>
                        <p class="text-sm text-twilight-indigo-200">
                            Manual versus rule-driven blacklists.
                        </p>
                    </div>

                    <div v-if="isLoading" class="space-y-4">
                        <Skeleton class="h-40 w-full" />
                        <Skeleton class="h-4 w-1/2" />
                    </div>

                    <ChartContainer v-else :config="blacklistChartConfig" class="h-[180px] w-full max-w-[420px] mx-auto">
                        <VisXYContainer :data="blacklistChartData">
                            <VisStackedBar
                                :x="(d: BlacklistChartDatum) => d.index"
                                :y="[
                                    (d: BlacklistChartDatum) => d.total,
                                    (d: BlacklistChartDatum) => d.manual,
                                    (d: BlacklistChartDatum) => d.auto,
                                ]"
                                :color="[
                                    blacklistChartConfig.total.color,
                                    blacklistChartConfig.manual.color,
                                    blacklistChartConfig.auto.color,
                                ]"
                                :data-step="1"
                                :bar-max-width="40"
                            />
                            <VisAxis
                                type="x"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="false"
                                :tick-values="blacklistChartData.map((item) => item.index)"
                                :tick-format="blacklistAxisLabel"
                            />
                            <VisAxis
                                type="y"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="true"
                                :tick-format="formatCount"
                                :tick-values="blacklistTicks"
                            />
                            <ChartTooltip />
                            <ChartCrosshair
                                :template="blacklistTooltip"
                                :color="[
                                    blacklistChartConfig.manual.color,
                                    blacklistChartConfig.auto.color,
                                ]"
                            />
                        </VisXYContainer>
                        <ChartLegendContent class="pt-4 text-sm text-twilight-indigo-200" />
                    </ChartContainer>

                    <div class="flex justify-center gap-10 text-sm mt-10">
                        <div v-for="item in blacklistSummary" :key="item.label" class="flex gap-4">
                            <div class="text-twilight-indigo-200">{{ item.label }}</div>
                            <div class="font-semibold" :style="{ color: item.color }">{{ formatCount(item.value) }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
