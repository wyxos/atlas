import type { ChartConfig } from '@/components/ui/chart';
import type {
    ContainerMetricItem,
    DashboardChartSection,
    DashboardContainerGroup,
    DashboardContainerTotals,
    DashboardMetrics,
} from '@/types/dashboard';

export const reactionChartConfig = {
    love: { label: 'Favorite', color: '#ef4444' },
    like: { label: 'Like', color: 'var(--color-smart-blue-500)' },
    dislike: { label: 'Dislike', color: '#6b7280' },
    funny: { label: 'Funny', color: '#eab308' },
    unreacted: { label: 'Unreacted', color: 'var(--color-blue-slate-400)' },
} satisfies ChartConfig;

export const overviewChartConfig = {
    total: { label: 'Total', color: 'var(--color-blue-slate-200)' },
    not_found: { label: 'Not found', color: 'var(--color-danger-300)' },
    downloaded: { label: 'Downloaded', color: 'var(--color-success-400)' },
    local: { label: 'Local', color: 'var(--color-smart-blue-500)' },
    non_local: { label: 'Non-local', color: 'var(--color-twilight-indigo-300)' },
} satisfies ChartConfig;

export const negativeOutcomeChartConfig = {
    blacklisted: { label: 'Blacklisted', color: 'var(--color-danger-300)' },
    auto_disliked: { label: 'Auto disliked', color: '#6b7280' },
} satisfies ChartConfig;

export function formatDashboardCount(value: number): string {
    return value.toLocaleString();
}

export function buildDashboardTickValues(maxValue: number, steps = 5): number[] {
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
}

export function createDashboardChartSections(metrics: DashboardMetrics | null): DashboardChartSection[] {
    const files = metrics?.files;

    return [
        {
            key: 'overview',
            title: 'Library overview',
            description: 'Inventory health and storage coverage at a glance.',
            tooltipLabel: 'Overview',
            config: overviewChartConfig,
            seriesKeys: ['total', 'not_found', 'downloaded', 'local', 'non_local'],
            data: [
                { index: 0, label: 'Total', total: files?.total ?? 0, not_found: 0, downloaded: 0, local: 0, non_local: 0 },
                {
                    index: 1,
                    label: 'Not found',
                    total: 0,
                    not_found: files?.not_found ?? 0,
                    downloaded: 0,
                    local: 0,
                    non_local: 0,
                },
                {
                    index: 2,
                    label: 'Downloaded',
                    total: 0,
                    not_found: 0,
                    downloaded: files?.downloaded ?? 0,
                    local: 0,
                    non_local: 0,
                },
                { index: 3, label: 'Local', total: 0, not_found: 0, downloaded: 0, local: files?.local ?? 0, non_local: 0 },
                {
                    index: 4,
                    label: 'Non-local',
                    total: 0,
                    not_found: 0,
                    downloaded: 0,
                    local: 0,
                    non_local: files?.non_local ?? 0,
                },
            ],
            summary: [
                { label: 'Total', value: files?.total ?? 0, color: overviewChartConfig.total.color },
                { label: 'Not found', value: files?.not_found ?? 0, color: overviewChartConfig.not_found.color },
                { label: 'Downloaded', value: files?.downloaded ?? 0, color: overviewChartConfig.downloaded.color },
                { label: 'Local', value: files?.local ?? 0, color: overviewChartConfig.local.color },
                { label: 'Non-local', value: files?.non_local ?? 0, color: overviewChartConfig.non_local.color },
            ],
        },
        {
            key: 'reactions',
            title: 'Reactions',
            description: 'Files with at least one reaction by type.',
            tooltipLabel: 'Reactions',
            config: reactionChartConfig,
            seriesKeys: ['love', 'like', 'dislike', 'funny', 'unreacted'],
            data: [
                { index: 0, label: 'Favorite', love: files?.reactions.love ?? 0, like: 0, dislike: 0, funny: 0, unreacted: 0 },
                { index: 1, label: 'Like', love: 0, like: files?.reactions.like ?? 0, dislike: 0, funny: 0, unreacted: 0 },
                { index: 2, label: 'Dislike', love: 0, like: 0, dislike: files?.reactions.dislike ?? 0, funny: 0, unreacted: 0 },
                { index: 3, label: 'Funny', love: 0, like: 0, dislike: 0, funny: files?.reactions.funny ?? 0, unreacted: 0 },
                {
                    index: 4,
                    label: 'Unreacted',
                    love: 0,
                    like: 0,
                    dislike: 0,
                    funny: 0,
                    unreacted: files?.unreacted_not_blacklisted ?? 0,
                },
            ],
            summary: [
                { label: 'Favorite', value: files?.reactions.love ?? 0, color: reactionChartConfig.love.color },
                { label: 'Like', value: files?.reactions.like ?? 0, color: reactionChartConfig.like.color },
                { label: 'Dislike', value: files?.reactions.dislike ?? 0, color: reactionChartConfig.dislike.color },
                { label: 'Funny', value: files?.reactions.funny ?? 0, color: reactionChartConfig.funny.color },
                {
                    label: 'Unreacted',
                    value: files?.unreacted_not_blacklisted ?? 0,
                    color: reactionChartConfig.unreacted.color,
                },
            ],
        },
        {
            key: 'negative',
            title: 'Negative outcomes',
            description: 'Backend blacklists and auto-dislikes.',
            tooltipLabel: 'Negative outcomes',
            config: negativeOutcomeChartConfig,
            seriesKeys: ['blacklisted', 'auto_disliked'],
            data: [
                { index: 0, label: 'Blacklisted', blacklisted: files?.blacklisted ?? 0, auto_disliked: 0 },
                { index: 1, label: 'Auto disliked', blacklisted: 0, auto_disliked: files?.auto_disliked ?? 0 },
            ],
            summary: [
                { label: 'Blacklisted', value: files?.blacklisted ?? 0, color: negativeOutcomeChartConfig.blacklisted.color },
                { label: 'Auto disliked', value: files?.auto_disliked ?? 0, color: negativeOutcomeChartConfig.auto_disliked.color },
            ],
        },
    ];
}

export function createDashboardContainerTotals(metrics: DashboardMetrics | null): DashboardContainerTotals {
    return {
        total: metrics?.containers.total ?? 0,
        blacklisted: metrics?.containers.blacklisted ?? 0,
    };
}

export function createDashboardContainerGroups(metrics: DashboardMetrics | null): DashboardContainerGroup[] {
    return [
        {
            key: 'downloads',
            title: 'Top downloads',
            items: metrics?.containers.top_downloads ?? [],
        },
        {
            key: 'favorites',
            title: 'Top favorites',
            items: metrics?.containers.top_favorites ?? [],
        },
        {
            key: 'blacklisted',
            title: 'Top blacklisted',
            items: metrics?.containers.top_blacklisted ?? [],
        },
    ];
}

export function formatDashboardContainerLabel(item: ContainerMetricItem): string {
    return `${item.type} • ${item.source} • ${item.source_id}`;
}
