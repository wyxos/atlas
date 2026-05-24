import { onBeforeUnmount, watch, type ComputedRef, type Ref } from 'vue';
import type { AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

const FALLBACK_AUDIO_ARTWORK_SRC = 'data:image/svg+xml,%3Csvg%20xmlns=%22http://www.w3.org/2000/svg%22%20viewBox=%220%200%20512%20512%22%3E%3Crect%20width=%22512%22%20height=%22512%22%20fill=%22%23001126%22/%3E%3Cpath%20d=%22M320%20112v236a64%2064%200%201%201-32-55V160l-128%2032v188a64%2064%200%201%201-32-55V168z%22%20fill=%22%23b7d8ff%22/%3E%3C/svg%3E';

type MaybeRef<T> = Ref<T> | ComputedRef<T>;

type AudioMediaSessionOptions = {
    currentTime: MaybeRef<number>;
    currentTrack: MaybeRef<AudioPlayerTrack | null>;
    durationSeconds: MaybeRef<number>;
    isPlaying: MaybeRef<boolean>;
    onNext: () => void;
    onPause: () => void;
    onPlay: () => void;
    onPrevious: () => void;
    trackSubtitle: MaybeRef<string>;
};

function mediaSession(): MediaSession | null {
    if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) {
        return null;
    }

    return navigator.mediaSession ?? null;
}

function absoluteUrl(src: string): string {
    if (typeof window === 'undefined') {
        return src;
    }

    try {
        return new URL(src, window.location.origin).toString();
    } catch {
        return src;
    }
}

function artworkForTrack(track: AudioPlayerTrack): MediaImage[] {
    if (track.coverUrl?.trim()) {
        return [{ src: absoluteUrl(track.coverUrl.trim()), sizes: '512x512' }];
    }

    return [{ src: FALLBACK_AUDIO_ARTWORK_SRC, sizes: '512x512', type: 'image/svg+xml' }];
}

function setActionHandler(session: MediaSession, action: MediaSessionAction, handler: MediaSessionActionHandler | null): void {
    try {
        session.setActionHandler(action, handler);
    } catch {
        // Some browsers expose Media Session but not every action.
    }
}

export function useAudioMediaSession(options: AudioMediaSessionOptions): void {
    function updateMetadata(): void {
        const session = mediaSession();
        const track = options.currentTrack.value;

        if (!session) {
            return;
        }

        if (!track || typeof window.MediaMetadata !== 'function') {
            session.metadata = null;
            return;
        }

        session.metadata = new window.MediaMetadata({
            album: track.album,
            artist: options.trackSubtitle.value,
            artwork: artworkForTrack(track),
            title: track.title,
        });
    }

    function updatePlaybackState(): void {
        const session = mediaSession();
        if (!session) {
            return;
        }

        session.playbackState = !options.currentTrack.value
            ? 'none'
            : options.isPlaying.value ? 'playing' : 'paused';
    }

    function updatePositionState(): void {
        const session = mediaSession();
        const track = options.currentTrack.value;
        const duration = Number.isFinite(options.durationSeconds.value) ? Math.max(0, options.durationSeconds.value) : 0;

        if (!session?.setPositionState || !track || duration <= 0) {
            return;
        }

        try {
            session.setPositionState({
                duration,
                playbackRate: 1,
                position: Math.min(duration, Math.max(0, options.currentTime.value)),
            });
        } catch {
            // Ignore invalid platform position-state input instead of breaking playback.
        }
    }

    function updateActionHandlers(): void {
        const session = mediaSession();
        const hasTrack = options.currentTrack.value !== null;

        if (!session) {
            return;
        }

        setActionHandler(session, 'play', hasTrack ? options.onPlay : null);
        setActionHandler(session, 'pause', hasTrack ? options.onPause : null);
        setActionHandler(session, 'previoustrack', hasTrack ? options.onPrevious : null);
        setActionHandler(session, 'nexttrack', hasTrack ? options.onNext : null);
    }

    watch(() => [options.currentTrack.value, options.trackSubtitle.value] as const, updateMetadata, { immediate: true });
    watch(() => [options.currentTrack.value, options.isPlaying.value] as const, updatePlaybackState, { immediate: true });
    watch(
        () => [options.currentTrack.value?.id ?? null, options.currentTime.value, options.durationSeconds.value] as const,
        updatePositionState,
        { immediate: true },
    );
    watch(() => options.currentTrack.value?.id ?? null, updateActionHandlers, { immediate: true });

    onBeforeUnmount(() => {
        const session = mediaSession();
        if (!session) {
            return;
        }

        session.metadata = null;
        session.playbackState = 'none';
        setActionHandler(session, 'play', null);
        setActionHandler(session, 'pause', null);
        setActionHandler(session, 'previoustrack', null);
        setActionHandler(session, 'nexttrack', null);
    });
}
