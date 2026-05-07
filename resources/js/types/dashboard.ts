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
        local: number;
        non_local: number;
        reactions: {
            love: number;
            like: number;
            funny: number;
        };
        blacklisted: number;
        blacklisted_manual: number;
        blacklisted_feed_removed: number;
        auto_blacklisted: number;
        not_found: number;
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

export type DashboardCoverageSegment = {
    key: 'unseen' | 'pending' | 'kept' | 'removed';
    label: string;
    value: number;
    barPercent: number;
    color: string;
};

export type DashboardCoverage = {
    total: number;
    moderated: number;
    moderatedPercent: string;
    segments: DashboardCoverageSegment[];
};

export type DashboardMetricRow = {
    key: string;
    label: string;
    value: number;
    meta?: string;
    barPercent?: number;
    color: string;
};

export type DashboardMetricPanel = {
    key: 'library' | 'backlog' | 'filtered' | 'feed';
    title: string;
    description: string;
    rows: DashboardMetricRow[];
};

export type DashboardPositiveOutcomes = {
    title: string;
    description: string;
    total: number;
    rows: DashboardMetricRow[];
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
