import { onMounted, onUnmounted, type Ref } from 'vue';
import { AUDIO_PLAYBACK_STATS_EVENT, type AudioPlaybackStatsEventDetail } from '@/composables/useGlobalAudioPlayer';
import type { AudioDetail } from '@/types/audio';

export function useAudioPlaybackStatsEvents(detailsById: Ref<Record<number, AudioDetail>>): void {
    function handleAudioPlaybackStatsEvent(event: Event): void {
        const stats = (event as CustomEvent<AudioPlaybackStatsEventDetail>).detail;
        const details = detailsById.value[stats.file_id];
        if (!details) {
            return;
        }

        detailsById.value = {
            ...detailsById.value,
            [stats.file_id]: {
                ...details,
                play_count: stats.play_count,
                skip_count: stats.skip_count,
            },
        };
    }

    onMounted(() => {
        window.addEventListener(AUDIO_PLAYBACK_STATS_EVENT, handleAudioPlaybackStatsEvent);
    });

    onUnmounted(() => {
        window.removeEventListener(AUDIO_PLAYBACK_STATS_EVENT, handleAudioPlaybackStatsEvent);
    });
}
