<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFileSize } from '../utils/file';
import type { DownloadTransfer } from '../types/downloadTransfer';

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
    'id' | 'status' | 'queued_at' | 'started_at' | 'finished_at' | 'percent'
>;

type SortKey = 'queuedAt' | 'startedAt' | 'completedAt' | 'progress';
type SortDirection = 'asc' | 'desc';

const DEFAULT_SORT: { key: SortKey; direction: SortDirection } = {
    key: 'queuedAt',
    direction: 'desc',
};

const downloads = ref<DownloadItem[]>([]);
const nextId = ref(1);
const isInitialLoading = ref(true);

const ITEM_HEIGHT = 64;
const OVERSCAN = 12;
const SCROLL_IDLE_MS = 180;
const SOCKET_TICK_MS = 900;

const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
const selectedStatus = ref<FilterStatus>('all');

type DownloadDetails = {
    path: string | null;
    original: string | null;
    preview: string | null;
    size: number | null;
    filename: string | null;
};

const detailsById = ref<Record<number, DownloadDetails>>({});

let activeRequestToken = 0;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let socketInterval: ReturnType<typeof setInterval> | null = null;
let detailsAbortController: AbortController | null = null;

const sortKey = ref<SortKey | null>(null);
const sortDirection = ref<SortDirection>('asc');

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

function sortMetric(item: DownloadItem, key: SortKey): number | null {
    if (key === 'progress') {
        return item.percent ?? 0;
    }

    const value = key === 'queuedAt'
        ? item.queued_at
        : key === 'startedAt'
            ? item.started_at
            : item.finished_at;

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

function findDownload(id: number) {
    return downloads.value.find((item) => item.id === id) ?? null;
}

function currentProgress(id: number) {
    return findDownload(id)?.percent ?? 0;
}

function setProgress(id: number, value: number) {
    const progress = normalizeProgress(value);
    updateDownload(id, (current) => ({
        ...current,
        percent: progress,
    }));
    if (detailsById.value[id]) {
        detailsById.value = {
            ...detailsById.value,
            [id]: { ...detailsById.value[id], progress },
        };
    }
}

function updateDownload(id: number, updater: (item: DownloadItem) => DownloadItem) {
    const index = downloads.value.findIndex((item) => item.id === id);
    if (index === -1) return;
    const next = downloads.value.slice();
    next[index] = updater(next[index]);
    downloads.value = next;
}

function buildDetails(item: DownloadItem): DownloadDetails {
    const size = 512_000 + ((item.id * 104_729) % 48_000_000);
    const color = ((item.id * 57) % 360).toString();
    const thumbnailUrl = `data:image/svg+xml;utf8,${encodeURIComponent(
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
            <defs>
                <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="hsl(${color} 70% 45%)" />
                    <stop offset="1" stop-color="hsl(${(parseInt(color, 10) + 40) % 360} 70% 35%)" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="12" fill="url(#g)" />
            <text x="50%" y="52%" font-size="18" font-family="Arial, sans-serif" fill="white" text-anchor="middle">${item.id}</text>
        </svg>`,
    )}`;

    return {
        path: `/downloads/${item.id}.bin`,
        original: `https://example.test/downloads/${item.id}`,
        preview: thumbnailUrl,
        size,
        filename: `download-${item.id}.bin`,
    };
}

function addDownload(status: Status, initialProgress?: number) {
    const now = new Date().toISOString();
    const id = nextId.value;
    nextId.value += 1;
    downloads.value = [
        ...downloads.value,
        {
            id,
            status,
            queued_at: now,
            started_at: status === 'downloading' ? now : null,
            finished_at: null,
            percent: status === 'downloading' ? (initialProgress ?? Math.floor(Math.random() * 12)) : 0,
        },
    ];

    detailsById.value = {
        ...detailsById.value,
        [id]: buildDetails({
            id,
            status,
            queued_at: now,
            started_at: status === 'downloading' ? now : null,
            finished_at: null,
            percent: status === 'downloading' ? (initialProgress ?? Math.floor(Math.random() * 12)) : 0,
        }),
    };

    queueFetchAfterIdle();
}

function pickRandomId(items: DownloadItem[]) {
    if (!items.length) return null;
    return items[Math.floor(Math.random() * items.length)]?.id ?? null;
}

function pickProcessingId(preferVisible = false) {
    const processing = downloads.value.filter((item) =>
        ['preparing', 'downloading', 'assembling'].includes(item.status),
    );
    if (!processing.length) return null;

    if (preferVisible) {
        const visibleProcessingIds = new Set(
            visibleIds.value
                .filter((item) => ['preparing', 'downloading', 'assembling'].includes(item.status))
                .map((item) => item.id),
        );
        const visibleProcessing = processing.filter((item) => visibleProcessingIds.has(item.id));
        const visiblePick = pickRandomId(visibleProcessing);
        if (visiblePick !== null) return visiblePick;
    }

    return pickRandomId(processing);
}

