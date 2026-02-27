<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import VirtualList from '../components/VirtualList.vue';
import { Skeleton } from '@/components/ui/skeleton';

type AudioIdsResponse = {
    ids: number[];
    cursor: {
        after_id: number;
        next_after_id: number | null;
        has_more: boolean;
        max_id: number;
    };
    pagination: {
        per_page: number;
        total: number | null;
        total_pages: number | null;
    };
};

type AudioDetailsResponse = {
    items: Array<{
        id: number;
        title: string | null;
        source: string | null;
        artists: string[];
        albums: string[];
    }>;
};

type AudioDetail = {
    title: string | null;
    source: string | null;
    artists: string[];
    albums: string[];
};

const PER_PAGE = 100;
const SCROLL_IDLE_MS = 180;

const audioIds = ref<number[]>([]);
const loadedPages = ref(0);
const totalPages = ref(0);
const totalAudioFiles = ref(0);
const isLoading = ref(false);
const error = ref<string | null>(null);
const visibleIds = ref<number[]>([]);
const detailsById = ref<Record<number, AudioDetail>>({});

const progressPercent = computed(() => {
    if (totalPages.value === 0) {
        return 100;
    }

    return Math.round((loadedPages.value / totalPages.value) * 100);
});

let isDisposed = false;
let activeRequestToken = 0;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let detailsAbortController: AbortController | null = null;

async function fetchChunk(afterId: number, maxId: number | null): Promise<AudioIdsResponse> {
    const params = {
        after_id: afterId,
        per_page: PER_PAGE,
        ...(maxId !== null ? { max_id: maxId } : {}),
    };
    const { data } = await window.axios.get<AudioIdsResponse>('/api/audio/ids', {
        params,
    });

    return data;
}

async function loadAllAudioIds(): Promise<void> {
    isLoading.value = true;
    error.value = null;
    audioIds.value = [];
    loadedPages.value = 0;
    totalPages.value = 0;
    totalAudioFiles.value = 0;
    visibleIds.value = [];
    detailsById.value = {};

    try {
        let afterId = 0;
        let maxId: number | null = null;
        let hasMore = true;

        while (hasMore) {
            const nextChunk = await fetchChunk(afterId, maxId);
            if (isDisposed) {
                return;
            }

            if (maxId === null) {
                maxId = nextChunk.cursor.max_id;
                totalAudioFiles.value = nextChunk.pagination.total ?? 0;
                totalPages.value = nextChunk.pagination.total_pages ?? (totalAudioFiles.value > 0 ? 1 : 0);
            }

            audioIds.value.push(...nextChunk.ids);
            if (totalPages.value > 0) {
                loadedPages.value = Math.min(totalPages.value, loadedPages.value + 1);
            }

            afterId = nextChunk.cursor.next_after_id ?? afterId;
            hasMore = nextChunk.cursor.has_more && nextChunk.cursor.next_after_id !== null;
        }
    } catch (loadError) {
        console.error('Failed to load audio IDs:', loadError);
        error.value = 'Failed to load audio IDs.';
    } finally {
        if (!isDisposed) {
            isLoading.value = false;
            queueFetchAfterIdle();
        }
    }
}

function detailTitle(audioId: number): string {
    const title = detailsById.value[audioId]?.title;

    return title && title.trim() !== '' ? title : `Audio #${audioId}`;
}

function hasDetails(audioId: number): boolean {
    return detailsById.value[audioId] !== undefined;
}

function detailSubtitle(audioId: number): string {
    const details = detailsById.value[audioId];
    if (!details) {
        return 'Loading metadata...';
    }

    const artists = details.artists.length > 0 ? details.artists.join(', ') : 'Unknown artist';
    const albums = details.albums.length > 0 ? details.albums.join(', ') : 'Unknown album';

    return `${artists} • ${albums}`;
}

function detailSource(audioId: number): string | null {
    const source = detailsById.value[audioId]?.source?.trim();

    return source && source !== '' ? source : null;
}

function isSpotifySource(source: string | null): boolean {
    return source !== null && source.toLowerCase() === 'spotify';
}

function isSpotifyTrack(audioId: number): boolean {
    return isSpotifySource(detailSource(audioId));
}

function sourceTooltip(audioId: number): string {
    const source = detailSource(audioId);

    return source ? `Source: ${source}` : 'Source: Unknown';
}

function cancelActiveRequest() {
    if (detailsAbortController) {
        detailsAbortController.abort();
        detailsAbortController = null;
    }
    activeRequestToken += 1;
}

function queueFetchAfterIdle() {
    if (isLoading.value) {
        return;
    }

    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }

    idleTimeout = setTimeout(() => {
        idleTimeout = null;
        void fetchVisibleDetails();
    }, SCROLL_IDLE_MS);
}

