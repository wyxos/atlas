<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageLayout from '../components/PageLayout.vue';
import AudioFilterSheet from '../components/AudioFilterSheet.vue';
import AudioListShell from '../components/AudioListShell.vue';
import AudioLoadProgressPanel from '../components/AudioLoadProgressPanel.vue';
import AudioPlaylistPanel from '../components/AudioPlaylistPanel.vue';
import { useAudioDetailAccessors } from '../composables/useAudioDetailAccessors';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '../composables/useGlobalAudioPlayer';
import type {
    AudioDetail,
    AudioDetailsResponse,
    AudioIdsResponse,
    AudioPlaylist,
    AudioPlaylistSection,
    AudioPlaylistsResponse,
    AudioSourceFilter,
} from '@/types/audio';
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
const playlistSections = ref<AudioPlaylistSection[]>([]);
const arePlaylistsLoading = ref(false);
const playlistsLoaded = ref(false);
const playlistsError = ref<string | null>(null);
const showProgressPanel = ref(true);
const visibleIds = ref<number[]>([]);
const detailsById = ref<Record<number, AudioDetail>>({});
const selectedAudioId = ref<number | null>(null);
const audioPlayer = useGlobalAudioPlayer();
const playerCurrentTrackId = audioPlayer.currentTrackId;
const playerIsPlaying = audioPlayer.isPlaying;
const route = useRoute();
const router = useRouter();

const {
    detailAlbum,
    detailArtists,
    detailBlacklistedAt,
    detailCoverUrl,
    detailDuration,
    detailPreviewedCount,
    detailReaction,
    detailSeenCount,
    detailSource,
    detailTitle,
    hasDetails,
} = useAudioDetailAccessors(detailsById, sourceById);

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
        const source = detailSource(id);
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

const activePlaylistSlug = computed(() => {
    const routeSlug = route.params.playlistSlug;

    if (typeof routeSlug !== 'string' || routeSlug.trim() === '') {
        return 'all';
    }

    return routeSlug;
});

let isDisposed = false;
let activeRequestToken = 0;
let activeIdsRequestToken = 0;
let idleTimeout: ReturnType<typeof setTimeout> | null = null;
let detailsAbortController: AbortController | null = null;
let progressHideTimeout: ReturnType<typeof setTimeout> | null = null;

async function fetchChunk(afterId: number, maxId: number | null, playlistSlug: string): Promise<AudioIdsResponse> {
    const params = {
        after_id: afterId,
        per_page: PER_PAGE,
        ...(maxId !== null ? { max_id: maxId } : {}),
        ...(playlistSlug !== 'all' ? { playlist: playlistSlug } : {}),
    };
    const { data } = await window.axios.get<AudioIdsResponse>('/api/audio/ids', {
        params,
    });

    return data;
}

