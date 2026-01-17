<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatFileSize } from '../utils/file';

const STATUSES = ['queued', 'processing', 'done', 'failed'] as const;
type Status = typeof STATUSES[number];
const FILTERS = ['all', ...STATUSES] as const;
type FilterStatus = typeof FILTERS[number];
const IDS = Array.from({ length: 100 }, (_, index) => ({
    id: index + 1,
    status: STATUSES[index % STATUSES.length],
}));

const ITEM_HEIGHT = 64;
const OVERSCAN = 12;
const SCROLL_IDLE_MS = 180;
const FETCH_DELAY_MS = 650;

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

let activeRequestToken = 0;
let activeFetchTimeout: ReturnType<typeof setTimeout> | null = null;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;

const filteredIds = computed(() =>
    selectedStatus.value === 'all'
        ? IDS
        : IDS.filter((item) => item.status === selectedStatus.value),
);
const totalHeight = computed(() => filteredIds.value.length * ITEM_HEIGHT);
const startIndex = computed(() =>
    Math.max(0, Math.floor(scrollTop.value / ITEM_HEIGHT) - OVERSCAN),
);
const endIndex = computed(() =>
    Math.min(
        filteredIds.value.length,
        Math.ceil((scrollTop.value + containerHeight.value) / ITEM_HEIGHT) + OVERSCAN,
    ),
);
const visibleIds = computed(() => filteredIds.value.slice(startIndex.value, endIndex.value));
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

function buildDetails(id: number): DownloadDetails {
    const progress = (id * 17) % 101;
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
    queueFetchAfterIdle();
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', updateContainerHeight);
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    cancelActiveRequest();
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

            <div class="mb-4 flex flex-wrap items-center gap-2">
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

            <div class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 overflow-hidden">
                <div
                    class="flex items-center justify-between border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300"
                >
                    <span>Download</span>
                    <div class="flex items-center gap-4">
                        <span class="w-24 text-right">Status</span>
                        <span class="w-28 text-right">Progress</span>
                        <span class="w-20 text-right">Size</span>
                    </div>
                </div>
                <div
                    ref="containerRef"
                    class="min-h-[60vh] max-h-[70vh] overflow-auto"
                    @scroll="onScroll"
                >
                    <div class="relative w-full" :style="{ height: `${totalHeight}px` }">
                        <div class="absolute left-0 right-0" :style="{ transform: `translateY(${offsetY}px)` }">
                            <div
                                v-for="item in visibleIds"
                                :key="item.id"
                                class="flex h-16 items-center justify-between border-b border-twilight-indigo-500/20 px-4 text-sm text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/60"
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
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
