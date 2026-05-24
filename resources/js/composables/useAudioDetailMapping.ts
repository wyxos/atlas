import type { AudioDetail, AudioDetailsResponse } from '@/types/audio';

export function audioDetailFromResponseItem(item: AudioDetailsResponse['items'][number]): AudioDetail {
    return {
        title: item.title,
        source: item.source,
        source_id: item.source_id,
        spotify_uri: item.spotify_uri,
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

export function emptyAudioDetail(): AudioDetail {
    return {
        title: null,
        source: null,
        source_id: null,
        spotify_uri: null,
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
