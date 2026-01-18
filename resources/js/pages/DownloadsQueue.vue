<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import { ArrowDown, ArrowUp, ArrowUpDown, Pause, Play, RotateCcw, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFileSize } from '@/utils/file';
import type { DownloadTransfer } from '@/types/downloadTransfer';
import downloadTransfers from '@/routes/api/download-transfers';

const STATUSES = [
    'pending',
    'queued',
    'preparing',
    'downloading',
    'assembling',
    'paused',
    'completed',
    'failed',
    'canceled',
] as const;
type Status = typeof STATUSES[number];
const FILTERS = ['all', ...STATUSES] as const;
type FilterStatus = typeof FILTERS[number];
type DownloadItem = Pick<
    DownloadTransfer,
    'id' | 'status' | 'created_at' | 'queued_at' | 'started_at' | 'finished_at' | 'failed_at' | 'percent'
>;

type SortKey = 'createdAt' | 'queuedAt' | 'startedAt' | 'completedAt' | 'progress';
type SortDirection = 'asc' | 'desc';

const DEFAULT_SORT: { key: SortKey; direction: SortDirection } = {
    key: 'createdAt',
    direction: 'desc',
};

const downloads = ref<DownloadItem[]>([]);
const isInitialLoading = ref(true);

const ITEM_HEIGHT = 64;
const OVERSCAN = 12;
const SCROLL_IDLE_MS = 180;
const SOCKET_CHANNEL = 'downloads';

const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
const selectedStatus = ref<FilterStatus>('all');
const actionBusy = ref<Record<number, boolean>>({});
const isScrolling = ref(false);
const selectedIds = ref<Set<number>>(new Set());
const lastSelectedIndex = ref<number | null>(null);
const selectAllRef = ref<HTMLInputElement | null>(null);
const removeDialogOpen = ref(false);
const removeTargetIds = ref<number[]>([]);
const removeIsDeleting = ref(false);
const removeMode = ref<'single' | 'selection' | 'all' | null>(null);
const removeCount = computed(() => removeTargetIds.value.length);
const removeTitle = computed(() => (removeMode.value === 'single' ? 'Remove download' : 'Remove downloads'));
const removeLabel = computed(() => {
    if (removeMode.value === 'single') return 'this download';
    if (removeCount.value === 1) return '1 download';
    return `${removeCount.value} downloads`;
});
const removeDescription = computed(
    () => `Are you sure you want to remove ${removeLabel.value}? This action cannot be undone.`,
);

type DownloadDetails = {
    path: string | null;
    absolute_path: string | null;
    original: string | null;
    referrer_url: string | null;
    preview: string | null;
    size: number | null;
    filename: string | null;
};

const detailsById = ref<Record<number, DownloadDetails>>({});

type DownloadQueuedPayload = DownloadItem & DownloadDetails & {
    downloadTransferId?: number;
};
type DownloadProgressPayload = {
    downloadTransferId: number;
    status: string;
    percent: number;
    created_at?: string | null;
    started_at?: string | null;
    finished_at?: string | null;
    failed_at?: string | null;
    path?: string | null;
    absolute_path?: string | null;
    original?: string | null;
    referrer_url?: string | null;
    preview?: string | null;
    size?: number | null;
    filename?: string | null;
};

let activeRequestToken = 0;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let detailsAbortController: AbortController | null = null;
let echoChannel: { listen: (event: string, callback: (payload: unknown) => void) => void } | null = null;

const sortKey = ref<SortKey | null>(null);
const sortDirection = ref<SortDirection>('asc');
const lastNonCompletedSort = ref<{ key: SortKey | null; direction: SortDirection } | null>(null);

const baseFilteredIds = computed(() =>
    selectedStatus.value === 'all'
        ? downloads.value
        : downloads.value.filter((item) => item.status === selectedStatus.value),
);

