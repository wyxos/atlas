export type ContainerMetricItem = {
    id: number;
    type: string;
    source: string;
    source_id: string;
    referrer: string | null;
    browse_tab?: DashboardContainerTabPayload | null;
    files_count: number;
};

export type DashboardMetrics = {
    files: {
        total: number;
        downloaded: number;
        stored: number;
        records_only: number;
        local: number;
        non_local: number;
        local_available: number;
        non_local_available: number;
        file_types: {
            image: number;
            video: number;
            audio: number;
            other: number;
        };
        reactions: {
            love: number;
            like: number;
            funny: number;
        };
        reacted: number;
        unreacted: number;
        blacklisted: number;
        blacklisted_manual: number;
        blacklisted_feed_removed: number;
        blacklisted_manual_in_feed: number;
        blacklisted_auto_in_feed: number;
        auto_blacklisted: number;
        not_found: number;
        previewed_not_blacklisted: number;
        unpreviewed_not_blacklisted: number;
        unreacted_not_blacklisted: number;
        unreacted_previewed_not_blacklisted: number;
        unreacted_unpreviewed_not_blacklisted: number;
    };
    containers: {
        total: number;
        blacklisted: number;
        top_downloads: ContainerMetricItem[];
        top_favorites: ContainerMetricItem[];
        top_blacklisted: ContainerMetricItem[];
    };
};

export type DashboardMetricIcon =
    | 'archive'
    | 'ban'
    | 'bot'
    | 'circle'
    | 'circle-slash'
    | 'cloud'
    | 'download'
    | 'eye'
    | 'eye-off'
    | 'file'
    | 'file-x'
    | 'hard-drive'
    | 'heart'
    | 'image'
    | 'import'
    | 'music'
    | 'smile'
    | 'thumbs-up'
    | 'user'
    | 'video';

export type DashboardCoverageSegment = {
    key: 'previewed' | 'not-previewed';
    label: string;
    value: number;
    barPercent: number;
    color: string;
    icon: DashboardMetricIcon;
};

export type DashboardCoverage = {
    total: number;
    previewed: number;
    previewedPercent: string;
    segments: DashboardCoverageSegment[];
    distributions: DashboardMetricDistribution[];
};

export type DashboardMetricRow = {
    key: string;
    label: string;
    value: number;
    denominator?: number;
    icon: DashboardMetricIcon;
    meta?: string;
    barPercent?: number;
    color: string;
};

export type DashboardMetricDistribution = {
    key: string;
    label: string;
    meta?: string;
    total: number;
    segments: DashboardMetricRow[];
};

export type DashboardMetricPanel = {
    key: 'inventory';
    title: string;
    description: string;
    rows: DashboardMetricRow[];
    summaryRows?: DashboardMetricRow[];
    distributions?: DashboardMetricDistribution[];
};

export type DashboardContainerGroup = {
    key: 'downloads' | 'favorites' | 'blacklisted';
    title: string;
    items: ContainerMetricItem[];
};

export type DashboardContainerTotals = {
    total: number;
    blacklisted: number;
};

export type DashboardContainerTabPayload = {
    label: string;
    params: Record<string, unknown>;
};
