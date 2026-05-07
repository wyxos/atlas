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
    funny: { label: 'Funny', color: '#eab308' },
    unreacted_previewed: { label: 'Unreacted previewed', color: '#f97316' },
    unreacted_unpreviewed: { label: 'Unreacted not previewed', color: 'var(--color-blue-slate-400)' },
} satisfies ChartConfig;

export const overviewChartConfig = {
    total: { label: 'Total', color: 'var(--color-blue-slate-200)' },
    not_found: { label: 'Not found', color: 'var(--color-danger-300)' },
    downloaded: { label: 'Downloaded', color: 'var(--color-success-400)' },
    local: { label: 'Local', color: 'var(--color-smart-blue-500)' },
    non_local: { label: 'Non-local', color: 'var(--color-twilight-indigo-300)' },
} satisfies ChartConfig;

export const negativeOutcomeChartConfig = {
    blacklisted: { label: 'Total blacklisted', color: 'var(--color-danger-300)' },
    blacklisted_manual: { label: 'Manual blacklist', color: '#f97316' },
    auto_blacklisted: { label: 'Auto blacklist', color: '#6b7280' },
    blacklisted_feed_removed: { label: 'Out of feed', color: '#eab308' },
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
            description: 'Reacted files and unreacted files split by preview state.',
            tooltipLabel: 'Reactions',
            config: reactionChartConfig,
            seriesKeys: ['love', 'like', 'funny', 'unreacted_previewed', 'unreacted_unpreviewed'],
            data: [
                { index: 0, label: 'Favorite', love: files?.reactions.love ?? 0, like: 0, funny: 0, unreacted_previewed: 0, unreacted_unpreviewed: 0 },
                { index: 1, label: 'Like', love: 0, like: files?.reactions.like ?? 0, funny: 0, unreacted_previewed: 0, unreacted_unpreviewed: 0 },
                { index: 2, label: 'Funny', love: 0, like: 0, funny: files?.reactions.funny ?? 0, unreacted_previewed: 0, unreacted_unpreviewed: 0 },
                {
                    index: 3,
                    label: 'Previewed',
                    love: 0,
                    like: 0,
                    funny: 0,
                    unreacted_previewed: files?.unreacted_previewed_not_blacklisted ?? 0,
                    unreacted_unpreviewed: 0,
                },
                {
                    index: 4,
                    label: 'Not previewed',
                    love: 0,
                    like: 0,
                    funny: 0,
                    unreacted_previewed: 0,
                    unreacted_unpreviewed: files?.unreacted_unpreviewed_not_blacklisted ?? 0,
                },
            ],
            summary: [
                { label: 'Favorite', value: files?.reactions.love ?? 0, color: reactionChartConfig.love.color },
                { label: 'Like', value: files?.reactions.like ?? 0, color: reactionChartConfig.like.color },
                { label: 'Funny', value: files?.reactions.funny ?? 0, color: reactionChartConfig.funny.color },
                {
                    label: 'Unreacted previewed',
                    value: files?.unreacted_previewed_not_blacklisted ?? 0,
                    color: reactionChartConfig.unreacted_previewed.color,
                },
                {
                    label: 'Unreacted not previewed',
                    value: files?.unreacted_unpreviewed_not_blacklisted ?? 0,
                    color: reactionChartConfig.unreacted_unpreviewed.color,
                },
            ],
        },
        {
            key: 'negative',
            title: 'Negative outcomes',
            description: 'Blacklist totals, source split, and out-of-feed terminal state.',
            tooltipLabel: 'Negative outcomes',
            config: negativeOutcomeChartConfig,
            seriesKeys: ['blacklisted', 'blacklisted_manual', 'auto_blacklisted', 'blacklisted_feed_removed'],
            data: [
                { index: 0, label: 'Total', blacklisted: files?.blacklisted ?? 0, blacklisted_manual: 0, auto_blacklisted: 0, blacklisted_feed_removed: 0 },
                { index: 1, label: 'Manual', blacklisted: 0, blacklisted_manual: files?.blacklisted_manual ?? 0, auto_blacklisted: 0, blacklisted_feed_removed: 0 },
                { index: 2, label: 'Auto', blacklisted: 0, blacklisted_manual: 0, auto_blacklisted: files?.auto_blacklisted ?? 0, blacklisted_feed_removed: 0 },
                {
                    index: 3,
                    label: 'Out',
                    blacklisted: 0,
                    blacklisted_manual: 0,
                    auto_blacklisted: 0,
                    blacklisted_feed_removed: files?.blacklisted_feed_removed ?? 0,
                },
            ],
            summary: [
                { label: 'Total blacklisted', value: files?.blacklisted ?? 0, color: negativeOutcomeChartConfig.blacklisted.color },
                { label: 'Manual blacklist', value: files?.blacklisted_manual ?? 0, color: negativeOutcomeChartConfig.blacklisted_manual.color },
                { label: 'Auto blacklist', value: files?.auto_blacklisted ?? 0, color: negativeOutcomeChartConfig.auto_blacklisted.color },
                {
                    label: 'Out of feed',
                    value: files?.blacklisted_feed_removed ?? 0,
                    color: negativeOutcomeChartConfig.blacklisted_feed_removed.color,
                },
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
