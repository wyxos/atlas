import type { AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';
import type { SpotifyPlaybackSnapshot } from '@/services/spotifyPlayback';

export type PlaybackEngine = 'local' | 'spotify';

export function isSpotifyAudioTrack(track: AudioPlayerTrack | null): boolean {
    return Boolean(track?.spotifyUri && track.spotifyUri.trim() !== '');
}

export function canTrackOwnSpotifyPlayback(isPlaying: boolean, track: AudioPlayerTrack | null): boolean {
    return isPlaying && isSpotifyAudioTrack(track);
}

export function spotifyStartConfirmationSnapshot(
    confirmedSnapshot: SpotifyPlaybackSnapshot | null | undefined,
    durationSeconds: number,
    positionSeconds: number,
    trackUri: string,
): SpotifyPlaybackSnapshot {
    return confirmedSnapshot ?? {
        durationMs: Math.max(0, Math.round(durationSeconds * 1000)),
        paused: false,
        positionMs: Math.max(0, Math.round(positionSeconds * 1000)),
        trackUri,
    };
}
