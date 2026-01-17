<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFileSize } from '../utils/file';

const STATUSES = ['queued', 'processing', 'done', 'failed'] as const;
type Status = typeof STATUSES[number];
const FILTERS = ['all', ...STATUSES] as const;
type FilterStatus = typeof FILTERS[number];
type DownloadItem = {
    id: number;
    status: Status;
    queuedAt: string;
    startedAt: string | null;
    completedAt: string | null;
};

type SortKey = 'queuedAt' | 'startedAt' | 'completedAt' | 'progress';
type SortDirection = 'asc' | 'desc';

const DEFAULT_SORT: { key: SortKey; direction: SortDirection } = {
    key: 'queuedAt',
    direction: 'desc',
};

const INITIAL_LOAD_DELAY_MS = 900;

function createInitialDownloads(count: number): DownloadItem[] {
    const now = Date.now();
    return Array.from({ length: count }, (_, index) => {
        const id = index + 1;
        const status = STATUSES[index % STATUSES.length];
        const queuedAt = new Date(now - (count - index) * 60_000).toISOString();
        let startedAt: string | null = null;
        let completedAt: string | null = null;

        if (status !== 'queued') {
            startedAt = new Date(Date.parse(queuedAt) + 4 * 60_000).toISOString();
        }
        if (status === 'done') {
            completedAt = new Date(Date.parse(startedAt ?? queuedAt) + 8 * 60_000).toISOString();
        }

        return {
            id,
            status,
            queuedAt,
            startedAt,
            completedAt,
        };
    });
}

const downloads = ref<DownloadItem[]>([]);
const nextId = ref(1);
const isInitialLoading = ref(true);

const ITEM_HEIGHT = 64;
const OVERSCAN = 12;
const SCROLL_IDLE_MS = 180;
const FETCH_DELAY_MS = 650;
const SOCKET_TICK_MS = 900;

const containerRef = ref<HTMLElement | null>(null);
const scrollTop = ref(0);
const containerHeight = ref(0);
const selectedStatus = ref<FilterStatus>('all');

type DownloadDetails = {
    path: string;
    url: string;
    thumbnailUrl: string;
    progress: number;
    size: number;
};

const detailsById = ref<Record<number, DownloadDetails>>({});
const progressOverrides = ref<Record<number, number>>({});

let activeRequestToken = 0;
let activeFetchTimeout: ReturnType<typeof setTimeout> | null = null;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let socketInterval: ReturnType<typeof setInterval> | null = null;
let initialLoadTimeout: ReturnType<typeof setTimeout> | null = null;

const sortKey = ref<SortKey | null>(null);
const sortDirection = ref<SortDirection>('asc');

const baseFilteredIds = computed(() =>
    selectedStatus.value === 'all'
        ? downloads.value
        : downloads.value.filter((item) => item.status === selectedStatus.value),
);

function sortMetric(item: DownloadItem, key: SortKey): number | null {
    if (key === 'progress') {
        return currentProgress(item.id);
    }

    const value = key === 'queuedAt'
        ? item.queuedAt
        : key === 'startedAt'
            ? item.startedAt
            : item.completedAt;

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
    queued: 'bg-twilight-indigo-500 border border-blue-slate-500 text-twilight-indigo-100',
    processing: 'bg-smart-blue-600 border border-smart-blue-500 text-white',
    done: 'bg-success-600 border border-success-500 text-white',
    failed: 'bg-danger-600 border border-danger-500 text-white',
};

function statusClass(status: Status) {
    return STATUS_STYLES[status];
}

