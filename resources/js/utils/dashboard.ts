import type {
    ContainerMetricItem,
    DashboardContainerGroup,
    DashboardContainerTotals,
    DashboardCoverage,
    DashboardMetricDistribution,
    DashboardMetricIcon,
    DashboardMetricPanel,
    DashboardMetricRow,
    DashboardMetrics,
} from '@/types/dashboard';

const colors = {
    total: 'var(--color-blue-slate-200)',
    downloaded: 'var(--color-success-400)',
    stored: '#14b8a6',
    recordsOnly: 'var(--color-blue-slate-500)',
    imported: 'var(--color-smart-blue-500)',
    notFound: 'var(--color-danger-300)',
    online: '#38bdf8',
    image: 'var(--color-success-400)',
    video: '#38bdf8',
    audio: '#a78bfa',
    other: 'var(--color-blue-slate-500)',
    previewed: 'var(--color-smart-blue-500)',
    notPreviewed: 'var(--color-blue-slate-500)',
    unseen: 'var(--color-blue-slate-500)',
    pending: '#f97316',
    kept: 'var(--color-success-400)',
    removed: 'var(--color-danger-300)',
    favorite: '#ef4444',
    like: 'var(--color-smart-blue-500)',
    funny: '#eab308',
    reacted: 'var(--color-success-400)',
    unreacted: '#f97316',
    auto: '#6b7280',
    feed: '#eab308',
};

const metricIcons: Record<string, DashboardMetricIcon> = {
    total: 'circle',
    stored: 'hard-drive',
    online: 'cloud',
    'not-found': 'file-x',
    downloaded: 'download',
    imported: 'import',
    image: 'image',
    video: 'video',
    audio: 'music',
    other: 'file',
    previewed: 'eye',
    'not-previewed': 'eye-off',
    unseen: 'eye-off',
    pending: 'eye',
    kept: 'thumbs-up',
    removed: 'ban',
    reacted: 'smile',
    unreacted: 'circle-slash',
    favorite: 'heart',
    like: 'thumbs-up',
    funny: 'smile',
    'manual-in-feed': 'user',
    'auto-in-feed': 'bot',
    'out-of-feed': 'archive',
};

export function formatDashboardCount(value: number): string {
    return value.toLocaleString('en-US');
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
    const rawTotal = files?.total ?? 0;
    const reactions = files?.reactions;
    const removed = files?.blacklisted ?? 0;
    const total = Math.max(0, rawTotal - removed);
    const previewed = Math.min(files?.previewed_not_blacklisted ?? 0, total);
    const notPreviewed = Math.max(0, total - previewed);
    const reactionTotal = total;
    const reacted = Math.min(files?.reacted ?? 0, reactionTotal);
    const unreacted = Math.max(0, reactionTotal - reacted);
    const manualInFeed = files?.blacklisted_manual_in_feed ?? 0;
    const autoInFeed = files?.blacklisted_auto_in_feed ?? 0;
    const outOfFeed = files?.blacklisted_feed_removed ?? 0;
    const favoriteReactions = reactions?.love ?? 0;
    const likeReactions = reactions?.like ?? 0;
    const funnyReactions = reactions?.funny ?? 0;
    const reactionSignals = favoriteReactions + likeReactions + funnyReactions;
    const reactionRows = [
        createMetricRow('favorite', 'Favorite', favoriteReactions, colors.favorite, reactionSignals, 'of reaction signals'),
        createMetricRow('like', 'Like', likeReactions, colors.like, reactionSignals, 'of reaction signals'),
        createMetricRow('funny', 'Funny', funnyReactions, colors.funny, reactionSignals, 'of reaction signals'),
    ];
    const segments = [
        createCoverageSegment('previewed', 'Previewed', previewed, total, colors.previewed),
        createCoverageSegment('not-previewed', 'Not previewed', notPreviewed, total, colors.notPreviewed),
    ];

    return {
        total,
        previewed,
        previewedPercent: formatDashboardPercent(percent(previewed, total)),
        segments,
        distributions: [
            createMetricDistribution('coverage', 'Preview state', 'Previewed and not previewed files, excluding removed files', segments, total),
            createMetricDistribution('reaction-state', 'Reaction state', 'Reacted and unreacted files, excluding blacklisted files', [
                createMetricRow('reacted', 'Reacted', reacted, colors.reacted, reactionTotal, 'of non-blacklisted files'),
                createMetricRow('unreacted', 'Unreacted', unreacted, colors.unreacted, reactionTotal, 'of non-blacklisted files'),
            ], reactionTotal),
            createMetricDistribution('reaction-types', 'Reaction types', 'Reaction signals by type', reactionRows, reactionSignals),
            createMetricDistribution('removal-state', 'Removal state', 'Manual and auto counts only include blacklisted records still in feed', [
                createMetricRow('manual-in-feed', 'Manual', manualInFeed, colors.pending, removed, 'of blacklisted'),
                createMetricRow('auto-in-feed', 'Auto', autoInFeed, colors.auto, removed, 'of blacklisted'),
                createMetricRow('out-of-feed', 'Out of feed', outOfFeed, colors.feed, removed, 'of blacklisted'),
            ], removed),
        ],
    };
}

