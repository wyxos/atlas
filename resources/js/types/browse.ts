export interface BrowseItem {
    id: number; // Use actual CivitAI numeric ID
    src: string;
    width: number;
    height: number;
    page: string | number;
    index: number;
    loved?: boolean;
    liked?: boolean;
    disliked?: boolean;
    funny?: boolean;
    imageHeight?: number;
}

export interface BrowseFilters {
    sort: string;
    period: string;
    nsfw: boolean;
    autoNext: boolean;
    page: number | string | null;
    nextPage: number | string | null;
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
