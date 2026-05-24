import { ref } from 'vue';
import type { AudioIdsResponse } from '@/types/audio';

export function useAudioSourceIdentityMaps() {
    const sourceById = ref<Record<number, string | null>>({});
    const sourceIdById = ref<Record<number, string | null>>({});
    const spotifyUriById = ref<Record<number, string | null>>({});

    function resetAudioSourceIdentityMaps(): void {
        sourceById.value = {};
        sourceIdById.value = {};
        spotifyUriById.value = {};
    }

    function mergeAudioSourceIdentityMaps(chunk: AudioIdsResponse): void {
        sourceById.value = {
            ...sourceById.value,
            ...chunk.sources,
        };
        sourceIdById.value = {
            ...sourceIdById.value,
            ...chunk.source_ids,
        };
        spotifyUriById.value = {
            ...spotifyUriById.value,
            ...chunk.spotify_uris,
        };
    }

    return {
        mergeAudioSourceIdentityMaps,
        resetAudioSourceIdentityMaps,
        sourceById,
        sourceIdById,
        spotifyUriById,
    };
}