function filterLabel(status: FilterStatus) {
    if (status === 'all') return 'All';
    return status.charAt(0).toUpperCase() + status.slice(1);
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

function currentProgress(id: number) {
    return progressOverrides.value[id] ?? detailsById.value[id]?.progress ?? 0;
}

function setProgress(id: number, value: number) {
    const progress = normalizeProgress(value);
    progressOverrides.value = { ...progressOverrides.value, [id]: progress };
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

function buildDetails(id: number): DownloadDetails {
    const progress = progressOverrides.value[id] ?? ((id * 17) % 101);
    const size = 512_000 + ((id * 104_729) % 48_000_000);
    const color = ((id * 57) % 360).toString();
    const thumbnailUrl = `data:image/svg+xml;utf8,${encodeURIComponent(
        `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
            <defs>
                <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="hsl(${color} 70% 45%)" />
                    <stop offset="1" stop-color="hsl(${(parseInt(color, 10) + 40) % 360} 70% 35%)" />
                </linearGradient>
            </defs>
            <rect width="64" height="64" rx="12" fill="url(#g)" />
            <text x="50%" y="52%" font-size="18" font-family="Arial, sans-serif" fill="white" text-anchor="middle">${id}</text>
        </svg>`,
    )}`;

    return {
        path: `/downloads/${id}.bin`,
        url: `https://example.test/downloads/${id}`,
        thumbnailUrl,
        progress,
        size,
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
            queuedAt: now,
            startedAt: status === 'processing' ? now : null,
            completedAt: null,
        },
    ];

    if (status === 'processing') {
        setProgress(id, initialProgress ?? Math.floor(Math.random() * 12));
    } else {
        setProgress(id, 0);
    }

    detailsById.value = {
        ...detailsById.value,
        [id]: buildDetails(id),
    };

    queueFetchAfterIdle();
}

function seedInitialDownloads() {
    const seeded = createInitialDownloads(100);
    downloads.value = seeded;
    nextId.value = seeded.length + 1;
    progressOverrides.value = seeded.reduce((acc, item) => {
        if (item.status === 'processing') {
            acc[item.id] = 5 + (item.id % 15);
        }
        if (item.status === 'done') {
            acc[item.id] = 100;
        }
        return acc;
    }, {} as Record<number, number>);
    isInitialLoading.value = false;
    queueFetchAfterIdle();
}

function pickRandomId(items: DownloadItem[]) {
    if (!items.length) return null;
    return items[Math.floor(Math.random() * items.length)]?.id ?? null;
}

function pickProcessingId(preferVisible = false) {
    const processing = downloads.value.filter((item) => item.status === 'processing');
    if (!processing.length) return null;

    if (preferVisible) {
        const visibleProcessingIds = new Set(
            visibleIds.value.filter((item) => item.status === 'processing').map((item) => item.id),
        );
        const visibleProcessing = processing.filter((item) => visibleProcessingIds.has(item.id));
        const visiblePick = pickRandomId(visibleProcessing);
        if (visiblePick !== null) return visiblePick;
    }

    return pickRandomId(processing);
}

function pickQueuedId(preferVisible = false) {
    const queued = downloads.value.filter((item) => item.status === 'queued');
    if (!queued.length) return null;

    if (preferVisible) {
        const visibleQueuedIds = new Set(
            visibleIds.value.filter((item) => item.status === 'queued').map((item) => item.id),
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
        status: 'processing',
        startedAt: current.startedAt ?? now,
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
            status: 'done',
            startedAt: current.startedAt ?? now,
            completedAt: now,
        }));
        setProgress(id, 100);
    } else {
        updateDownload(id, (current) => ({
            ...current,
            startedAt: current.startedAt ?? now,
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
        startedAt: current.startedAt ?? now,
        completedAt: null,
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

    addDownload('processing', Math.floor(Math.random() * 14));
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
    if (activeFetchTimeout) {
        clearTimeout(activeFetchTimeout);
        activeFetchTimeout = null;
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

function fetchVisibleDetails() {
    const idsToFetch = visibleIds.value
        .map((item) => item.id)
        .filter((id) => !detailsById.value[id]);

    if (!idsToFetch.length) return;

    cancelActiveRequest();
    const requestToken = activeRequestToken;

    activeFetchTimeout = setTimeout(() => {
        if (requestToken !== activeRequestToken) return;

        const nextDetails = { ...detailsById.value };
        for (const id of idsToFetch) {
            nextDetails[id] = buildDetails(id);
        }
        detailsById.value = nextDetails;
        activeFetchTimeout = null;
    }, FETCH_DELAY_MS);
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

onMounted(() => {
    updateContainerHeight();
    window.addEventListener('resize', updateContainerHeight);
    isInitialLoading.value = true;
    initialLoadTimeout = setTimeout(() => {
        seedInitialDownloads();
    }, INITIAL_LOAD_DELAY_MS);
    startSocketSimulation();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateContainerHeight);
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    if (initialLoadTimeout) {
        clearTimeout(initialLoadTimeout);
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
                        class="inline-flex items-center rounded border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
                        :class="selectedStatus === status
                            ? 'border-smart-blue-500 bg-smart-blue-600 text-white'
                            : 'border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500'"
                        @click="selectedStatus = status"
                    >
                        {{ filterLabel(status) }}
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
                        >
                            <span>Progress</span>
                            <span v-if="sortState('progress')" class="text-[10px] text-blue-slate-400">
                                {{ sortState('progress')?.toUpperCase() }}
                            </span>
                        </button>
                        <span class="w-20 text-right">Size</span>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('queuedAt')"
                        >
                            <span>Queued</span>
                            <span v-if="sortState('queuedAt')" class="text-[10px] text-blue-slate-400">
                                {{ sortState('queuedAt')?.toUpperCase() }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('startedAt')"
                        >
                            <span>Started</span>
                            <span v-if="sortState('startedAt')" class="text-[10px] text-blue-slate-400">
                                {{ sortState('startedAt')?.toUpperCase() }}
                            </span>
                        </button>
                        <button
                            type="button"
                            class="inline-flex w-28 items-center justify-end gap-1 text-blue-slate-300 hover:text-white"
                            @click="toggleSort('completedAt')"
                        >
                            <span>Completed</span>
                            <span v-if="sortState('completedAt')" class="text-[10px] text-blue-slate-400">
                                {{ sortState('completedAt')?.toUpperCase() }}
                            </span>
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
                                                v-if="detailsById[item.id]?.thumbnailUrl"
                                                :src="detailsById[item.id]?.thumbnailUrl"
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
                                                {{ detailsById[item.id]?.url }}
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
                                            <div v-if="detailsById[item.id]" class="h-1.5 w-full rounded bg-prussian-blue-600">
                                                <div
                                                    class="h-full rounded bg-smart-blue-500 transition-all"
                                                    :style="{ width: `${detailsById[item.id]?.progress ?? 0}%` }"
                                                ></div>
                                            </div>
                                            <Skeleton v-else class="h-2 w-full bg-prussian-blue-500/60" />
                                            <div v-if="detailsById[item.id]" class="mt-1 text-right text-[11px] text-blue-slate-300">
                                                {{ `${detailsById[item.id].progress}%` }}
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
                                            {{ formatTimestamp(item.queuedAt) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.startedAt) }}
                                        </div>
                                        <div class="w-28 text-right text-xs text-blue-slate-300">
                                            {{ formatTimestamp(item.completedAt) }}
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
