export interface BrowseItem {
    id: number; // Use actual CivitAI numeric ID
    src: string;
    original: string; // Full resolution image URL
    width: number;
    height: number;
    page: string | number;
    index: number;
    loved?: boolean;
    liked?: boolean;
    disliked?: boolean;
    funny?: boolean;
    imageHeight?: number;
    seen_preview_at?: string | null;
    seen_file_at?: string | null;
}

export interface BrowseFilters {
    sort: string;
    period: string;
    limit: number;
    nsfw: boolean;
    autoNext: boolean;
    page: number | string;
    nextPage: number | string | null;
    container: string;
}

export interface BrowseProps {
    items: BrowseItem[];
    filters: BrowseFilters;
}

export interface PaginationState {
    page: number | string | null;
    nextPage: number | string | null;
}

export interface SortOption {
    value: string;
    label: string;
}

export interface PeriodOption {
    value: string;
    label: string;
}

export interface LimitOption {
    value: number;
    label: string;
}

export interface ContainerOption {
    value: string;
    label: string;
}