const statusCounts = computed(() =>
    downloads.value.reduce((acc, item) => {
        acc[item.status] = (acc[item.status] ?? 0) + 1;
        return acc;
    }, {} as Record<string, number>),
);

const filteredIds = computed(() => baseFilteredIds.value.map((item) => item.id));
const selectedCount = computed(() => selectedIds.value.size);
const selectedInFilterCount = computed(() =>
    filteredIds.value.filter((id) => selectedIds.value.has(id)).length,
);
const selectedIdsList = computed(() => Array.from(selectedIds.value));
const allFilteredSelected = computed(() =>
    filteredIds.value.length > 0 && selectedInFilterCount.value === filteredIds.value.length,
);
const someFilteredSelected = computed(() =>
    selectedInFilterCount.value > 0 && !allFilteredSelected.value,
);

function sortMetric(item: DownloadItem, key: SortKey): number | null {
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

function compareItems(a: DownloadItem, b: DownloadItem, key: SortKey, direction: SortDirection) {
    const aValue = sortMetric(a, key);
    const bValue = sortMetric(b, key);

    if (aValue === null && bValue === null) return a.id - b.id;
    if (aValue === null) return 1;
    if (bValue === null) return -1;

    if (aValue === bValue) return a.id - b.id;
    return direction === 'asc' ? aValue - bValue : bValue - aValue;
}

const sortedIds = computed(() => {
    const key = sortKey.value ?? DEFAULT_SORT.key;
    const direction = sortKey.value ? sortDirection.value : DEFAULT_SORT.direction;
    return baseFilteredIds.value.slice().sort((a, b) => compareItems(a, b, key, direction));
});

const totalHeight = computed(() => sortedIds.value.length * ITEM_HEIGHT);
const startIndex = computed(() =>
    Math.max(0, Math.floor(scrollTop.value / ITEM_HEIGHT) - OVERSCAN),
);
const endIndex = computed(() =>
    Math.min(
        sortedIds.value.length,
        Math.ceil((scrollTop.value + containerHeight.value) / ITEM_HEIGHT) + OVERSCAN,
    ),
);
const visibleIds = computed(() => sortedIds.value.slice(startIndex.value, endIndex.value));
const offsetY = computed(() => startIndex.value * ITEM_HEIGHT);

const STATUS_STYLES: Record<Status, string> = {
    pending: 'bg-warning-600 border border-warning-500 text-warning-100',
    queued: 'bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100',
    preparing: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    downloading: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    assembling: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    paused: 'bg-warning-600 border border-warning-500 text-warning-100',
    completed: 'bg-success-600 border border-success-500 text-white',
    failed: 'bg-danger-600 border border-danger-500 text-white',
    canceled: 'bg-prussian-blue-600 border border-blue-slate-500 text-blue-slate-200',
};

function statusClass(status: string) {
    return STATUS_STYLES[status as Status] ?? 'bg-prussian-blue-600 border border-blue-slate-500 text-blue-slate-200';
}

function filterLabel(status: FilterStatus) {
    if (status === 'all') return 'All';

    const labels: Record<Status, string> = {
        pending: 'Pending',
        queued: 'Queued',
        preparing: 'Preparing',
        downloading: 'Downloading',
        assembling: 'Assembling',
        paused: 'Paused',
        completed: 'Completed',
        failed: 'Failed',
        canceled: 'Canceled',
    };

    return labels[status];
}

function pad2(value: number) {
    return value.toString().padStart(2, '0');
}

function formatTimestamp(value: string | null) {
    if (!value) return '--';
    const date = new Date(value);
    const now = new Date();

    const isToday =
        date.getFullYear() === now.getFullYear()
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

function sortState(key: SortKey) {
    if (sortKey.value === null) {
        return key === DEFAULT_SORT.key ? DEFAULT_SORT.direction : null;
    }
    return sortKey.value === key ? sortDirection.value : null;
}

function toggleSort(key: SortKey) {
    if (sortKey.value !== key) {
        sortKey.value = key;
        sortDirection.value = 'asc';
        return;
    }

    if (sortDirection.value === 'asc') {
        sortDirection.value = 'desc';
        return;
    }

    sortKey.value = null;
    sortDirection.value = DEFAULT_SORT.direction;
}

function normalizeProgress(value: number) {
    return Math.max(0, Math.min(100, Math.round(value)));
}

function setActionBusy(id: number, value: boolean) {
    actionBusy.value = { ...actionBusy.value, [id]: value };
}

function isActionBusy(id: number) {
    return actionBusy.value[id] ?? false;
}

function canPause(item: DownloadItem) {
    return [
        'pending',
        'queued',
        'preparing',
        'downloading',
        'assembling',
    ].includes(item.status);
}

function canResume(item: DownloadItem) {
    return item.status === 'paused';
}

function canCancel(item: DownloadItem) {
    return !['completed', 'failed', 'canceled'].includes(item.status);
}

function canRestart(item: DownloadItem) {
    return ['failed', 'canceled', 'completed'].includes(item.status);
}

async function copyPath(path: string | null, absolutePath: string | null) {
    const value = absolutePath || path;
    if (!value) return;
    const isWindows = navigator.userAgent.toLowerCase().includes('windows');
    const normalized = isWindows ? value.replace(/\//g, '\\') : value.replace(/\\/g, '/');
    try {
        await navigator.clipboard.writeText(normalized);
    } catch {
        // Ignore clipboard errors.
    }
}

function setSelection(next: Set<number>) {
    selectedIds.value = next;
}

function isSelected(id: number) {
    return selectedIds.value.has(id);
}

function selectRange(rangeIds: number[], additive: boolean) {
    const next = additive ? new Set(selectedIds.value) : new Set<number>();
    rangeIds.forEach((id) => next.add(id));
    setSelection(next);
}

function selectSingle(id: number, additive: boolean) {
    const next = additive ? new Set(selectedIds.value) : new Set<number>();
    if (additive && next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    setSelection(next);
}

function handleSelection(id: number, event?: MouseEvent) {
    const allIds = sortedIds.value.map((item) => item.id);
    const index = allIds.indexOf(id);
    if (index === -1) return;

    const isCtrl = !!event && (event.ctrlKey || event.metaKey);
    const isShift = !!event && event.shiftKey;

    if (isShift && lastSelectedIndex.value !== null) {
        const start = Math.min(lastSelectedIndex.value, index);
        const end = Math.max(lastSelectedIndex.value, index);
        selectRange(allIds.slice(start, end + 1), isCtrl);
    } else {
        selectSingle(id, isCtrl);
    }

    lastSelectedIndex.value = index;
}

function handleCheckboxClick(id: number, event: MouseEvent) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }
    setSelection(next);
    event.stopPropagation();
    lastSelectedIndex.value = sortedIds.value.findIndex((item) => item.id === id);
}

function handleRowClick(item: DownloadItem, event: MouseEvent) {
    const target = event.target as HTMLElement | null;
    if (target?.closest('button, a, input')) return;
    handleSelection(item.id, event);
}

function toggleSelectAll(checked: boolean) {
    const next = new Set(selectedIds.value);
    if (checked) {
        filteredIds.value.forEach((id) => next.add(id));
    } else {
        filteredIds.value.forEach((id) => next.delete(id));
    }
    setSelection(next);
}

function clearSelection() {
    const filtered = new Set(filteredIds.value);
    const next = new Set([...selectedIds.value].filter((id) => !filtered.has(id)));
    setSelection(next);
}

function clearAllSelection() {
    setSelection(new Set());
}

function openRemoveDialog(mode: 'single' | 'selection' | 'all', ids: number[]) {
    if (ids.length === 0) return;
    removeMode.value = mode;
    removeTargetIds.value = ids;
    removeDialogOpen.value = true;
}

async function confirmRemove(): Promise<void> {
    if (removeIsDeleting.value) return;
    removeIsDeleting.value = true;
    const ids = removeTargetIds.value;

    try {
        await Promise.all(ids.map((id) => window.axios.delete(downloadTransfers.destroy.url(id))));
        downloads.value = downloads.value.filter((item) => !ids.includes(item.id));
        detailsById.value = Object.fromEntries(
            Object.entries(detailsById.value).filter(([key]) => !ids.includes(Number(key))),
        );
        const nextSelection = new Set(selectedIds.value);
        ids.forEach((id) => nextSelection.delete(id));
        setSelection(nextSelection);
    } finally {
        removeIsDeleting.value = false;
        removeDialogOpen.value = false;
        removeTargetIds.value = [];
        removeMode.value = null;
    }
}

async function pauseDownload(item: DownloadItem) {
    if (!canPause(item) || isActionBusy(item.id)) return;
    setActionBusy(item.id, true);
    try {
        await window.axios.post(downloadTransfers.pause.url(item.id));
    } finally {
        setActionBusy(item.id, false);
    }
}

async function resumeDownload(item: DownloadItem) {
    if (!canResume(item) || isActionBusy(item.id)) return;
    setActionBusy(item.id, true);
    try {
        await window.axios.post(downloadTransfers.resume.url(item.id));
    } finally {
        setActionBusy(item.id, false);
    }
}

async function cancelDownload(item: DownloadItem) {
    if (!canCancel(item) || isActionBusy(item.id)) return;
    setActionBusy(item.id, true);
    try {
        await window.axios.post(downloadTransfers.cancel.url(item.id));
    } finally {
        setActionBusy(item.id, false);
    }
}

async function restartDownload(item: DownloadItem) {
    if (!canRestart(item) || isActionBusy(item.id)) return;
    setActionBusy(item.id, true);
    try {
        await window.axios.post(downloadTransfers.restart.url(item.id));
    } finally {
        setActionBusy(item.id, false);
    }
}

function deleteDownload(item: DownloadItem) {
    openRemoveDialog('single', [item.id]);
}

function updateDownload(id: number, updater: (item: DownloadItem) => DownloadItem) {
    const index = downloads.value.findIndex((item) => item.id === id);
    if (index === -1) return;
    const next = downloads.value.slice();
    next[index] = updater(next[index]);
    downloads.value = next;
}

function upsertDownload(item: DownloadItem) {
    const index = downloads.value.findIndex((row) => row.id === item.id);
    if (index === -1) {
        downloads.value = [item, ...downloads.value];
        return;
    }
    const next = downloads.value.slice();
    next[index] = { ...next[index], ...item };
    downloads.value = next;
}

function applyQueuedPayload(payload: DownloadQueuedPayload) {
    const id = payload.id ?? payload.downloadTransferId;
    if (!id) return;
    const existing = downloads.value.find((item) => item.id === id);

    const item: DownloadItem = {
        id,
        status: payload.status,
        created_at: payload.created_at ?? existing?.created_at ?? null,
        queued_at: payload.queued_at ?? null,
        started_at: payload.started_at ?? null,
        finished_at: payload.finished_at ?? null,
        failed_at: payload.failed_at ?? null,
        percent: payload.percent ?? 0,
    };

    upsertDownload(item);

    detailsById.value = {
        ...detailsById.value,
        [id]: {
            path: payload.path ?? null,
            absolute_path: payload.absolute_path ?? null,
            original: payload.original ?? null,
            referrer_url: payload.referrer_url ?? null,
            preview: payload.preview ?? null,
            size: payload.size ?? null,
            filename: payload.filename ?? null,
        },
    };
}

function applyProgressPayload(payload: DownloadProgressPayload) {
    const id = payload.downloadTransferId;
    updateDownload(id, (current) => ({
        ...current,
        status: payload.status,
        percent: normalizeProgress(payload.percent),
        created_at: payload.created_at ?? current.created_at ?? null,
        started_at: payload.started_at ?? null,
        finished_at: payload.finished_at ?? null,
        failed_at: payload.failed_at ?? null,
    }));
    if (
        payload.path !== undefined
        || payload.absolute_path !== undefined
        || payload.original !== undefined
        || payload.referrer_url !== undefined
        || payload.preview !== undefined
        || payload.size !== undefined
        || payload.filename !== undefined
    ) {
        detailsById.value = {
            ...detailsById.value,
            [id]: {
                path: payload.path ?? detailsById.value[id]?.path ?? null,
                absolute_path: payload.absolute_path ?? detailsById.value[id]?.absolute_path ?? null,
                original: payload.original ?? detailsById.value[id]?.original ?? null,
                referrer_url: payload.referrer_url ?? detailsById.value[id]?.referrer_url ?? null,
                preview: payload.preview ?? detailsById.value[id]?.preview ?? null,
                size: payload.size ?? detailsById.value[id]?.size ?? null,
                filename: payload.filename ?? detailsById.value[id]?.filename ?? null,
            },
        };
    }
}

function startEchoListeners() {
    const echo = window.Echo as undefined | { private: (channel: string) => { listen: (event: string, cb: (payload: unknown) => void) => void } };
    if (!echo) return;
    echoChannel = echo.private(SOCKET_CHANNEL);
    echoChannel.listen('.DownloadTransferCreated', (payload: unknown) => {
        applyQueuedPayload(payload as DownloadQueuedPayload);
    });
    echoChannel.listen('.DownloadTransferQueued', (payload: unknown) => {
        applyQueuedPayload(payload as DownloadQueuedPayload);
    });
    echoChannel.listen('.DownloadTransferProgressUpdated', (payload: unknown) => {
        applyProgressPayload(payload as DownloadProgressPayload);
    });
}

function stopEchoListeners() {
    const echo = window.Echo as undefined | { leave: (channel: string) => void };
    if (!echo) return;
    echo.leave(SOCKET_CHANNEL);
    echoChannel = null;
}

function cancelActiveRequest() {
    if (detailsAbortController) {
        detailsAbortController.abort();
        detailsAbortController = null;
    }
    activeRequestToken += 1;
}

function queueFetchAfterIdle() {
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    isScrolling.value = true;
    idleTimeout = setTimeout(() => {
        idleTimeout = null;
        isScrolling.value = false;
        fetchVisibleDetails();
    }, SCROLL_IDLE_MS);
}

async function fetchVisibleDetails() {
    const itemsToFetch = visibleIds.value;

    if (!itemsToFetch.length) return;

    cancelActiveRequest();
    const requestToken = activeRequestToken;
    const controller = new AbortController();
    detailsAbortController = controller;

    try {
        const { data } = await window.axios.post<{
            items: Array<DownloadDetails & { id: number }>;
        }>(downloadTransfers.details.url(), {
            ids: itemsToFetch.map((item) => item.id),
        }, {
            signal: controller.signal,
        });

        if (requestToken !== activeRequestToken) return;

        detailsById.value = data.items.reduce((acc, item) => {
            acc[item.id] = {
                path: item.path,
                absolute_path: item.absolute_path,
                original: item.original,
                referrer_url: item.referrer_url,
                preview: item.preview,
                size: item.size,
                filename: item.filename,
            };
            return acc;
        }, { ...detailsById.value } as Record<number, DownloadDetails>);
    } catch (error) {
        if (controller.signal.aborted) return;
    } finally {
        if (detailsAbortController === controller) {
            detailsAbortController = null;
        }
    }
}

function onScroll(event: Event) {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    scrollTop.value = target.scrollTop;
    cancelActiveRequest();
    queueFetchAfterIdle();
}

function updateContainerHeight() {
    if (!containerRef.value) return;
    containerHeight.value = containerRef.value.clientHeight;
    queueFetchAfterIdle();
}

async function loadDownloads() {
    isInitialLoading.value = true;
    try {
        const { data } = await window.axios.get<{ items: DownloadItem[] }>(downloadTransfers.index.url());
        downloads.value = data.items;
        detailsById.value = {};
    } finally {
        isInitialLoading.value = false;
        queueFetchAfterIdle();
    }
}

onMounted(async () => {
    updateContainerHeight();
    window.addEventListener('resize', updateContainerHeight);
    await loadDownloads();
    startEchoListeners();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateContainerHeight);
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    cancelActiveRequest();
    stopEchoListeners();
});

watch(selectedStatus, (next, prev) => {
    scrollTop.value = 0;
    if (containerRef.value) {
        containerRef.value.scrollTop = 0;
    }
    if (next === 'completed') {
        lastNonCompletedSort.value = { key: sortKey.value, direction: sortDirection.value };
        sortKey.value = 'completedAt';
        sortDirection.value = 'desc';
    } else if (prev === 'completed' && lastNonCompletedSort.value) {
        sortKey.value = lastNonCompletedSort.value.key;
        sortDirection.value = lastNonCompletedSort.value.direction;
        lastNonCompletedSort.value = null;
    }
    cancelActiveRequest();
    queueFetchAfterIdle();
});

watch([sortKey, sortDirection], () => {
    cancelActiveRequest();
    queueFetchAfterIdle();
});

watch([someFilteredSelected, allFilteredSelected], ([hasSome, hasAll]) => {
    if (!selectAllRef.value) return;
    selectAllRef.value.indeterminate = hasSome && !hasAll;
});

watch(downloads, () => {
    const validIds = new Set(downloads.value.map((item) => item.id));
    const next = new Set([...selectedIds.value].filter((id) => validIds.has(id)));
    if (next.size !== selectedIds.value.size) {
        setSelection(next);
    }
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                        Downloads Queue
                    </h4>
                    <p class="text-blue-slate-300">
                        Manage queued downloads.
                    </p>
                </div>
            </div>

            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <button v-for="status in FILTERS" :key="status" type="button"
                        class="inline-flex items-center gap-2 rounded border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
                        :class="selectedStatus === status
                            ? 'border-smart-blue-500 bg-smart-blue-600 text-white'
                            : 'border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500'"
                        @click="selectedStatus = status">
                        <span>{{ filterLabel(status) }}</span>
                        <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold" :class="selectedStatus === status
                            ? 'bg-white/15 text-white'
                            : 'bg-prussian-blue-500 text-blue-slate-200'">
                            {{ status === 'all' ? downloads.length : (statusCounts[status] ?? 0) }}
                        </span>
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs text-blue-slate-300">
                    <span>Total files: {{ downloads.length }} | Filtered files: {{ baseFilteredIds.length }}</span>
                    <span v-if="selectedCount">
                        Selected: {{ selectedCount }} | In filter: {{ selectedInFilterCount }}
                    </span>
                    <div v-if="selectedCount || filteredIds.length" class="flex items-center gap-2">
                        <Button
                            v-if="selectedCount"
                            variant="outline"
                            size="sm"
                            :disabled="removeIsDeleting"
                            @click="openRemoveDialog('selection', selectedIdsList)">
                            Remove selection
                        </Button>
                        <Button
                            v-if="filteredIds.length"
                            variant="outline"
                            size="sm"
                            :disabled="removeIsDeleting"
                            @click="openRemoveDialog('all', filteredIds)">
                            Remove all
                        </Button>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 overflow-hidden">
                <div
                    class="flex min-w-[1320px] items-center justify-between border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300">
                    <div class="flex items-center gap-3">
                        <input
                            ref="selectAllRef"
                            type="checkbox"
                            class="h-4 w-4 rounded border border-twilight-indigo-500 bg-prussian-blue-700 text-smart-blue-400"
                            :checked="allFilteredSelected"
                            aria-label="Select all downloads"
                            @change="toggleSelectAll(($event.target as HTMLInputElement).checked)"
                        />
                        <span>Download</span>
                    </div>
                    <div class="flex items-center gap-4">
                        <span class="w-24 text-right">Status</span>
                        <button type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('progress')" aria-label="Sort by progress">
                            <span>Progress</span>
                            <ArrowUp v-if="sortState('progress') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('progress') === 'desc'" :size="12"
                                class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <span class="w-20 text-right">Size</span>
                        <button type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('createdAt')" aria-label="Sort by added time">
                            <span>Added</span>
                            <ArrowUp v-if="sortState('createdAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('createdAt') === 'desc'" :size="12"
                                class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <button type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('queuedAt')" aria-label="Sort by queued time">
                            <span>Queued</span>
                            <ArrowUp v-if="sortState('queuedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('queuedAt') === 'desc'" :size="12"
                                class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <button type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('startedAt')" aria-label="Sort by started time">
                            <span>Started</span>
                            <ArrowUp v-if="sortState('startedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('startedAt') === 'desc'" :size="12"
                                class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <button type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('completedAt')" aria-label="Sort by completed time">
                            <span>Completed</span>
                            <ArrowUp v-if="sortState('completedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('completedAt') === 'desc'" :size="12"
                                class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <span class="w-80 text-right">Actions</span>
                    </div>
                </div>
                <div ref="containerRef" class="min-h-[60vh] max-h-[70vh] overflow-auto" @scroll="onScroll">
                    <div v-if="isInitialLoading" class="px-4 py-12 text-center text-sm text-blue-slate-300">
                        Loading downloads...
                    </div>
                    <div v-else class="relative w-full" :style="{ height: `${totalHeight}px` }">
                        <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                            <TransitionGroup :name="isScrolling ? '' : 'queue'" tag="div">
                                <div v-for="item in visibleIds" :key="item.id"
                                    class="flex h-16 min-w-[1320px] items-center justify-between border-b border-twilight-indigo-500/20 px-4 text-sm text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/60 cursor-pointer"
                                    @click="handleRowClick(item, $event)">
                                    <div class="flex min-w-0 items-center gap-3">
                                        <input
                                            type="checkbox"
                                            class="h-4 w-4 rounded border border-twilight-indigo-500 bg-prussian-blue-700 text-smart-blue-400"
                                            :checked="isSelected(item.id)"
                                            aria-label="Select download"
                                            @click.stop="handleCheckboxClick(item.id, $event)"
                                        />
                                        <div
                                            class="h-10 w-10 overflow-hidden rounded border border-twilight-indigo-500/40 bg-prussian-blue-600">
                                            <img v-if="detailsById[item.id]?.preview"
                                                :src="detailsById[item.id]?.preview" alt=""
                                                class="h-full w-full object-cover" />
                                            <Skeleton v-else
                                                class="h-full w-full rounded-none bg-prussian-blue-500/60" />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm text-twilight-indigo-100">
                                                    ID {{ item.id }}
                                                </span>
                                                <button v-if="detailsById[item.id]" type="button"
                                                    class="truncate text-xs text-blue-slate-300 hover:text-white"
                                                    title="Copy full path"
                                                    @click.stop="copyPath(detailsById[item.id]?.path ?? null, detailsById[item.id]?.absolute_path ?? null)">
                                                    {{ detailsById[item.id]?.path }}
                                                </button>
                                                <Skeleton v-else class="h-3 w-36 bg-prussian-blue-500/60" />
                                            </div>
                                            <div v-if="detailsById[item.id]"
                                                class="truncate text-xs text-smart-blue-400 hover:text-white">
                                                <a :href="detailsById[item.id]?.referrer_url" target="_blank">{{
                                                    detailsById[item.id]?.referrer_url }}</a>
                                            </div>
                                            <Skeleton v-else class="mt-1 h-3 w-48 bg-prussian-blue-500/60" />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="flex w-24 items-center justify-end gap-2">
                                            <span
                                                class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium"
                                                :class="statusClass(item.status)">
                                                {{ item.status }}
                                            </span>
                                        </div>
                                        <div class="w-28">
                                            <div v-if="item.percent !== null"
                                                class="h-1.5 w-full rounded bg-prussian-blue-600">
                                                <div class="h-full rounded bg-smart-blue-500 transition-all"
                                                    :style="{ width: `${item.percent}%` }"></div>
                                            </div>
                                            <Skeleton v-else class="h-2 w-full bg-prussian-blue-500/60" />
                                            <div v-if="item.percent !== null"
                                                class="mt-1 text-right text-[11px] text-blue-slate-300">
                                                {{ `${item.percent}%` }}
                                            </div>
                                            <div v-else class="mt-1 flex justify-end">
                                                <Skeleton class="h-3 w-10 bg-prussian-blue-500/60" />
                                            </div>
                                        </div>
                                        <div class="w-20 text-right text-xs text-blue-slate-300">
                                            <span v-if="detailsById[item.id]">
                                                {{ formatFileSize(detailsById[item.id].size) }}
                                            </span>
                                            <Skeleton v-else class="ml-auto h-3 w-12 bg-prussian-blue-500/60" />
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.created_at) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.queued_at) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.started_at) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.finished_at ?? item.failed_at) }}
                                        </div>
                                        <div class="flex w-80 items-center justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="border-warning-500/50 text-warning-200 hover:bg-warning-600/15 hover:text-warning-100"
                                                :disabled="isActionBusy(item.id) || !canPause(item)"
                                                aria-label="Pause download"
                                                @click="pauseDownload(item)"
                                            >
                                                <Pause :size="14" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="border-success-500/50 text-success-200 hover:bg-success-600/15 hover:text-success-100"
                                                :disabled="isActionBusy(item.id) || !canResume(item)"
                                                aria-label="Resume download"
                                                @click="resumeDownload(item)"
                                            >
                                                <Play :size="14" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="border-danger-500/50 text-danger-200 hover:bg-danger-600/15 hover:text-danger-100"
                                                :disabled="isActionBusy(item.id) || !canCancel(item)"
                                                aria-label="Cancel download"
                                                @click="cancelDownload(item)"
                                            >
                                                <X :size="14" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="border-sapphire-500/50 text-sapphire-200 hover:bg-sapphire-600/15 hover:text-sapphire-100"
                                                :disabled="isActionBusy(item.id) || !canRestart(item)"
                                                aria-label="Restart download"
                                                @click="restartDownload(item)"
                                            >
                                                <RotateCcw :size="14" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon-sm"
                                                class="border-danger-500/50 text-danger-200 hover:bg-danger-600/15 hover:text-danger-100"
                                                :disabled="isActionBusy(item.id)"
                                                aria-label="Delete download"
                                                @click="deleteDownload(item)"
                                            >
                                                <Trash2 :size="14" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </TransitionGroup>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <Dialog v-model="removeDialogOpen">
            <DialogContent class="sm:max-w-[425px] bg-prussian-blue-600 border-danger-500/30">
                <DialogHeader>
                    <DialogTitle class="text-danger-400">{{ removeTitle }}</DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        {{ removeDescription }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" :disabled="removeIsDeleting">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :loading="removeIsDeleting"
                        :disabled="removeIsDeleting"
                        @click="confirmRemove">
                        {{ removeIsDeleting ? 'Removing...' : 'Remove' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageLayout>
</template>

<style scoped>
.queue-move,
.queue-enter-active,
.queue-leave-active {
    transition: all 0.4s ease;
}

.queue-enter-from,
.queue-leave-to {
    opacity: 0;
    transform: translateY(14px);
}

.queue-leave-active {
    position: absolute;
    width: 100%;
}
</style>