function pickQueuedId(preferVisible = false) {
    const queued = downloads.value.filter((item) => ['pending', 'queued'].includes(item.status));
    if (!queued.length) return null;

    if (preferVisible) {
        const visibleQueuedIds = new Set(
            visibleIds.value
                .filter((item) => ['pending', 'queued'].includes(item.status))
                .map((item) => item.id),
        );
        const visibleQueued = queued.filter((item) => visibleQueuedIds.has(item.id));
        const visiblePick = pickRandomId(visibleQueued);
        if (visiblePick !== null) return visiblePick;
    }

    return pickRandomId(queued);
}

function progressQueuedItem() {
    const id = pickQueuedId(true);
    if (id === null) return false;
    const now = new Date().toISOString();
    updateDownload(id, (current) => ({
        ...current,
        status: 'downloading',
        started_at: current.started_at ?? now,
    }));
    setProgress(id, Math.floor(Math.random() * 10));
    queueFetchAfterIdle();
    return true;
}

function progressActiveItem() {
    const id = pickProcessingId(true);
    if (id === null) return false;
    const now = new Date().toISOString();
    const next = currentProgress(id) + 5 + Math.floor(Math.random() * 18);
    const progress = normalizeProgress(next);
    setProgress(id, progress);
    if (progress >= 100) {
        updateDownload(id, (current) => ({
            ...current,
            status: 'completed',
            started_at: current.started_at ?? now,
            finished_at: now,
        }));
        setProgress(id, 100);
    } else {
        updateDownload(id, (current) => ({
            ...current,
            status: current.status === 'preparing' ? 'downloading' : current.status,
            started_at: current.started_at ?? now,
        }));
    }
    queueFetchAfterIdle();
    return true;
}

function failActiveItem() {
    const id = pickProcessingId(true);
    if (id === null) return false;
    const now = new Date().toISOString();
    updateDownload(id, (current) => ({
        ...current,
        status: 'failed',
        started_at: current.started_at ?? now,
        finished_at: null,
    }));
    setProgress(id, currentProgress(id));
    queueFetchAfterIdle();
    return true;
}

function simulateSocketEvent() {
    const roll = Math.random();
    if (roll < 0.35) {
        if (Math.random() < 0.5) {
            if (!progressQueuedItem()) {
                progressActiveItem();
            }
        } else if (!progressActiveItem()) {
            progressQueuedItem();
        }
        return;
    }

    if (roll < 0.5) {
        failActiveItem();
        return;
    }

    if (roll < 0.75) {
        addDownload('queued');
        return;
    }

    addDownload('downloading', Math.floor(Math.random() * 14));
}

function startSocketSimulation() {
    if (socketInterval) return;
    socketInterval = setInterval(simulateSocketEvent, SOCKET_TICK_MS);
}

function stopSocketSimulation() {
    if (!socketInterval) return;
    clearInterval(socketInterval);
    socketInterval = null;
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
    idleTimeout = setTimeout(() => {
        idleTimeout = null;
        fetchVisibleDetails();
    }, SCROLL_IDLE_MS);
}

