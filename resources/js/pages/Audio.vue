<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import PageLayout from '../components/PageLayout.vue';
import AudioFilterSheet from '../components/AudioFilterSheet.vue';
import AudioListShell from '../components/AudioListShell.vue';
import AudioLoadProgressPanel from '../components/AudioLoadProgressPanel.vue';
import AudioPlaylistPanelFrame from '../components/AudioPlaylistPanelFrame.vue';
import AudioTrackDetailsSheet from '../components/AudioTrackDetailsSheet.vue';
import { useAudioDetailAccessors } from '../composables/useAudioDetailAccessors';
import { useAudioPlaylistPanelOpenState } from '../composables/useAudioPlaylistPanelOpenState';
import { useAudioPlaybackStatsEvents } from '../composables/useAudioPlaybackStatsEvents';
import { useAudioMetadataReview } from '../composables/useAudioMetadataReview';
import { useAudioSourceIdentityMaps } from '../composables/useAudioSourceIdentityMaps';
import { useAudioVisibleDetails } from '../composables/useAudioVisibleDetails';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '../composables/useGlobalAudioPlayer';
import type {
    AudioIdsResponse,
    AudioPlaylist,
    AudioPlaylistSection,
    AudioPlaylistsResponse,
    AudioSourceFilter,
} from '@/types/audio';
import type { ReactionType } from '@/types/reaction';

const PER_PAGE = 100;
const PROGRESS_HIDE_DELAY_MS = 350;

const audioIds = ref<number[]>([]);
const {
    mergeAudioSourceIdentityMaps,
    resetAudioSourceIdentityMaps,
    sourceById,
    sourceIdById,
    spotifyUriById,
} = useAudioSourceIdentityMaps();
const loadedPages = ref(0);
const totalPages = ref(0);
const totalAudioFiles = ref(0);
const isLoading = ref(false);
const error = ref<string | null>(null);
const isFilterSheetOpen = ref(false);
const { isPlaylistPanelOpen } = useAudioPlaylistPanelOpenState();
const activeFilter = ref<AudioSourceFilter>('all');
const playlistSections = ref<AudioPlaylistSection[]>([]);
const arePlaylistsLoading = ref(false);
const playlistsLoaded = ref(false);
const playlistsError = ref<string | null>(null);
const showProgressPanel = ref(true);
const selectedAudioId = ref<number | null>(null);
const audioListShellRef = ref<InstanceType<typeof AudioListShell> | null>(null);
const audioPlayer = useGlobalAudioPlayer();
const playerCurrentTrackId = audioPlayer.currentTrackId;
const playerIsPlaying = audioPlayer.isPlaying;
const route = useRoute();
const router = useRouter();
const {
    cancelActiveRequest,
    detailsById,
    disposeAudioDetails,
    fetchAudioDetails,
    handleVisibleItemsChange,
    handleVirtualListScroll,
    queueFetchAfterIdle,
    resetAudioDetails,
} = useAudioVisibleDetails(isLoading);
useAudioPlaybackStatsEvents(detailsById);

