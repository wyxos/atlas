import { ref, watch } from 'vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse } from '@/types/audio';

type GlobalAudioPlayer = ReturnType<typeof useGlobalAudioPlayer>;

const loadingQueueDetailIds = ref<Set<number>>(new Set());

function formatSeconds(value: number): string {
    const seconds = Math.max(0, Math.floor(value));
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

function formatOptionalDuration(value: number | null): string {
    return value && value > 0 ? formatSeconds(value) : '--:--';
}

function needsQueueDetails(track: AudioPlayerTrack): boolean {
    return track.artists === 'Loading metadata...';
}

function hydratedQueueTrack(
    item: AudioDetailsResponse['items'][number],
    existingTrack: AudioPlayerTrack,
): AudioPlayerTrack {
    const title = item.title?.trim();

    return {
        ...existingTrack,
        title: title && title !== '' ? title : existingTrack.title,
        source: item.source ?? existingTrack.source,
        sourceId: item.source_id ?? existingTrack.sourceId,
        spotifyUri: item.spotify_uri ?? existingTrack.spotifyUri,
        artists: item.artists.length > 0 ? item.artists.join(', ') : 'Unknown artist',
        album: item.albums[0] ?? 'Unknown album',
        coverUrl: item.cover_url,
        duration: formatOptionalDuration(item.duration_seconds),
        durationSeconds: item.duration_seconds,
        reaction: item.reaction,
        blacklistedAt: item.blacklisted_at,
        previewedCount: item.previewed_count,
        seenCount: item.seen_count,
    };
}

export function useAudioQueueDetails(audioPlayer: GlobalAudioPlayer) {
    function playbackWindowTracks(): AudioPlayerTrack[] {
        const tracks = audioPlayer.queue.value;
        const currentIndex = tracks.findIndex((track) => track.id === audioPlayer.currentTrackId.value);

        if (currentIndex < 0) {
            return [];
        }

        const candidateIndexes = new Set<number>([currentIndex]);

        if (currentIndex > 0) {
            candidateIndexes.add(currentIndex - 1);
        } else if (audioPlayer.repeatMode.value === 'all' && tracks.length > 1) {
            candidateIndexes.add(tracks.length - 1);
        }

        if (currentIndex < tracks.length - 1) {
            candidateIndexes.add(currentIndex + 1);
        } else if (audioPlayer.repeatMode.value === 'all' && tracks.length > 1) {
            candidateIndexes.add(0);
        }

        return [...candidateIndexes]
            .map((index) => tracks[index])
            .filter((track): track is AudioPlayerTrack => track !== undefined);
    }

    async function loadQueueDetails(tracks: AudioPlayerTrack[]): Promise<void> {
        const tracksById = new Map(tracks.map((track) => [track.id, track]));
        const tracksNeedingDetails = [...tracksById.values()].filter((track) =>
            needsQueueDetails(track)
            && !loadingQueueDetailIds.value.has(track.id));

        if (tracksNeedingDetails.length === 0) {
            return;
        }

        const ids = tracksNeedingDetails.map((track) => track.id);
        loadingQueueDetailIds.value = new Set([...loadingQueueDetailIds.value, ...ids]);

        try {
            const { data } = await window.axios.post<AudioDetailsResponse>('/api/audio/details', {
                ids,
            });
            const detailsById = new Map(data.items.map((item) => [item.id, item]));
            const hydratedTracks = audioPlayer.queue.value.flatMap((track) => {
                const details = detailsById.get(track.id);
                if (!details) {
                    return [];
                }

                return [hydratedQueueTrack(details, track)];
            });

            audioPlayer.updateQueuedTracks(hydratedTracks);
        } catch (error) {
            console.error('Failed to load queue audio details:', error);
        } finally {
            const requestedIds = new Set(ids);
            loadingQueueDetailIds.value = new Set([...loadingQueueDetailIds.value].filter((id) => !requestedIds.has(id)));
        }
    }

    async function handleQueueVisibleItemsChange(tracks: AudioPlayerTrack[]): Promise<void> {
        await loadQueueDetails(tracks);
    }

    function preloadPlaybackWindow(): void {
        void loadQueueDetails(playbackWindowTracks());
    }

    watch(
        () => audioPlayer.queue.value.map((track) => track.id).join(','),
        () => {
            loadingQueueDetailIds.value = new Set();
            preloadPlaybackWindow();
        },
    );

    watch(
        () => `${audioPlayer.currentTrackId.value ?? ''}:${audioPlayer.repeatMode.value}`,
        () => {
            preloadPlaybackWindow();
        },
        { immediate: true },
    );

    return {
        handleQueueVisibleItemsChange,
    };
}
