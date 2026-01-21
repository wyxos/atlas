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
        downloaded: number;
        local: number;
        non_local: number;
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
        not_found: number;
        unreacted_not_blacklisted: number;
    };
    containers: {
        total: number;
        blacklisted: number;
        top_downloads: ContainerMetricItem[];
        top_favorites: ContainerMetricItem[];
        top_blacklisted: ContainerMetricItem[];
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

type OverviewChartDatum = {
    index: number;
    label: string;
    total: number;
    not_found: number;
    downloaded: number;
    local: number;
    non_local: number;
};

type ContainerMetricItem = {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer: string | null;
    files_count: number;
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

const overviewChartConfig = {
    total: { label: 'Total', color: 'var(--color-blue-slate-200)' },
    not_found: { label: 'Not found', color: 'var(--color-danger-300)' },
    downloaded: { label: 'Downloaded', color: 'var(--color-success-400)' },
    local: { label: 'Local', color: 'var(--color-smart-blue-500)' },
    non_local: { label: 'Non-local', color: 'var(--color-twilight-indigo-300)' },
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

const overviewChartData = computed<OverviewChartDatum[]>(() => [
    {
        index: 0,
        label: 'Total',
        total: metrics.value?.files.total ?? 0,
        not_found: 0,
        downloaded: 0,
        local: 0,
        non_local: 0,
    },
    {
        index: 1,
        label: 'Not found',
        total: 0,
        not_found: metrics.value?.files.not_found ?? 0,
        downloaded: 0,
        local: 0,
        non_local: 0,
    },
    {
        index: 2,
        label: 'Downloaded',
        total: 0,
        not_found: 0,
        downloaded: metrics.value?.files.downloaded ?? 0,
        local: 0,
        non_local: 0,
    },
    {
        index: 3,
        label: 'Local',
        total: 0,
        not_found: 0,
        downloaded: 0,
        local: metrics.value?.files.local ?? 0,
        non_local: 0,
    },
    {
        index: 4,
        label: 'Non-local',
        total: 0,
        not_found: 0,
        downloaded: 0,
        local: 0,
        non_local: metrics.value?.files.non_local ?? 0,
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

const overviewTooltip = componentToString(overviewChartConfig, ChartTooltipContent, {
    labelFormatter: () => 'Overview',
    class: tooltipClass,
});

const blacklistTooltip = componentToString(blacklistChartConfig, ChartTooltipContent, {
    labelFormatter: () => 'Blacklisted',
    class: tooltipClass,
});

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

const overviewSummary = computed(() => [
    { label: 'Total', value: metrics.value?.files.total ?? 0, color: overviewChartConfig.total.color },
    { label: 'Not found', value: metrics.value?.files.not_found ?? 0, color: overviewChartConfig.not_found.color },
    { label: 'Downloaded', value: metrics.value?.files.downloaded ?? 0, color: overviewChartConfig.downloaded.color },
    { label: 'Local', value: metrics.value?.files.local ?? 0, color: overviewChartConfig.local.color },
    { label: 'Non-local', value: metrics.value?.files.non_local ?? 0, color: overviewChartConfig.non_local.color },
]);

const containerTotals = computed(() => ({
    total: metrics.value?.containers.total ?? 0,
    blacklisted: metrics.value?.containers.blacklisted ?? 0,
}));

const topDownloadContainers = computed(() => metrics.value?.containers.top_downloads ?? []);
const topFavoriteContainers = computed(() => metrics.value?.containers.top_favorites ?? []);
const topBlacklistedContainers = computed(() => metrics.value?.containers.top_blacklisted ?? []);

const formatContainerLabel = (item: ContainerMetricItem) =>
    `${item.type} • ${item.source} • ${item.source_id}`;

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
const overviewMax = computed(() =>
    Math.max(
        metrics.value?.files.total ?? 0,
        metrics.value?.files.not_found ?? 0,
        metrics.value?.files.downloaded ?? 0,
        metrics.value?.files.local ?? 0,
        metrics.value?.files.non_local ?? 0,
    ),
);
const blacklistMax = computed(() =>
    Math.max(
        (metrics.value?.files.blacklisted.manual ?? 0) + (metrics.value?.files.blacklisted.auto ?? 0),
        metrics.value?.files.blacklisted.manual ?? 0,
        metrics.value?.files.blacklisted.auto ?? 0,
    ),
);
const overviewTicks = computed(() => buildTickValues(overviewMax.value, 5));
const reactionTicks = computed(() => buildTickValues(reactionMax.value, 5));
const blacklistTicks = computed(() => buildTickValues(blacklistMax.value, 5));
const overviewAxisLabel = (value: number) =>
    overviewChartData.value.find((item) => item.index === value)?.label ?? '';
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

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-6">
                    <div>
                        <h2 class="text-lg font-semibold text-regal-navy-100">Library overview</h2>
                        <p class="text-sm text-twilight-indigo-200">
                            Inventory health and storage coverage at a glance.
                        </p>
                    </div>

                    <div v-if="isLoading" class="space-y-4">
                        <Skeleton class="h-40 w-full" />
                        <Skeleton class="h-4 w-1/2" />
                    </div>

                    <ChartContainer v-else :config="overviewChartConfig" class="h-[180px] w-full max-w-[420px] mx-auto">
                        <VisXYContainer :data="overviewChartData">
                            <VisStackedBar
                                :x="(d: OverviewChartDatum) => d.index"
                                :y="[
                                    (d: OverviewChartDatum) => d.total,
                                    (d: OverviewChartDatum) => d.not_found,
                                    (d: OverviewChartDatum) => d.downloaded,
                                    (d: OverviewChartDatum) => d.local,
                                    (d: OverviewChartDatum) => d.non_local,
                                ]"
                                :color="[
                                    overviewChartConfig.total.color,
                                    overviewChartConfig.not_found.color,
                                    overviewChartConfig.downloaded.color,
                                    overviewChartConfig.local.color,
                                    overviewChartConfig.non_local.color,
                                ]"
                                :data-step="1"
                                :bar-max-width="40"
                            />
                            <VisAxis
                                type="x"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="false"
                                :tick-values="overviewChartData.map((item) => item.index)"
                                :tick-format="overviewAxisLabel"
                            />
                            <VisAxis
                                type="y"
                                :tick-line="false"
                                :domain-line="false"
                                :grid-line="true"
                                :tick-format="formatCount"
                                :tick-values="overviewTicks"
                            />
                            <ChartTooltip />
                            <ChartCrosshair
                                :template="overviewTooltip"
                                :color="[
                                    overviewChartConfig.total.color,
                                    overviewChartConfig.not_found.color,
                                    overviewChartConfig.downloaded.color,
                                    overviewChartConfig.local.color,
                                    overviewChartConfig.non_local.color,
                                ]"
                            />
                        </VisXYContainer>
                        <ChartLegendContent class="pt-4 text-sm text-twilight-indigo-200" />
                    </ChartContainer>

                    <div class="flex justify-center gap-10 text-sm mt-10">
                        <div v-for="item in overviewSummary" :key="item.label" class="flex gap-2">
                            <div class="text-twilight-indigo-200">{{ item.label }}</div>
                            <div class="font-semibold" :style="{ color: item.color }">{{ formatCount(item.value) }}</div>
                        </div>
                    </div>
                </div>
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

            <div class="rounded-lg border border-twilight-indigo-500/40 bg-prussian-blue-600 p-6 space-y-6">
                <div class="flex flex-col gap-2">
                    <h2 class="text-lg font-semibold text-regal-navy-100">Containers</h2>
                    <p class="text-sm text-twilight-indigo-200">
                        Top containers by downloads, favorites, and blacklisted files.
                    </p>
                    <div class="flex gap-6 text-sm text-twilight-indigo-200">
                        <div>
                            Total containers:
                            <span class="font-semibold text-regal-navy-100">{{ formatCount(containerTotals.total) }}</span>
                        </div>
                        <div>
                            Blacklisted containers:
                            <span class="font-semibold text-regal-navy-100">{{ formatCount(containerTotals.blacklisted) }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="isLoading" class="space-y-4">
                    <Skeleton class="h-40 w-full" />
                </div>

                <div v-else class="grid gap-6 lg:grid-cols-3">
                    <div class="space-y-3">
                        <div class="text-sm font-semibold text-regal-navy-100">Top downloads</div>
                        <div class="space-y-2">
                            <div
                                v-for="item in topDownloadContainers"
                                :key="`downloads-${item.id}`"
                                class="flex items-center justify-between gap-3 text-xs text-twilight-indigo-200"
                            >
                                <span class="truncate" :title="formatContainerLabel(item)">{{ formatContainerLabel(item) }}</span>
                                <span class="font-semibold text-regal-navy-100">{{ formatCount(item.files_count) }}</span>
                            </div>
                            <div v-if="topDownloadContainers.length === 0" class="text-xs text-twilight-indigo-300">
                                No containers yet.
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="text-sm font-semibold text-regal-navy-100">Top favorites</div>
                        <div class="space-y-2">
                            <div
                                v-for="item in topFavoriteContainers"
                                :key="`favorites-${item.id}`"
                                class="flex items-center justify-between gap-3 text-xs text-twilight-indigo-200"
                            >
                                <span class="truncate" :title="formatContainerLabel(item)">{{ formatContainerLabel(item) }}</span>
                                <span class="font-semibold text-regal-navy-100">{{ formatCount(item.files_count) }}</span>
                            </div>
                            <div v-if="topFavoriteContainers.length === 0" class="text-xs text-twilight-indigo-300">
                                No containers yet.
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div class="text-sm font-semibold text-regal-navy-100">Top blacklisted</div>
                        <div class="space-y-2">
                            <div
                                v-for="item in topBlacklistedContainers"
                                :key="`blacklisted-${item.id}`"
                                class="flex items-center justify-between gap-3 text-xs text-twilight-indigo-200"
                            >
                                <span class="truncate" :title="formatContainerLabel(item)">{{ formatContainerLabel(item) }}</span>
                                <span class="font-semibold text-regal-navy-100">{{ formatCount(item.files_count) }}</span>
                            </div>
                            <div v-if="topBlacklistedContainers.length === 0" class="text-xs text-twilight-indigo-300">
                                No containers yet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
