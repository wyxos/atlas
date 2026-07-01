import { computed, onBeforeUnmount, ref, watch, type ComputedRef, type Ref } from 'vue';
import { useAudioPlaybackSession, type AudioPlaybackSessionSnapshot } from '@/composables/useAudioPlaybackSession';
import { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';
import { isSpotifyAudioTrack } from '@/lib/audioPlaybackOwnership';
import type { useToast } from '@/components/ui/toast/use-toast';

type GlobalAudioPlayer = ReturnType<typeof useGlobalAudioPlayer>;
type Toast = ReturnType<typeof useToast>;

type UseGlobalAudioPlaybackOwnershipOptions = {
    activateSpotifyElement: () => void;
    audioPlayer: GlobalAudioPlayer;
    currentTime: Ref<number>;
    mediaDuration: Ref<number>;
    startCurrentPlayback: () => void;
    toast: Toast;
};

export function useGlobalAudioPlaybackOwnership(options: UseGlobalAudioPlaybackOwnershipOptions) {
    const playbackSession = useAudioPlaybackSession();
    const claimRequestInFlight = ref(false);
    const isObservingRemotePlayback = computed(() => playbackSession.shouldShowOwnershipUi.value);
    const canUsePlaybackControls = computed(() => !isObservingRemotePlayback.value);
    const currentTrack = computed(() => isObservingRemotePlayback.value
        ? playbackSession.session.value.current_track
        : options.audioPlayer.currentTrack.value);
    const currentTrackId = computed(() => currentTrack.value?.id ?? null);
    const isPlaying = computed(() => isObservingRemotePlayback.value
        ? playbackSession.session.value.state === 'playing'
        : options.audioPlayer.isPlaying.value);
    const durationSeconds = computed(() => {
        if (options.mediaDuration.value > 0 && !isObservingRemotePlayback.value) {
            return options.mediaDuration.value;
        }

        return isObservingRemotePlayback.value
            ? playbackSession.session.value.duration_seconds ?? currentTrack.value?.durationSeconds ?? 0
            : currentTrack.value?.durationSeconds ?? 0;
    });

    function playbackSnapshot(): AudioPlaybackSessionSnapshot {
        const track = currentTrack.value;

        return {
            state: !track ? 'idle' : isPlaying.value ? 'playing' : 'paused',
            source: track ? (isSpotifyAudioTrack(track) ? 'spotify' : 'local') : null,
            current_track: track,
            queue_label: isObservingRemotePlayback.value
                ? playbackSession.session.value.queue_label
                : options.audioPlayer.queueLabel.value,
            position_seconds: options.currentTime.value,
            duration_seconds: durationSeconds.value > 0 ? durationSeconds.value : null,
            spotify_device_id: playbackSession.session.value.spotify_device_id,
        };
    }

    async function claimPlaybackOnThisDevice(): Promise<void> {
        const track = currentTrack.value;
        if (!track || claimRequestInFlight.value) {
            return;
        }

        if (isSpotifyAudioTrack(track)) {
            options.activateSpotifyElement();
        }

        claimRequestInFlight.value = true;

        try {
            if (isObservingRemotePlayback.value) {
                options.audioPlayer.queueAndPlay([track], track.id, {
                    queueLabel: playbackSession.session.value.queue_label,
                });
                options.audioPlayer.updatePlaybackPosition(playbackSession.remotePositionSeconds.value);
                options.currentTime.value = playbackSession.remotePositionSeconds.value;
            }

            await playbackSession.claimOwnership(playbackSnapshot());
            options.startCurrentPlayback();
        } catch (error) {
            options.audioPlayer.pause();
            options.toast.error('Unable to move playback to this device.', {
                id: 'audio-playback-claim-failed',
                description: error instanceof Error ? error.message : 'Try again after the current device updates.',
                duration: 8000,
            });
        } finally {
            claimRequestInFlight.value = false;
        }
    }

    function handlePlaybackClick(): void {
        if (isObservingRemotePlayback.value) {
            void claimPlaybackOnThisDevice();
            return;
        }

        options.audioPlayer.togglePlayback();
        void updateOwnerPlaybackSession();
    }

    async function updateOwnerPlaybackSession(): Promise<void> {
        if (!playbackSession.isLeaseOwner.value) {
            return;
        }

        await playbackSession.update(playbackSnapshot());
    }

    async function claimOwnershipForLocalPlayback(): Promise<void> {
        const localTrack = options.audioPlayer.currentTrack.value;
        const isSessionAvailable = playbackSession.refreshAvailability();

        if (
            claimRequestInFlight.value
            || !isSessionAvailable
            || playbackSession.isLeaseOwner.value
            || !options.audioPlayer.isPlaying.value
            || !localTrack
        ) {
            return;
        }

        claimRequestInFlight.value = true;

        try {
            await playbackSession.claimOwnership(playbackSnapshot());
            options.startCurrentPlayback();
        } catch (error) {
            options.audioPlayer.pause();
            console.error('Failed to claim audio playback ownership:', error);
        } finally {
            claimRequestInFlight.value = false;
        }
    }

    playbackSession.setSnapshotProvider(playbackSnapshot);

    watch(options.audioPlayer.playbackPositionSeconds, (positionSeconds) => {
        if (!isObservingRemotePlayback.value) {
            options.currentTime.value = positionSeconds;
        }
    }, { flush: 'sync' });

    watch(playbackSession.remotePositionSeconds, (positionSeconds) => {
        if (isObservingRemotePlayback.value) {
            options.currentTime.value = positionSeconds;
        }
    }, { flush: 'sync' });

    watch(isObservingRemotePlayback, (isObserving) => {
        if (isObserving) {
            options.audioPlayer.pause();
            options.audioPlayer.closeQueueSheet();
            options.currentTime.value = playbackSession.remotePositionSeconds.value;
            options.mediaDuration.value = playbackSession.session.value.duration_seconds
                ?? playbackSession.session.value.current_track?.durationSeconds
                ?? 0;
        } else {
            options.currentTime.value = options.audioPlayer.playbackPositionSeconds.value;
            options.mediaDuration.value = options.audioPlayer.currentTrack.value?.durationSeconds ?? 0;
        }

        options.startCurrentPlayback();
    }, { flush: 'sync' });

    watch(currentTrackId, () => {
        if (isObservingRemotePlayback.value) {
            options.mediaDuration.value = playbackSession.session.value.duration_seconds
                ?? playbackSession.session.value.current_track?.durationSeconds
                ?? 0;
            options.currentTime.value = playbackSession.remotePositionSeconds.value;
        }

        options.startCurrentPlayback();
    }, { immediate: true, flush: 'sync' });

    watch(isPlaying, () => {
        options.startCurrentPlayback();
    });

    watch(options.mediaDuration, () => {
        void updateOwnerPlaybackSession();
    }, { flush: 'post' });

    watch([
        options.audioPlayer.currentTrackId,
        options.audioPlayer.isPlaying,
        playbackSession.isAvailable,
        playbackSession.isLeaseOwner,
        playbackSession.hasOtherOwner,
    ], () => {
        void claimOwnershipForLocalPlayback();
        void updateOwnerPlaybackSession();
    }, { immediate: true, flush: 'post' });

    watch(playbackSession.canOutputAudio, () => {
        options.startCurrentPlayback();
    }, { flush: 'sync' });

    onBeforeUnmount(() => {
        void playbackSession.release(playbackSnapshot());
    });

    return {
        canUsePlaybackControls,
        claimPlaybackOnThisDevice,
        claimRequestInFlight,
        currentTrack,
        currentTrackId,
        durationSeconds: durationSeconds as ComputedRef<number>,
        handlePlaybackClick,
        isObservingRemotePlayback,
        isPlaying,
        playbackSession,
        updateOwnerPlaybackSession,
    };
}