export function createDashboardMetricPanels(metrics: DashboardMetrics | null): DashboardMetricPanel[] {
    const files = metrics?.files;
    const total = files?.total ?? 0;
    const downloaded = files?.downloaded ?? 0;
    const stored = files?.stored ?? 0;
    const recordsOnly = files?.records_only ?? Math.max(0, total - stored);
    const importedStored = Math.max(0, stored - downloaded);
    const notFound = files?.not_found ?? 0;
    const notFoundRecords = Math.min(notFound, recordsOnly);
    const onlineRecords = Math.max(0, recordsOnly - notFoundRecords);
    const fileTypes = files?.file_types ?? {
        image: 0,
        video: 0,
        audio: 0,
        other: 0,
    };

    return [
        {
            key: 'inventory',
            title: 'Library inventory',
            description: 'Records by disk coverage and stored origin.',
            summaryRows: [
                createMetricRow('total', 'Total records', total, colors.total),
                createMetricRow('stored', 'On disk', stored, colors.stored, total, 'of records'),
            ],
            distributions: [
                createMetricDistribution('storage-coverage', 'Storage coverage', 'On-disk files, online records, and missing source records', [
                    createMetricRow('stored', 'On disk', stored, colors.stored, total, 'of records'),
                    createMetricRow('online', 'Online records', onlineRecords, colors.online, total, 'of records'),
                    createMetricRow('not-found', 'Not found', notFoundRecords, colors.notFound, total, 'of records'),
                ], total),
                createMetricDistribution('stored-origin', 'Stored origin', 'Downloaded vs imported files on disk', [
                    createMetricRow('downloaded', 'Downloaded', downloaded, colors.downloaded, stored, 'of stored media'),
                    createMetricRow('imported', 'Imported', importedStored, colors.imported, stored, 'of stored media'),
                ], stored),
                createMetricDistribution('file-types', 'File types', 'Records grouped by media type', [
                    createMetricRow('image', 'Images', fileTypes.image, colors.image, total, 'of records'),
                    createMetricRow('video', 'Videos', fileTypes.video, colors.video, total, 'of records'),
                    createMetricRow('audio', 'Audio', fileTypes.audio, colors.audio, total, 'of records'),
                    createMetricRow('other', 'Other', fileTypes.other, colors.other, total, 'of records'),
                ], total),
            ],
            rows: [],
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
        icon: metricIcon(key),
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
        denominator,
        icon: metricIcon(key),
        barPercent,
        meta: barPercent === undefined ? undefined : `${formatDashboardPercent(barPercent)} ${basisLabel}`,
    };
}

function createMetricDistribution(
    key: string,
    label: string,
    meta: string,
    segments: DashboardMetricRow[],
    total = segments.reduce((sum, segment) => sum + segment.value, 0),
): DashboardMetricDistribution {
    return {
        key,
        label,
        meta,
        total,
        segments,
    };
}

function percent(value: number, denominator: number): number {
    if (denominator <= 0 || value <= 0) {
        return 0;
    }

    return Math.min(100, (value / denominator) * 100);
}

function metricIcon(key: string): DashboardMetricIcon {
    return metricIcons[key] ?? 'circle';
}
