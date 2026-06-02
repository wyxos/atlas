import { onBeforeUnmount, watch } from 'vue';
import { AUDIO_PLAYBACK_STATS_EVENT, type AudioPlaybackStatsEventDetail } from '@/composables/useGlobalAudioPlayer';
import type { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';

type GlobalAudioPlayer = ReturnType<typeof useGlobalAudioPlayer>;
type PlaybackEventType = 'play' | 'skip';

let activeRecorderToken: symbol | null = null;

export function useAudioPlaybackStatsRecorder(audioPlayer: GlobalAudioPlayer) {
    const recorderToken = Symbol('atlas-audio-playback-stats-recorder');
    const naturallyEndedTrackIds = new Set<number>();
    let countedPlayTrackId: number | null = null;
    let countedSkipTrackId: number | null = null;
    activeRecorderToken = recorderToken;

    function isActiveRecorder(): boolean {
        return activeRecorderToken === recorderToken;
    }

    async function recordPlaybackEvent(fileId: number, event: PlaybackEventType): Promise<void> {
        if (!isActiveRecorder() || !window.axios?.post) {
            return;
        }

        try {
            const { data } = await window.axios.post<AudioPlaybackStatsEventDetail>('/api/audio/playback-events', {
                event,
                file_id: fileId,
            });

            window.dispatchEvent(new CustomEvent<AudioPlaybackStatsEventDetail>(AUDIO_PLAYBACK_STATS_EVENT, {
                detail: data,
            }));

            if (audioPlayer.currentTrackId.value === data.file_id) {
                audioPlayer.updateCurrentTrack({
                    playCount: data.play_count,
                    skipCount: data.skip_count,
                });
            }
        } catch (error) {
            console.error('Failed to record audio playback event:', error);
        }
    }

    function recordPlayIfNeeded(fileId: number): void {
        if (countedPlayTrackId === fileId) {
            return;
        }

        countedPlayTrackId = fileId;
        void recordPlaybackEvent(fileId, 'play');
    }

    function recordSkipIfNeeded(fileId: number): void {
        if (countedSkipTrackId === fileId || naturallyEndedTrackIds.delete(fileId)) {
            return;
        }

        countedSkipTrackId = fileId;
        void recordPlaybackEvent(fileId, 'skip');
    }

    function handleTrackNaturallyEnded(trackId: number): void {
        naturallyEndedTrackIds.add(trackId);
        countedPlayTrackId = null;
        countedSkipTrackId = null;
        recordPlayIfNeeded(trackId);

        if (audioPlayer.repeatMode.value !== 'one') {
            return;
        }

        naturallyEndedTrackIds.delete(trackId);
        countedPlayTrackId = null;
    }

    watch([audioPlayer.currentTrackId, audioPlayer.isPlaying], ([trackId], [oldTrackId, wasPlaying]) => {
        if (!isActiveRecorder()) {
            return;
        }

        const previousTrackId = oldTrackId ?? null;
        const wasPreviouslyPlaying = Boolean(wasPlaying);

        if (previousTrackId !== null && previousTrackId !== trackId && wasPreviouslyPlaying) {
            recordSkipIfNeeded(previousTrackId);
        }

        if (previousTrackId !== trackId) {
            countedPlayTrackId = null;
            countedSkipTrackId = null;
        }
    }, { immediate: true, flush: 'sync' });

    onBeforeUnmount(() => {
        if (isActiveRecorder()) {
            activeRecorderToken = null;
        }
    });

    return {
        handleTrackNaturallyEnded,
    };
}