function handleVisibleItemsChange(items: unknown[]) {
    visibleIds.value = items
        .map((item) => Number(item))
        .filter((item) => Number.isInteger(item) && item > 0);
    queueFetchAfterIdle();
}

function handleVirtualListScroll() {
    cancelActiveRequest();
    queueFetchAfterIdle();
}

async function fetchVisibleDetails(): Promise<void> {
    const ids = Array.from(new Set(visibleIds.value));
    const idsToFetch = ids.filter((id) => detailsById.value[id] === undefined);

    if (idsToFetch.length === 0) {
        return;
    }

    cancelActiveRequest();
    const requestToken = activeRequestToken;
    const controller = new AbortController();
    detailsAbortController = controller;

    try {
        const { data } = await window.axios.post<AudioDetailsResponse>('/api/audio/details', {
            ids: idsToFetch,
        }, {
            signal: controller.signal,
        });

        if (requestToken !== activeRequestToken) {
            return;
        }

        const nextDetails = { ...detailsById.value };
        const returnedIds = new Set<number>();

        for (const item of data.items) {
            returnedIds.add(item.id);
            nextDetails[item.id] = {
                title: item.title,
                source: item.source,
                artists: item.artists,
                albums: item.albums,
            };
        }

        for (const id of idsToFetch) {
            if (returnedIds.has(id)) {
                continue;
            }

            nextDetails[id] = {
                title: null,
                source: null,
                artists: [],
                albums: [],
            };
        }

        detailsById.value = nextDetails;
    } catch {
        if (controller.signal.aborted) {
            return;
        }
    } finally {
        if (detailsAbortController === controller) {
            detailsAbortController = null;
        }
    }
}

onMounted(() => {
    void loadAllAudioIds();
});

onUnmounted(() => {
    isDisposed = true;
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    cancelActiveRequest();
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-6">
                <h4 class="text-2xl font-semibold text-regal-navy-100 mb-2">Audio</h4>
                <p class="text-blue-slate-300">All audio file IDs</p>
            </div>

            <div class="mb-4 rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-4">
                <div class="mb-2 flex items-center justify-between text-sm text-twilight-indigo-100">
                    <span>Pages: {{ loadedPages }} / {{ totalPages }}</span>
                    <span>{{ progressPercent }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-twilight-indigo-600">
                    <div
                        class="h-2 rounded-full bg-smart-blue-400 transition-[width] duration-200"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
                <div class="mt-2 text-xs text-blue-slate-300">
                    IDs loaded: {{ audioIds.length }} / {{ totalAudioFiles }}
                    <span v-if="isLoading" class="ml-2">Loading...</span>
                </div>
            </div>

            <div v-if="error" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ error }}
            </div>
            <div v-else class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
                <div v-if="isLoading" class="p-4 text-twilight-indigo-100">Preparing full audio index...</div>
                <div v-else-if="audioIds.length === 0" class="p-4 text-twilight-indigo-100">
                    No audio files found.
                </div>
                <VirtualList
                    v-else
                    :items="audioIds"
                    :item-height="64"
                    :overscan="4"
                    container-class="max-h-[70vh] overflow-y-auto"
                    @scroll="handleVirtualListScroll"
                    @visible-items-change="handleVisibleItemsChange"
                >
                    <template #default="{ items }">
                        <ul class="divide-y divide-twilight-indigo-500">
                            <li
                                v-for="audioId in items"
                                :key="audioId"
                                class="h-16 px-4 text-twilight-indigo-100 flex flex-col justify-center gap-1"
                            >
                                <p class="font-mono text-xs text-blue-slate-300">#{{ audioId }}</p>
                                <template v-if="hasDetails(audioId)">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <p class="truncate text-sm">{{ detailTitle(audioId) }}</p>
                                        <span
                                            v-if="isSpotifyTrack(audioId)"
                                            class="inline-flex shrink-0 items-center rounded border border-emerald-400/40 bg-emerald-500/20 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-100"
                                            :title="sourceTooltip(audioId)"
                                        >
                                            Spotify
                                        </span>
                                    </div>
                                    <p class="truncate text-xs text-blue-slate-300">{{ detailSubtitle(audioId) }}</p>
                                </template>
                                <template v-else>
                                    <Skeleton class="h-4 w-2/3 bg-prussian-blue-500/60" />
                                    <Skeleton class="h-3 w-1/2 bg-prussian-blue-500/60" />
                                </template>
                            </li>
                        </ul>
                    </template>
                </VirtualList>
            </div>
        </div>
    </PageLayout>
</template>