const {
    detailAlbum,
    detailArtists,
    detailBlacklistedAt,
    detailCoverUrl,
    detailDuration,
    detailPreviewedCount,
    detailPlayCount,
    detailReaction,
    detailSeenCount,
    detailSkipCount,
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

const activeFilterLabel = computed(() => ({ all: 'All', local: 'Library', spotify: 'Spotify' })[activeFilter.value]);

const activePlaylistLabel = computed(() => {
    const slug = activePlaylistSlug.value.replace(/^source-/, '');
    const fallbackLabel = slug === 'all' ? 'All audio' : slug.replace(/-/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());

    return playlistSections.value.flatMap((section) => section.playlists).find((playlist) => playlist.slug === activePlaylistSlug.value)?.name ?? fallbackLabel;
});

const activePlaylistSlug = computed(() => {
    const routeSlug = route.params.playlistSlug;

    if (typeof routeSlug !== 'string' || routeSlug.trim() === '') {
        return 'all';
    }

    return routeSlug;
});

let isDisposed = false;
let activeIdsRequestToken = 0;
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
    resetAudioSourceIdentityMaps();
    loadedPages.value = 0;
    totalPages.value = 0;
    totalAudioFiles.value = 0;
    resetAudioDetails();
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
            mergeAudioSourceIdentityMaps(nextChunk);
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

function audioPlayerTracksById(): Map<number, AudioPlayerTrack> {
    return new Map(audioPlayer.queue.value.map((track) => [track.id, track]));
}

function audioPlayerTrack(audioId: number, existingTracksById: Map<number, AudioPlayerTrack>): AudioPlayerTrack {
    const details = detailsById.value[audioId];

    if (details === undefined) {
        const existingTrack = existingTracksById.get(audioId);
        if (existingTrack) {
            return existingTrack;
        }
    }

    return {
        id: audioId,
        title: detailTitle(audioId),
        source: detailSource(audioId),
        sourceId: details?.source_id ?? sourceIdById.value[audioId] ?? null,
        spotifyUri: details?.spotify_uri ?? spotifyUriById.value[audioId] ?? null,
        artists: detailArtists(audioId),
        album: detailAlbum(audioId),
        coverUrl: detailCoverUrl(audioId),
        duration: detailDuration(audioId),
        durationSeconds: details?.duration_seconds ?? null,
        reaction: detailReaction(audioId),
        blacklistedAt: detailBlacklistedAt(audioId),
        previewedCount: detailPreviewedCount(audioId),
        seenCount: detailSeenCount(audioId),
        playCount: details?.play_count ?? 0,
        skipCount: details?.skip_count ?? 0,
        playbackUrl: `/api/files/${audioId}/serve`,
    };
}

function audioPlayerQueue(): AudioPlayerTrack[] {
    const existingTracksById = audioPlayerTracksById();

    return filteredAudioIds.value.map((audioId) => audioPlayerTrack(audioId, existingTracksById));
}

function hasReusableCurrentQueue(): boolean {
    return playerCurrentTrackId.value !== null
        && audioPlayer.queue.value.length > 0
        && filteredAudioIds.value.includes(playerCurrentTrackId.value);
}

function handleAudioSelect(audioId: number): void {
    selectedAudioId.value = audioId;

    if (hasReusableCurrentQueue()) {
        return;
    }

    audioPlayer.queueTracks(audioPlayerQueue(), audioId, { queueLabel: activePlaylistLabel.value });
}

function handleAudioPlay(audioId: number): void {
    selectedAudioId.value = audioId;
    audioPlayer.queueAndPlay(audioPlayerQueue(), audioId, { queueLabel: activePlaylistLabel.value });
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

function focusAudioTrackInList(audioId: number): void {
    if (!filteredAudioIds.value.includes(audioId)) {
        return;
    }

    selectedAudioId.value = audioId;
    audioListShellRef.value?.scrollToAudioId(audioId);
}

const {
    batchMetadataError,
    batchMetadataMessage,
    detailsSheetProposal,
    detailsSheetTrack,
    handleAudioDetailsOpen,
    handleBatchMetadataRun,
    handleMetadataProposalApply,
    handleMetadataProposalIgnore,
    handleRestoreMetadataFromFile,
    handleTrackMetadataRun,
    isMetadataProposalLoading,
    isMetadataProposalReviewing,
    isMetadataRestoring,
    isMetadataRunStarting,
    isTrackDetailsSheetOpen,
    metadataReviewError,
    metadataReviewMessage,
} = useAudioMetadataReview({
    activeFilter,
    selectedAudioId,
    hasDetails,
    fetchAudioDetails,
    detailTitle,
    detailArtists,
    detailAlbum,
    detailCoverUrl,
    detailSource,
    detailDuration,
});

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
}, { immediate: true });

watch(activePlaylistSlug, () => {
    activeFilter.value = 'all';
    cancelActiveRequest();
    void loadAllAudioIds();
});

watch(detailsById, (nextDetails, previousDetails) => {
    if (!audioPlayer.hasQueue.value) {
        return;
    }

    const queuedIds = new Set(audioPlayer.queue.value.map((track) => track.id));
    const existingTracksById = audioPlayerTracksById();
    const changedQueuedTracks = Object.keys(nextDetails).flatMap((audioId) => {
        const id = Number(audioId);

        if (!Number.isInteger(id) || !queuedIds.has(id) || nextDetails[id] === previousDetails?.[id]) {
            return [];
        }

        return [audioPlayerTrack(id, existingTracksById)];
    });

    if (changedQueuedTracks.length === 0) {
        return;
    }

    audioPlayer.updateQueuedTracks(changedQueuedTracks);
});

