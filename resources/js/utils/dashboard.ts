import type {
    ContainerMetricItem,
    DashboardContainerGroup,
    DashboardContainerTotals,
    DashboardCoverage,
    DashboardMetricPanel,
    DashboardMetricRow,
    DashboardMetrics,
    DashboardPositiveOutcomes,
} from '@/types/dashboard';

const colors = {
    total: 'var(--color-blue-slate-200)',
    downloaded: 'var(--color-success-400)',
    local: 'var(--color-smart-blue-500)',
    nonLocal: 'var(--color-twilight-indigo-300)',
    notFound: 'var(--color-danger-300)',
    unseen: 'var(--color-blue-slate-500)',
    pending: '#f97316',
    kept: 'var(--color-success-400)',
    removed: 'var(--color-danger-300)',
    favorite: '#ef4444',
    like: 'var(--color-smart-blue-500)',
    funny: '#eab308',
    auto: '#6b7280',
    feed: '#eab308',
};

export function formatDashboardCount(value: number): string {
    return value.toLocaleString();
}

export function formatDashboardPercent(value: number): string {
    if (value === 0) {
        return '0%';
    }

    if (value > 0 && value < 0.1) {
        return '<0.1%';
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)}%`;
}

export function createDashboardCoverage(metrics: DashboardMetrics | null): DashboardCoverage {
    const files = metrics?.files;
    const total = files?.total ?? 0;
    const unseen = files?.unreacted_unpreviewed_not_blacklisted ?? 0;
    const pending = files?.unreacted_previewed_not_blacklisted ?? 0;
    const removed = files?.blacklisted ?? 0;
    const kept = Math.max(0, total - unseen - pending - removed);
    const moderated = kept + removed;

    return {
        total,
        moderated,
        moderatedPercent: formatDashboardPercent(percent(moderated, total)),
        segments: [
            createCoverageSegment('unseen', 'Unseen', unseen, total, colors.unseen),
            createCoverageSegment('pending', 'Seen, no decision', pending, total, colors.pending),
            createCoverageSegment('kept', 'Kept', kept, total, colors.kept),
            createCoverageSegment('removed', 'Removed', removed, total, colors.removed),
        ],
    };
}

export function createDashboardMetricPanels(metrics: DashboardMetrics | null): DashboardMetricPanel[] {
    const files = metrics?.files;
    const total = files?.total ?? 0;
    const unreacted = files?.unreacted_not_blacklisted ?? 0;
    const blacklisted = files?.blacklisted ?? 0;
    const outOfFeed = files?.blacklisted_feed_removed ?? 0;
    const decided = Math.max(0, total - unreacted);

    return [
        {
            key: 'library',
            title: 'Library health',
            description: 'Inventory coverage and file availability.',
            rows: [
                createMetricRow('total', 'Total files', total, colors.total),
                createMetricRow('downloaded', 'Downloaded', files?.downloaded ?? 0, colors.downloaded, total, 'of library'),
                createMetricRow('local', 'Local', files?.local ?? 0, colors.local, total, 'of library'),
                createMetricRow('non-local', 'Non-local', files?.non_local ?? 0, colors.nonLocal, total, 'of library'),
                createMetricRow('not-found', 'Not found', files?.not_found ?? 0, colors.notFound, total, 'of library'),
            ],
        },
        {
            key: 'backlog',
            title: 'Review backlog',
            description: 'Files still waiting for a decision.',
            rows: [
                createMetricRow('decided', 'Decision coverage', decided, colors.kept, total, 'of library'),
                createMetricRow('unseen', 'Not previewed', files?.unreacted_unpreviewed_not_blacklisted ?? 0, colors.unseen, total, 'of library'),
                createMetricRow('pending', 'Previewed, no decision', files?.unreacted_previewed_not_blacklisted ?? 0, colors.pending, total, 'of library'),
            ],
        },
        {
            key: 'filtered',
            title: 'Filtered / removed',
            description: 'Blacklist volume and source split.',
            rows: [
                createMetricRow('blacklisted', 'Total blacklisted', blacklisted, colors.removed, total, 'of library'),
                createMetricRow('manual', 'Manual blacklist', files?.blacklisted_manual ?? 0, colors.pending, blacklisted, 'of blacklisted'),
                createMetricRow('auto', 'Auto blacklist', files?.auto_blacklisted ?? 0, colors.auto, blacklisted, 'of blacklisted'),
            ],
        },
        {
            key: 'feed',
            title: 'Feed impact',
            description: 'Blacklisted files pushed out of normal feed rotation.',
            rows: [
                createMetricRow('out-of-feed', 'Out of feed', outOfFeed, colors.feed, blacklisted, 'of blacklisted'),
                createMetricRow('blacklisted-in-feed', 'Still in feed', Math.max(0, blacklisted - outOfFeed), colors.auto, blacklisted, 'of blacklisted'),
                createMetricRow('library-share', 'Library share', outOfFeed, colors.feed, total, 'of library'),
            ],
        },
    ];
}

export function createDashboardPositiveOutcomes(metrics: DashboardMetrics | null): DashboardPositiveOutcomes {
    const reactions = metrics?.files.reactions;
    const rows = [
        { key: 'favorite', label: 'Favorite', value: reactions?.love ?? 0, color: colors.favorite },
        { key: 'like', label: 'Like', value: reactions?.like ?? 0, color: colors.like },
        { key: 'funny', label: 'Funny', value: reactions?.funny ?? 0, color: colors.funny },
    ];
    const maxValue = Math.max(...rows.map((row) => row.value), 0);
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    return {
        title: 'Positive outcomes',
        description: 'Ranked positive signals by reaction type.',
        total,
        rows: rows.map((row) => ({
            ...row,
            barPercent: percent(row.value, maxValue),
            meta: `${formatDashboardPercent(percent(row.value, total))} of positive signals`,
        })),
    };
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

function createCoverageSegment(
    key: DashboardCoverage['segments'][number]['key'],
    label: string,
    value: number,
    total: number,
    color: string,
): DashboardCoverage['segments'][number] {
    return {
        key,
        label,
        value,
        barPercent: percent(value, total),
        color,
    };
}

function createMetricRow(
    key: string,
    label: string,
    value: number,
    color: string,
    denominator?: number,
    basisLabel?: string,
): DashboardMetricRow {
    const barPercent = denominator === undefined ? undefined : percent(value, denominator);

    return {
        key,
        label,
        value,
        color,
        barPercent,
        meta: barPercent === undefined ? undefined : `${formatDashboardPercent(barPercent)} ${basisLabel}`,
    };
}

function percent(value: number, denominator: number): number {
    if (denominator <= 0 || value <= 0) {
        return 0;
    }

    return Math.min(100, (value / denominator) * 100);
}
