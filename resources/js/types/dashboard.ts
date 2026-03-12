import type { ChartConfig } from '@/components/ui/chart';

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

export type DashboardChartDatum = {
    index: number;
    label: string;
} & Record<string, number | string>;

export type DashboardSummaryItem = {
    label: string;
    value: number;
    color: string;
};

export type DashboardChartSection = {
    key: 'overview' | 'reactions' | 'blacklist';
    title: string;
    description: string;
    tooltipLabel: string;
    config: ChartConfig;
    data: DashboardChartDatum[];
    seriesKeys: string[];
    summary: DashboardSummaryItem[];
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