watch(audioPlayer.trackFocusRequest, (request) => {
    if (!request) {
        return;
    }

    focusAudioTrackInList(request.trackId);
});

onUnmounted(() => {
    isDisposed = true;
    if (progressHideTimeout) {
        clearTimeout(progressHideTimeout);
    }
    disposeAudioDetails();
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
            <AudioTrackDetailsSheet
                v-model:open="isTrackDetailsSheetOpen"
                :track="detailsSheetTrack" :proposal="detailsSheetProposal"
                :is-proposal-loading="isMetadataProposalLoading" :is-running="isMetadataRunStarting"
                :is-reviewing="isMetadataProposalReviewing" :is-restoring="isMetadataRestoring"
                :message="metadataReviewMessage" :error="metadataReviewError"
                @run-metadata="handleTrackMetadataRun"
                @restore-from-file="handleRestoreMetadataFromFile" @apply-proposal="handleMetadataProposalApply"
                @ignore-proposal="handleMetadataProposalIgnore"
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

            <div v-if="batchMetadataMessage || batchMetadataError" class="border-x border-twilight-indigo-500 bg-prussian-blue-800 px-4 py-2 text-xs">
                <p v-if="batchMetadataMessage" class="text-smart-blue-100">{{ batchMetadataMessage }}</p>
                <p v-if="batchMetadataError" class="text-danger-100">{{ batchMetadataError }}</p>
            </div>

            <div v-if="error" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ error }}
            </div>
            <div
                v-else
                class="relative flex min-h-0 flex-1 overflow-hidden"
                data-test="audio-library-surface"
            >
                <AudioPlaylistPanelFrame
                    :is-open="isPlaylistPanelOpen"
                    :sections="playlistSections"
                    :active-slug="activePlaylistSlug"
                    :is-loading="arePlaylistsLoading"
                    :error="playlistsError"
                    @close="isPlaylistPanelOpen = false"
                    @select="handlePlaylistSelect"
                />
                <AudioListShell
                    ref="audioListShellRef"
                    :active-filter-label="activeFilterLabel"
                    :audio-ids="filteredAudioIds"
                    :can-shuffle-play="filteredAudioIds.length > 0 && !isLoading"
                    :has-queue="audioPlayer.hasQueue.value"
                    :is-loading="isLoading"
                    :has-details="hasDetails"
                    :detail-title="detailTitle"
                    :detail-artists="detailArtists"
                    :detail-album="detailAlbum"
                    :detail-cover-url="detailCoverUrl"
                    :detail-source="detailSource"
                    :detail-reaction="detailReaction"
                    :detail-blacklisted-at="detailBlacklistedAt"
                    :detail-previewed-count="detailPreviewedCount"
                    :detail-seen-count="detailSeenCount"
                    :detail-play-count="detailPlayCount"
                    :detail-skip-count="detailSkipCount"
                    :detail-duration="detailDuration"
                    :selected-audio-id="selectedAudioId"
                    :current-track-id="playerCurrentTrackId"
                    :is-playing="playerIsPlaying"
                    @toggle-queue="audioPlayer.toggleQueueSheet"
                    @toggle-playlists="isPlaylistPanelOpen = !isPlaylistPanelOpen"
                    @shuffle-play="audioPlayer.queueAndShufflePlay(audioPlayerQueue(), { queueLabel: activePlaylistLabel })"
                    @scan-metadata="handleBatchMetadataRun"
                    @open-filter="isFilterSheetOpen = true"
                    @scroll="handleVirtualListScroll"
                    @visible-items-change="handleVisibleItemsChange"
                    @select="handleAudioSelect"
                    @open-details="handleAudioDetailsOpen"
                    @play="handleAudioPlay"
                    @pause="handleAudioPause"
                    @reaction="handleAudioReaction"
                    @blacklist="handleAudioBlacklist"
                />
            </div>
        </div>
    </PageLayout>
</template>
