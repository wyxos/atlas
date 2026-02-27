<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import VirtualList from '../components/VirtualList.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';

type AudioIdsResponse = {
    ids: number[];
    sources: Record<number, string | null>;
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

type AudioSourceFilter = 'all' | 'spotify' | 'local';

const PER_PAGE = 100;
const SCROLL_IDLE_MS = 180;
const PROGRESS_HIDE_DELAY_MS = 350;

const audioIds = ref<number[]>([]);
const sourceById = ref<Record<number, string | null>>({});
const loadedPages = ref(0);
const totalPages = ref(0);
const totalAudioFiles = ref(0);
const isLoading = ref(false);
const error = ref<string | null>(null);
const isFilterSheetOpen = ref(false);
const activeFilter = ref<AudioSourceFilter>('all');
const showProgressPanel = ref(true);
const visibleIds = ref<number[]>([]);
const detailsById = ref<Record<number, AudioDetail>>({});

const progressPercent = computed(() => {
    if (totalPages.value === 0) {
        return 100;
    }

    return Math.round((loadedPages.value / totalPages.value) * 100);
});

const filteredAudioIds = computed(() => {
    if (activeFilter.value === 'all') {
        return audioIds.value;
    }

    return audioIds.value.filter((id) => {
        const source = sourceForAudioId(id);
        if (!source) {
            return false;
        }

        if (activeFilter.value === 'spotify') {
            return source.toLowerCase() === 'spotify';
        }

        return source.toLowerCase() === 'local';
    });
});

const activeFilterLabel = computed(() => {
    if (activeFilter.value === 'spotify') {
        return 'Spotify';
    }

    if (activeFilter.value === 'local') {
        return 'Local';
    }

    return 'All';
});

let isDisposed = false;
let activeRequestToken = 0;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let detailsAbortController: AbortController | null = null;
let progressHideTimeout: ReturnType<typeof setTimeout> | null = null;

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
    sourceById.value = {};
    loadedPages.value = 0;
    totalPages.value = 0;
    totalAudioFiles.value = 0;
    visibleIds.value = [];
    detailsById.value = {};
    showProgressPanel.value = true;

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
            sourceById.value = {
                ...sourceById.value,
                ...nextChunk.sources,
            };
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

    return source && source !== '' ? source : sourceById.value[audioId] ?? null;
}

function sourceForAudioId(audioId: number): string | null {
    return detailSource(audioId);
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

watch([isLoading, progressPercent], ([loading, percent]) => {
    if (loading || percent < 100) {
        showProgressPanel.value = true;
        if (progressHideTimeout) {
            clearTimeout(progressHideTimeout);
            progressHideTimeout = null;
        }

        return;
    }

    if (progressHideTimeout) {
        clearTimeout(progressHideTimeout);
    }

    progressHideTimeout = setTimeout(() => {
        showProgressPanel.value = false;
        progressHideTimeout = null;
    }, PROGRESS_HIDE_DELAY_MS);
}, { immediate: true });

onUnmounted(() => {
    isDisposed = true;
    if (idleTimeout) {
        clearTimeout(idleTimeout);
    }
    if (progressHideTimeout) {
        clearTimeout(progressHideTimeout);
    }
    cancelActiveRequest();
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <h4 class="text-2xl font-semibold text-regal-navy-100 mb-2">Audio</h4>
                    <p class="text-blue-slate-300">All audio file IDs</p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="audio-filter-cta"
                    class="shrink-0"
                    @click="isFilterSheetOpen = true"
                >
                    Filter: {{ activeFilterLabel }}
                </Button>
            </div>

            <Sheet v-model:open="isFilterSheetOpen">
                <SheetContent side="right" class="w-full sm:max-w-sm">
                    <SheetHeader>
                        <SheetTitle>Audio Filter</SheetTitle>
                    </SheetHeader>
                    <div class="mt-6 space-y-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-twilight-indigo-200">Source</p>
                        <div class="inline-flex rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-1">
                            <Button
                                type="button"
                                size="sm"
                                :variant="activeFilter === 'all' ? 'default' : 'ghost'"
                                data-test="audio-filter-all"
                                @click="activeFilter = 'all'"
                            >
                                All
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                :variant="activeFilter === 'spotify' ? 'default' : 'ghost'"
                                data-test="audio-filter-spotify"
                                @click="activeFilter = 'spotify'"
                            >
                                Spotify
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                :variant="activeFilter === 'local' ? 'default' : 'ghost'"
                                data-test="audio-filter-local"
                                @click="activeFilter = 'local'"
                            >
                                Local
                            </Button>
                        </div>
                        <p class="text-xs text-blue-slate-300">
                            Showing {{ filteredAudioIds.length }} of {{ audioIds.length }}
                        </p>
                    </div>
                </SheetContent>
            </Sheet>

            <Transition
                enter-active-class="transition-all duration-250 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition-all duration-350 ease-in"
                leave-from-class="translate-y-0 opacity-100 max-h-32"
                leave-to-class="-translate-y-3 opacity-0 max-h-0"
            >
                <div
                    v-if="showProgressPanel"
                    class="mb-4 overflow-hidden rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-4"
                    data-test="audio-progress-panel"
                >
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
            </Transition>

            <div v-if="error" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ error }}
            </div>
            <div v-else class="rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
                <div v-if="isLoading" class="p-4 text-twilight-indigo-100">Preparing full audio index...</div>
                <div v-else-if="filteredAudioIds.length === 0" class="p-4 text-twilight-indigo-100">
                    No audio files match this filter.
                </div>
                <VirtualList
                    v-else
                    :items="filteredAudioIds"
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
