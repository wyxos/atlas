import type { Ref } from 'vue';
import type { AudioDetail } from '@/types/audio';
import type { ReactionType } from '@/types/reaction';

export function useAudioDetailAccessors(
    detailsById: Ref<Record<number, AudioDetail>>,
    sourceById: Ref<Record<number, string | null>>,
) {
    function detailSource(audioId: number): string | null {
        const source = detailsById.value[audioId]?.source?.trim();

        return source && source !== '' ? source : sourceById.value[audioId] ?? null;
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

    return {
        detailAlbum: (audioId: number) => {
            const details = detailsById.value[audioId];

            return details && details.albums.length > 0 ? details.albums[0] ?? 'Unknown album' : 'Unknown album';
        },
        detailArtists: (audioId: number) => {
            const details = detailsById.value[audioId];

            return details
                ? details.artists.length > 0 ? details.artists.join(', ') : 'Unknown artist'
                : 'Loading metadata...';
        },
        detailBlacklistedAt: (audioId: number) => detailsById.value[audioId]?.blacklisted_at ?? null,
        detailCoverUrl: (audioId: number) => detailsById.value[audioId]?.cover_url ?? null,
        detailDuration,
        detailPreviewedCount: (audioId: number) => detailsById.value[audioId]?.previewed_count ?? 0,
        detailReaction: (audioId: number): { type: ReactionType } | null => detailsById.value[audioId]?.reaction ?? null,
        detailSeenCount: (audioId: number) => detailsById.value[audioId]?.seen_count ?? 0,
        detailSource,
        detailTitle: (audioId: number) => {
            const title = detailsById.value[audioId]?.title;

            return title && title.trim() !== '' ? title : `Audio #${audioId}`;
        },
        hasDetails: (audioId: number) => detailsById.value[audioId] !== undefined,
    };
}
