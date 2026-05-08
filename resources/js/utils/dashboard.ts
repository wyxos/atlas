import type {
    ContainerMetricItem,
    DashboardContainerGroup,
    DashboardContainerTotals,
    DashboardCoverage,
    DashboardMetricDistribution,
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
    const unreacted = files?.unreacted_not_blacklisted ?? 0;
    const removed = files?.blacklisted ?? 0;
    const kept = Math.max(0, total - unseen - pending - removed);
    const moderated = kept + removed;
    const segments = [
        createCoverageSegment('unseen', 'Unseen', unseen, total, colors.unseen),
        createCoverageSegment('pending', 'Seen, no decision', pending, total, colors.pending),
        createCoverageSegment('kept', 'Kept', kept, total, colors.kept),
        createCoverageSegment('removed', 'Removed', removed, total, colors.removed),
    ];

    return {
        total,
        moderated,
        moderatedPercent: formatDashboardPercent(percent(moderated, total)),
        segments,
        distributions: [
            createMetricDistribution('coverage', 'Coverage split', 'Unseen, pending, kept, and removed files', segments),
            createMetricDistribution('backlog', 'Backlog split', 'Unseen files vs seen files still waiting for a decision', [
                createMetricRow('unseen', 'Not previewed', unseen, colors.unseen, unreacted, 'of backlog'),
                createMetricRow('pending', 'Previewed, no decision', pending, colors.pending, unreacted, 'of backlog'),
            ]),
        ],
    };
}

export function createDashboardMetricPanels(metrics: DashboardMetrics | null): DashboardMetricPanel[] {
    const files = metrics?.files;
    const total = files?.total ?? 0;
    const downloaded = files?.downloaded ?? 0;
    const local = files?.local ?? 0;
    const nonLocal = files?.non_local ?? 0;
    const notFound = files?.not_found ?? 0;
    const blacklisted = files?.blacklisted ?? 0;
    const manualBlacklisted = files?.blacklisted_manual ?? 0;
    const autoBlacklisted = files?.auto_blacklisted ?? 0;
    const outOfFeed = files?.blacklisted_feed_removed ?? 0;
    const blacklistedInFeed = Math.max(0, blacklisted - outOfFeed);

    return [
        {
            key: 'library',
            title: 'Library health',
            description: 'Inventory source and availability distribution.',
            summaryRows: [
                createMetricRow('total', 'Total files', total, colors.total),
            ],
            distributions: [
                createMetricDistribution('source', 'Source split', 'Local files vs external references', [
                    createMetricRow('local', 'Local', local, colors.local, total, 'of library'),
                    createMetricRow('non-local', 'Non-local', nonLocal, colors.nonLocal, total, 'of library'),
                ]),
                createMetricDistribution('storage', 'Storage state', 'Downloaded assets vs remote-only records', [
                    createMetricRow('downloaded', 'Downloaded', downloaded, colors.downloaded, total, 'of library'),
                    createMetricRow('remote-only', 'Remote only', Math.max(0, total - downloaded), colors.unseen, total, 'of library'),
                ]),
                createMetricDistribution('availability', 'Availability', 'Reachable records vs missing source files', [
                    createMetricRow('available', 'Available', Math.max(0, total - notFound), colors.total, total, 'of library'),
                    createMetricRow('not-found', 'Not found', notFound, colors.notFound, total, 'of library'),
                ]),
            ],
            rows: [],
        },
        {
            key: 'removal',
            title: 'Removal impact',
            description: 'Blacklist source and feed rotation impact.',
            summaryRows: [
                createMetricRow('blacklisted', 'Total blacklisted', blacklisted, colors.removed, total, 'of library'),
                createMetricRow('out-of-feed', 'Out of feed', outOfFeed, colors.feed, blacklisted, 'of blacklisted'),
            ],
            distributions: [
                createMetricDistribution('blacklist-source', 'Blacklist source', 'Manual removals vs backend-applied removals', [
                    createMetricRow('manual', 'Manual', manualBlacklisted, colors.pending, blacklisted, 'of blacklisted'),
                    createMetricRow('auto', 'Auto', autoBlacklisted, colors.auto, blacklisted, 'of blacklisted'),
                ]),
                createMetricDistribution('feed-state', 'Feed state', 'Removed from normal rotation vs still visible in feed', [
                    createMetricRow('out-of-feed', 'Out of feed', outOfFeed, colors.feed, blacklisted, 'of blacklisted'),
                    createMetricRow('blacklisted-in-feed', 'Still in feed', blacklistedInFeed, colors.auto, blacklisted, 'of blacklisted'),
                ]),
            ],
            rows: [],
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
    const total = rows.reduce((sum, row) => sum + row.value, 0);

    return {
        title: 'Positive outcomes',
        description: 'Ranked positive signals by reaction type.',
        total,
        rows: rows.map((row) => ({
            ...row,
            barPercent: percent(row.value, total),
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

function createMetricDistribution(
    key: string,
    label: string,
    meta: string,
    segments: DashboardMetricRow[],
): DashboardMetricDistribution {
    return {
        key,
        label,
        meta,
        segments,
    };
}

function percent(value: number, denominator: number): number {
    if (denominator <= 0 || value <= 0) {
        return 0;
    }

    return Math.min(100, (value / denominator) * 100);
}
