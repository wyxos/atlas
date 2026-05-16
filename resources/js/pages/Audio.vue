<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import PageLayout from '../components/PageLayout.vue';
import AudioPlaylistPanel from '../components/AudioPlaylistPanel.vue';
import AudioTrackRow from '../components/AudioTrackRow.vue';
import VirtualList from '../components/VirtualList.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent } from '@/components/ui/sheet';
import type { AudioDetail, AudioDetailsResponse, AudioIdsResponse, AudioSourceFilter } from '@/types/audio';
import type { ReactionType } from '@/types/reaction';

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
const isPlaylistPanelOpen = ref(false);
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

function detailArtists(audioId: number): string {
    const details = detailsById.value[audioId];
    if (!details) {
        return 'Loading metadata...';
    }

    return details.artists.length > 0 ? details.artists.join(', ') : 'Unknown artist';
}

function detailAlbum(audioId: number): string {
    const details = detailsById.value[audioId];
    if (!details) {
        return 'Loading metadata...';
    }

    return details.albums.length > 0 ? details.albums[0] ?? 'Unknown album' : 'Unknown album';
}

function detailSource(audioId: number): string | null {
    const source = detailsById.value[audioId]?.source?.trim();

    return source && source !== '' ? source : sourceById.value[audioId] ?? null;
}

function sourceForAudioId(audioId: number): string | null {
    return detailSource(audioId);
}

function detailCoverUrl(audioId: number): string | null {
    return detailsById.value[audioId]?.cover_url ?? null;
}

function detailReaction(audioId: number): { type: ReactionType } | null {
    return detailsById.value[audioId]?.reaction ?? null;
}

function detailBlacklistedAt(audioId: number): string | null {
    return detailsById.value[audioId]?.blacklisted_at ?? null;
}

function detailPreviewedCount(audioId: number): number {
    return detailsById.value[audioId]?.previewed_count ?? 0;
}

function detailSeenCount(audioId: number): number {
    return detailsById.value[audioId]?.seen_count ?? 0;
}

function detailDuration(audioId: number): string {
    const seconds = detailsById.value[audioId]?.duration_seconds;
    if (!seconds || seconds <= 0) {
        return '--:--';
    }

    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = Math.round(seconds % 60);

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

async function handleAudioReaction(audioId: number, type: ReactionType): Promise<void> {
    const { data } = await window.axios.post<{ reaction: { type: ReactionType } }>(`/api/files/${audioId}/reaction`, {
        type,
    });
    const details = detailsById.value[audioId];
    if (!details) {
        return;
    }

    detailsById.value = {
        ...detailsById.value,
        [audioId]: {
            ...details,
            reaction: data.reaction,
        },
    };
}

async function handleAudioBlacklist(audioId: number): Promise<void> {
    const details = detailsById.value[audioId];
    if (!details || details.blacklisted_at) {
        return;
    }

    const { data } = await window.axios.post<{
        results?: Array<{
            id: number;
            blacklisted_at: string | null;
            previewed_count?: number;
        }>;
    }>('/api/files/blacklist/batch', {
        file_ids: [audioId],
    });
    const result = data.results?.find((item) => item.id === audioId);
    if (!result) {
        return;
    }

    detailsById.value = {
        ...detailsById.value,
        [audioId]: {
            ...details,
            blacklisted_at: result.blacklisted_at,
            previewed_count: result.previewed_count ?? details.previewed_count,
        },
    };
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
                cover_url: item.cover_url,
                duration_seconds: item.duration_seconds,
                reaction: item.reaction,
                blacklisted_at: item.blacklisted_at,
                previewed_count: item.previewed_count,
                seen_count: item.seen_count,
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
                cover_url: null,
                duration_seconds: null,
                reaction: null,
                blacklisted_at: null,
                previewed_count: 0,
                seen_count: 0,
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
    <PageLayout flush>
        <div class="relative flex h-full min-h-0 w-full flex-col overflow-hidden" data-test="audio-page">
            <Sheet v-model:open="isFilterSheetOpen">
                <SheetContent side="right" class="w-full sm:max-w-sm">
                    <div class="space-y-4 px-6 pt-12" data-test="audio-filter-sheet-body">
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
            <div
                v-else
                class="flex min-h-0 flex-1"
                data-test="audio-library-surface"
            >
                <AudioPlaylistPanel v-if="isPlaylistPanelOpen" />
                <div
                    class="flex min-h-0 flex-1 flex-col border border-twilight-indigo-500 bg-prussian-blue-700"
                    data-test="audio-list-shell"
                >
                <div
                    class="flex h-10 shrink-0 items-center justify-between border-b border-twilight-indigo-500/70 px-3 md:px-4"
                    data-test="audio-list-header"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="audio-playlists-cta"
                        class="h-7 shrink-0 px-2 text-xs"
                        @click="isPlaylistPanelOpen = !isPlaylistPanelOpen"
                    >
                        Playlists
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        data-test="audio-filter-cta"
                        class="h-7 shrink-0 px-2 text-xs"
                        @click="isFilterSheetOpen = true"
                    >
                        Filter: {{ activeFilterLabel }}
                    </Button>
                </div>
                <div v-if="isLoading" class="p-4 text-twilight-indigo-100">Preparing full audio index...</div>
                <div v-else-if="filteredAudioIds.length === 0" class="p-4 text-twilight-indigo-100">
                    No audio files match this filter.
                </div>
                <VirtualList
                    v-else
                    :items="filteredAudioIds"
                    :item-height="72"
                    :overscan="4"
                    container-class="min-h-0 flex-1 overflow-y-auto"
                    @scroll="handleVirtualListScroll"
                    @visible-items-change="handleVisibleItemsChange"
                >
                    <template #default="{ items, startIndex }">
                        <ul class="divide-y divide-twilight-indigo-500/70">
                            <AudioTrackRow
                                v-for="(audioId, index) in items"
                                :key="audioId"
                                :audio-id="audioId"
                                :display-index="startIndex + index + 1"
                                :is-loaded="hasDetails(audioId)"
                                :title="detailTitle(audioId)"
                                :artists="detailArtists(audioId)"
                                :album="detailAlbum(audioId)"
                                :cover-url="detailCoverUrl(audioId)"
                                :reaction="detailReaction(audioId)"
                                :blacklisted-at="detailBlacklistedAt(audioId)"
                                :previewed-count="detailPreviewedCount(audioId)"
                                :seen-count="detailSeenCount(audioId)"
                                :duration="detailDuration(audioId)"
                                @reaction="handleAudioReaction"
                                @blacklist="handleAudioBlacklist"
                            />
                        </ul>
                    </template>
                </VirtualList>
                </div>
            </div>
        </div>
    </PageLayout>
</template>