async function loadAllAudioIds(): Promise<void> {
    const requestToken = ++activeIdsRequestToken;
    const playlistSlug = activePlaylistSlug.value;

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
            const nextChunk = await fetchChunk(afterId, maxId, playlistSlug);
            if (isDisposed || requestToken !== activeIdsRequestToken) {
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

async function loadPlaylists(): Promise<void> {
    if (arePlaylistsLoading.value || playlistsLoaded.value) {
        return;
    }

    arePlaylistsLoading.value = true;
    playlistsError.value = null;

    try {
        const { data } = await window.axios.get<AudioPlaylistsResponse>('/api/audio/playlists');
        playlistSections.value = Array.isArray(data.sections) ? data.sections : [];
        playlistsLoaded.value = true;
    } catch (playlistError) {
        console.error('Failed to load audio playlists:', playlistError);
        playlistsError.value = 'Failed to load playlists.';
    } finally {
        arePlaylistsLoading.value = false;
    }
}

function handlePlaylistSelect(playlist: AudioPlaylist): void {
    if (activePlaylistSlug.value === playlist.slug) {
        return;
    }

    activeFilter.value = 'all';
    void router.push({
        name: 'audio',
        params: {
            playlistSlug: playlist.slug,
        },
    });
}

function audioPlayerTrack(audioId: number): AudioPlayerTrack {
    const details = detailsById.value[audioId];

    return {
        id: audioId,
        title: detailTitle(audioId),
        artists: detailArtists(audioId),
        album: detailAlbum(audioId),
        coverUrl: detailCoverUrl(audioId),
        duration: detailDuration(audioId),
        durationSeconds: details?.duration_seconds ?? null,
        reaction: detailReaction(audioId),
        blacklistedAt: detailBlacklistedAt(audioId),
        previewedCount: detailPreviewedCount(audioId),
        seenCount: detailSeenCount(audioId),
        playbackUrl: `/api/files/${audioId}/serve`,
    };
}

function audioPlayerQueue(): AudioPlayerTrack[] {
    return filteredAudioIds.value.map(audioPlayerTrack);
}

function handleAudioSelect(audioId: number): void {
    selectedAudioId.value = audioId;
    audioPlayer.queueTracks(audioPlayerQueue(), audioId);
}

function handleAudioPlay(audioId: number): void {
    selectedAudioId.value = audioId;
    audioPlayer.queueAndPlay(audioPlayerQueue(), audioId);
}

function handleAudioPause(audioId: number): void {
    if (playerCurrentTrackId.value === audioId) {
        audioPlayer.pause();
    }
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

watch(isPlaylistPanelOpen, (isOpen) => {
    if (isOpen) {
        void loadPlaylists();
    }
});

watch(activePlaylistSlug, () => {
    activeFilter.value = 'all';
    cancelActiveRequest();
    void loadAllAudioIds();
});

watch(detailsById, () => {
    audioPlayer.updateQueuedTracks(audioPlayerQueue());
});

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
            <AudioFilterSheet
                v-model:open="isFilterSheetOpen"
                v-model:active-filter="activeFilter"
                :visible-count="filteredAudioIds.length"
                :total-count="audioIds.length"
            />

            <Transition
                enter-active-class="transition-all duration-250 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition-all duration-350 ease-in"
                leave-from-class="translate-y-0 opacity-100 max-h-32"
                leave-to-class="-translate-y-3 opacity-0 max-h-0"
            >
                <AudioLoadProgressPanel
                    v-if="showProgressPanel"
                    :loaded-pages="loadedPages"
                    :total-pages="totalPages"
                    :progress-percent="progressPercent"
                    :loaded-ids="audioIds.length"
                    :total-audio-files="totalAudioFiles"
                    :is-loading="isLoading"
                />
            </Transition>

            <div v-if="error" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ error }}
            </div>
            <div
                v-else
                class="flex min-h-0 flex-1"
                data-test="audio-library-surface"
            >
                <div
                    class="hidden min-h-0 shrink-0 overflow-hidden transition-[width] ease-in-out md:block"
                    :class="isPlaylistPanelOpen ? 'w-72 duration-500' : 'w-0 duration-300'"
                    data-test="audio-playlist-panel-frame"
                >
                    <Transition
                        enter-active-class="transition duration-500 ease-in-out"
                        enter-from-class="-translate-x-full opacity-0"
                        enter-to-class="translate-x-0 opacity-100"
                        leave-active-class="transition duration-300 ease-in-out"
                        leave-from-class="translate-x-0 opacity-100"
                        leave-to-class="-translate-x-full opacity-0"
                    >
                        <AudioPlaylistPanel
                            v-if="isPlaylistPanelOpen"
                            :sections="playlistSections"
                            :active-slug="activePlaylistSlug"
                            :is-loading="arePlaylistsLoading"
                            :error="playlistsError"
                            @select="handlePlaylistSelect"
                        />
                    </Transition>
                </div>
                <AudioListShell
                    :active-filter-label="activeFilterLabel"
                    :audio-ids="filteredAudioIds"
                    :is-loading="isLoading"
                    :has-details="hasDetails"
                    :detail-title="detailTitle"
                    :detail-artists="detailArtists"
                    :detail-album="detailAlbum"
                    :detail-cover-url="detailCoverUrl"
                    :detail-reaction="detailReaction"
                    :detail-blacklisted-at="detailBlacklistedAt"
                    :detail-previewed-count="detailPreviewedCount"
                    :detail-seen-count="detailSeenCount"
                    :detail-duration="detailDuration"
                    :selected-audio-id="selectedAudioId"
                    :current-track-id="playerCurrentTrackId"
                    :is-playing="playerIsPlaying"
                    @toggle-playlists="isPlaylistPanelOpen = !isPlaylistPanelOpen"
                    @open-filter="isFilterSheetOpen = true"
                    @scroll="handleVirtualListScroll"
                    @visible-items-change="handleVisibleItemsChange"
                    @select="handleAudioSelect"
                    @play="handleAudioPlay"
                    @pause="handleAudioPause"
                    @reaction="handleAudioReaction"
                    @blacklist="handleAudioBlacklist"
                />
            </div>
        </div>
    </PageLayout>
</template>
