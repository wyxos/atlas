import { copyToClipboard } from '@/utils/clipboard';
import type {
    DownloadQueueFilterStatus,
    DownloadQueueItem,
    DownloadQueueSortDirection,
    DownloadQueueSortKey,
    DownloadQueueStatus,
} from '@/types/downloadQueue';

const STATUS_STYLES: Record<DownloadQueueStatus, string> = {
    pending: 'bg-warning-600 border border-warning-500 text-warning-100',
    queued: 'bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100',
    preparing: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    downloading: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    assembling: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    previewing: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    paused: 'bg-warning-600 border border-warning-500 text-warning-100',
    completed: 'bg-success-600 border border-success-500 text-white',
    failed: 'bg-danger-600 border border-danger-500 text-white',
    canceled: 'bg-prussian-blue-600 border border-blue-slate-500 text-blue-slate-200',
};

const FILTER_LABELS: Record<DownloadQueueStatus, string> = {
    pending: 'Pending',
    queued: 'Queued',
    preparing: 'Preparing',
    downloading: 'Downloading',
    assembling: 'Assembling',
    previewing: 'Previewing',
    paused: 'Paused',
    completed: 'Completed',
    failed: 'Failed',
    canceled: 'Canceled',
};

export function getDownloadQueueStatusClass(status: string): string {
    return STATUS_STYLES[status as DownloadQueueStatus] ?? 'bg-prussian-blue-600 border border-blue-slate-500 text-blue-slate-200';
}

export function getDownloadQueueFilterLabel(status: DownloadQueueFilterStatus): string {
    return status === 'all' ? 'All' : FILTER_LABELS[status];
}

function getSortMetric(item: DownloadQueueItem, key: DownloadQueueSortKey): number | null {
    if (key === 'progress') {
        return item.percent ?? 0;
    }

    const value = key === 'createdAt'
        ? item.created_at
        : key === 'queuedAt'
            ? item.queued_at
            : key === 'startedAt'
                ? item.started_at
                : item.finished_at ?? item.failed_at;

    return value ? Date.parse(value) : null;
}

export function compareDownloadQueueItems(
    a: DownloadQueueItem,
    b: DownloadQueueItem,
    key: DownloadQueueSortKey,
    direction: DownloadQueueSortDirection,
): number {
    const aValue = getSortMetric(a, key);
    const bValue = getSortMetric(b, key);

    if (aValue === null && bValue === null) return a.id - b.id;
    if (aValue === null) return 1;
    if (bValue === null) return -1;
    if (aValue === bValue) return a.id - b.id;

    return direction === 'asc' ? aValue - bValue : bValue - aValue;
}

export function normalizeDownloadQueueProgress(value: number): number {
    return Math.max(0, Math.min(100, Math.round(value)));
}

export function canPauseDownloadQueueItem(item: DownloadQueueItem): boolean {
    if ((item.percent ?? 0) >= 100) {
        return false;
    }

    return ['pending', 'queued', 'preparing', 'downloading', 'assembling'].includes(item.status);
}

export function canResumeDownloadQueueItem(item: DownloadQueueItem): boolean {
    return item.status === 'paused';
}

export function canCancelDownloadQueueItem(item: DownloadQueueItem): boolean {
    if ((item.percent ?? 0) >= 100) {
        return false;
    }

    return !['completed', 'failed', 'canceled', 'previewing'].includes(item.status);
}

export function canRestartDownloadQueueItem(item: DownloadQueueItem): boolean {
    return ['failed', 'canceled', 'completed'].includes(item.status);
}

function pad2(value: number): string {
    return value.toString().padStart(2, '0');
}

export function formatDownloadQueueTimestamp(value: string | null): string {
    if (!value) return '--';

    const date = new Date(value);
    const now = new Date();
    const isToday = date.getFullYear() === now.getFullYear()
        && date.getMonth() === now.getMonth()
        && date.getDate() === now.getDate();
    const time = `${pad2(date.getHours())}:${pad2(date.getMinutes())}:${pad2(date.getSeconds())}`;

    if (isToday) {
        return time;
    }

    const day = pad2(date.getDate());
    const month = pad2(date.getMonth() + 1);

    if (date.getFullYear() === now.getFullYear()) {
        return `${day}.${month} ${time}`;
    }

    return `${day}:${month}:${date.getFullYear()} ${time}`;
}

export async function copyDownloadQueuePath(path: string | null, absolutePath: string | null): Promise<void> {
    const value = absolutePath || path;

    if (!value) {
        return;
    }

    const normalized = navigator.userAgent.toLowerCase().includes('windows')
        ? value.replace(/\//g, '\\')
        : value.replace(/\\/g, '/');

    try {
        await copyToClipboard(normalized, 'Download path');
    } catch {
        // Ignore clipboard errors.
    }
}
