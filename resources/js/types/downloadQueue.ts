export const DOWNLOAD_QUEUE_STATUSES = [
    'pending',
    'queued',
    'preparing',
    'downloading',
    'assembling',
    'previewing',
    'paused',
    'completed',
    'failed',
    'canceled',
] as const;

export type DownloadQueueStatus = typeof DOWNLOAD_QUEUE_STATUSES[number];

export const DOWNLOAD_QUEUE_FILTERS = ['all', ...DOWNLOAD_QUEUE_STATUSES] as const;

export type DownloadQueueFilterStatus = typeof DOWNLOAD_QUEUE_FILTERS[number];

export interface DownloadQueueItem {
    id: number;
    status: DownloadQueueStatus;
    created_at: string | null;
    queued_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    failed_at: string | null;
    percent: number;
    error: string | null;
    download_via?: string | null;
    can_resume?: boolean;
    can_restart?: boolean;
}

export interface DownloadQueueDetails {
    path: string | null;
    absolute_path: string | null;
    original: string | null;
    referrer_url: string | null;
    preview: string | null;
    size: number | null;
    filename: string | null;
}

export type DownloadQueueSortKey = 'createdAt' | 'queuedAt' | 'startedAt' | 'completedAt' | 'progress';

export type DownloadQueueSortDirection = 'asc' | 'desc';

export interface DownloadQueueSortState {
    key: DownloadQueueSortKey;
    direction: DownloadQueueSortDirection;
}

export const DEFAULT_DOWNLOAD_QUEUE_SORT: DownloadQueueSortState = {
    key: 'createdAt',
    direction: 'desc',
};

export type DownloadQueueRemoveMode = 'single' | 'selection' | 'all' | 'completed' | null;

export type DownloadQueueQueuedPayload = DownloadQueueItem & DownloadQueueDetails & {
    downloadTransferId?: number;
};

export interface DownloadQueueRemovedPayload {
    ids: number[];
}

export interface DownloadQueueProgressPayload {
    downloadTransferId: number;
    status: DownloadQueueStatus;
    percent: number;
    created_at?: string | null;
    queued_at?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
    failed_at?: string | null;
    error?: string | null;
    download_via?: string | null;
    can_resume?: boolean;
    can_restart?: boolean;
    path?: string | null;
    absolute_path?: string | null;
    original?: string | null;
    referrer_url?: string | null;
    preview?: string | null;
    size?: number | null;
    filename?: string | null;
}