async function fetchVisibleDetails() {
    const itemsToFetch = visibleIds.value.filter((item) => !detailsById.value[item.id]);

    if (!itemsToFetch.length) return;

    cancelActiveRequest();
    const requestToken = activeRequestToken;
    const controller = new AbortController();
    detailsAbortController = controller;

    try {
        const { data } = await window.axios.post<{
            items: Array<DownloadDetails & { id: number }>;
        }>('/api/download-transfers/details', {
            ids: itemsToFetch.map((item) => item.id),
        }, {
            signal: controller.signal,
        });

        if (requestToken !== activeRequestToken) return;

        detailsById.value = data.items.reduce((acc, item) => {
            acc[item.id] = {
                path: item.path,
                original: item.original,
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
        const { data } = await window.axios.get<{ items: DownloadItem[] }>('/api/download-transfers');
        downloads.value = data.items;
        detailsById.value = {};

        const maxId = data.items.reduce((max, item) => Math.max(max, item.id), 0);
        nextId.value = maxId + 1;
    } finally {
        isInitialLoading.value = false;
        queueFetchAfterIdle();
    }
}

onMounted(async () => {
    updateContainerHeight();
    window.addEventListener('resize', updateContainerHeight);
    await loadDownloads();
    startSocketSimulation();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateContainerHeight);
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    cancelActiveRequest();
    stopSocketSimulation();
});

watch(selectedStatus, () => {
    scrollTop.value = 0;
    if (containerRef.value) {
        containerRef.value.scrollTop = 0;
    }
    cancelActiveRequest();
    queueFetchAfterIdle();
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
                    <button
                        v-for="status in FILTERS"
                        :key="status"
                        type="button"
                        class="inline-flex items-center gap-2 rounded border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
                        :class="selectedStatus === status
                            ? 'border-smart-blue-500 bg-smart-blue-600 text-white'
                            : 'border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500'"
                        @click="selectedStatus = status"
                    >
                        <span>{{ filterLabel(status) }}</span>
                        <span
                            class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                            :class="selectedStatus === status
                                ? 'bg-white/15 text-white'
                                : 'bg-prussian-blue-500 text-blue-slate-200'"
                        >
                            {{ status === 'all' ? downloads.length : (statusCounts[status] ?? 0) }}
                        </span>
                    </button>
                </div>
                <div class="text-xs text-blue-slate-300">
                    Total files: {{ downloads.length }} · Filtered files: {{ baseFilteredIds.length }}
                </div>
            </div>

            <div class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 overflow-hidden">
                <div
                    class="flex min-w-[1080px] items-center justify-between border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300"
                >
                    <span>Download</span>
                    <div class="flex items-center gap-4">
                        <span class="w-24 text-right">Status</span>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('progress')"
                            aria-label="Sort by progress"
                        >
                            <span>Progress</span>
                            <ArrowUp v-if="sortState('progress') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('progress') === 'desc'" :size="12" class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <span class="w-20 text-right">Size</span>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('queuedAt')"
                            aria-label="Sort by queued time"
                        >
                            <span>Queued</span>
                            <ArrowUp v-if="sortState('queuedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('queuedAt') === 'desc'" :size="12" class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('startedAt')"
                            aria-label="Sort by started time"
                        >
                            <span>Started</span>
                            <ArrowUp v-if="sortState('startedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('startedAt') === 'desc'" :size="12" class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('completedAt')"
                            aria-label="Sort by completed time"
                        >
                            <span>Completed</span>
                            <ArrowUp v-if="sortState('completedAt') === 'asc'" :size="12" class="text-blue-slate-400" />
                            <ArrowDown v-else-if="sortState('completedAt') === 'desc'" :size="12" class="text-blue-slate-400" />
                            <ArrowUpDown v-else :size="12" class="text-blue-slate-500" />
                        </button>
                    </div>
                </div>
                <div
                    ref="containerRef"
                    class="min-h-[60vh] max-h-[70vh] overflow-auto"
                    @scroll="onScroll"
                >
                    <div v-if="isInitialLoading" class="px-4 py-12 text-center text-sm text-blue-slate-300">
                        Loading downloads...
                    </div>
                    <div v-else class="relative w-full" :style="{ height: `${totalHeight}px` }">
                        <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                            <TransitionGroup name="queue" tag="div">
                                <div
                                    v-for="item in visibleIds"
                                    :key="item.id"
                                    class="flex h-16 min-w-[1080px] items-center justify-between border-b border-twilight-indigo-500/20 px-4 text-sm text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/60"
                                >
                                    <div class="flex min-w-0 items-center gap-3">
                                        <div
                                            class="h-10 w-10 overflow-hidden rounded border border-twilight-indigo-500/40 bg-prussian-blue-600"
                                        >
                                        <img
                                            v-if="detailsById[item.id]?.preview"
                                            :src="detailsById[item.id]?.preview"
                                            alt=""
                                            class="h-full w-full object-cover"
                                        />
                                            <Skeleton v-else class="h-full w-full rounded-none bg-prussian-blue-500/60" />
                                        </div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm text-twilight-indigo-100">
                                                    ID {{ item.id }}
                                                </span>
                                                <span
                                                    v-if="detailsById[item.id]"
                                                    class="truncate text-xs text-blue-slate-300"
                                                >
                                                    {{ detailsById[item.id]?.path }}
                                                </span>
                                                <Skeleton v-else class="h-3 w-36 bg-prussian-blue-500/60" />
                                            </div>
                                        <div v-if="detailsById[item.id]" class="truncate text-xs text-smart-blue-400">
                                            {{ detailsById[item.id]?.original }}
                                        </div>
                                            <Skeleton v-else class="mt-1 h-3 w-48 bg-prussian-blue-500/60" />
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="flex w-24 items-center justify-end gap-2">
                                            <span
                                                class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium"
                                                :class="statusClass(item.status)"
                                            >
                                                {{ item.status }}
                                            </span>
                                        </div>
                                        <div class="w-28">
                                            <div v-if="item.percent !== null" class="h-1.5 w-full rounded bg-prussian-blue-600">
                                                <div
                                                    class="h-full rounded bg-smart-blue-500 transition-all"
                                                    :style="{ width: `${item.percent}%` }"
                                                ></div>
                                            </div>
                                            <Skeleton v-else class="h-2 w-full bg-prussian-blue-500/60" />
                                            <div v-if="item.percent !== null" class="mt-1 text-right text-[11px] text-blue-slate-300">
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
                                            {{ formatTimestamp(item.queued_at) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.started_at) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.finished_at) }}
                                        </div>
                                    </div>
                                </div>
                            </TransitionGroup>
                        </div>
                    </div>
                </div>
            </div>
        </div>
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
