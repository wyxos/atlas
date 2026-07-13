<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    ListMusic,
    MoreVertical,
    Music,
    Pause,
    Play,
    Repeat,
    Repeat1,
    Shuffle,
    SkipBack,
    SkipForward,
} from 'lucide-vue-next';
import AudioQueueSheet from './AudioQueueSheet.vue';
import GlobalAudioPlayerReactions from './GlobalAudioPlayerReactions.vue';
import AudioVolumeControl from './AudioVolumeControl.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useToast } from '@/components/ui/toast/use-toast';
import { useAudioMediaSession } from '@/composables/useAudioMediaSession';
import { useAudioPlaybackEngines } from '@/composables/useAudioPlaybackEngines';
import { useAudioQueueDetails } from '@/composables/useAudioQueueDetails';
import { useAudioPlaybackStatsRecorder } from '@/composables/useAudioPlaybackStatsRecorder';
import { useGlobalAudioPlaybackOwnership } from '@/composables/useGlobalAudioPlaybackOwnership';
import { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';
import { useSpotifyPlaybackNotifications } from '@/composables/useSpotifyPlaybackNotifications';
import type { ReactionType } from '@/types/reaction';

const audioPlayer = useGlobalAudioPlayer();
const audioRef = ref<HTMLAudioElement | null>(null);
const currentTime = ref(audioPlayer.playbackPositionSeconds.value);
const mediaDuration = ref(0);
const playbackVolume = ref(0.7);
const mobileActionsExpanded = ref(false);
const touchStartX = ref<number | null>(null);
const touchStartY = ref<number | null>(null);
const isQueueSheetOpen = audioPlayer.isQueueSheetOpen;
const toast = useToast();
const { handleQueueVisibleItemsChange } = useAudioQueueDetails(audioPlayer);
const { handleTrackNaturallyEnded } = useAudioPlaybackStatsRecorder(audioPlayer);
const { notifySpotifyAuthenticationError, notifySpotifyPlaybackError, notifySpotifyRecoveryStateChange } = useSpotifyPlaybackNotifications();

const MOBILE_ACTIONS_SWIPE_THRESHOLD = 28;
let startCurrentPlayback: () => Promise<void> = async () => {};
let activateSpotifyElement: () => void = () => {};

const {
    canUsePlaybackControls,
    claimPlaybackOnThisDevice,
    claimRequestInFlight,
    currentTrack,
    currentTrackId,
    durationSeconds,
    handlePlaybackClick,
    isObservingRemotePlayback,
    isPlaying,
    playbackSession,
    updateOwnerPlaybackSession,
} = useGlobalAudioPlaybackOwnership({
    activateSpotifyElement: () => activateSpotifyElement(),
    audioPlayer,
    currentTime,
    mediaDuration,
    startCurrentPlayback: () => {
        void startCurrentPlayback();
    },
    toast,
});

const hasTrack = computed(() => currentTrack.value !== null);
const hasFavorite = computed(() => currentTrack.value?.reaction?.type === 'love');
const hasLike = computed(() => currentTrack.value?.reaction?.type === 'like');
const hasFunny = computed(() => currentTrack.value?.reaction?.type === 'funny');
const isBlacklisted = computed(() => Boolean(currentTrack.value?.blacklistedAt));

const trackSubtitle = computed(() => {
    if (!currentTrack.value) {
        return '';
    }

    return currentTrack.value.artists || currentTrack.value.album || 'Unknown artist';
});

const durationLabel = computed(() => durationSeconds.value > 0 ? formatSeconds(durationSeconds.value) : '0:00');
const currentTimeLabel = computed(() => currentTime.value > 0 ? formatSeconds(currentTime.value) : '0:00');
const canSeek = computed(() => canUsePlaybackControls.value && hasTrack.value && durationSeconds.value > 0);
const progressWidth = computed(() => {
    if (durationSeconds.value <= 0) {
        return '0%';
    }

    return `${Math.min(100, Math.max(0, (currentTime.value / durationSeconds.value) * 100))}%`;
});
const repeatButtonLabel = computed(() => {
    if (audioPlayer.repeatMode.value === 'one') {
        return 'Repeat one';
    }

    if (audioPlayer.repeatMode.value === 'all') {
        return 'Repeat all';
    }

    return 'Repeat off';
});

const playbackEngines = useAudioPlaybackEngines(audioPlayer, audioRef, currentTime, mediaDuration, durationSeconds, {
    isPlaybackOwner: playbackSession.canOutputAudio,
    onSpotifyAuthenticationError: notifySpotifyAuthenticationError,
    onSpotifyPlaybackError: notifySpotifyPlaybackError,
    onSpotifyRecoveryStateChange: notifySpotifyRecoveryStateChange,
    onTrackEnded: handleTrackNaturallyEnded,
    volume: playbackVolume,
});
activateSpotifyElement = playbackEngines.activateSpotifyElement;
startCurrentPlayback = playbackEngines.startCurrentPlayback;

const {
    handleEnded,
    handleLoadedMetadata,
    handleSeek,
    handleTimeUpdate,
    nativeAudioSource,
    setSpotifyVolume,
    teardown,
} = playbackEngines;
void startCurrentPlayback();

useAudioMediaSession({
    currentTime,
    currentTrack,
    durationSeconds,
    isPlaying,
    onNext: () => {
        if (canUsePlaybackControls.value) {
            audioPlayer.playNext();
        }
    },
    onPause: () => {
        if (canUsePlaybackControls.value) {
            audioPlayer.pause();
        }
    },
    onPlay: () => {
        if (canUsePlaybackControls.value) {
            audioPlayer.resume();
        }
    },
    onPrevious: () => {
        if (canUsePlaybackControls.value) {
            audioPlayer.playPrevious();
        }
    },
    trackSubtitle,
});

const controlButtonClass = 'player-control-button inline-flex size-12 items-center justify-center rounded-full text-blue-slate-300 transition-colors enabled:cursor-pointer enabled:hover:bg-smart-blue-700 enabled:hover:text-white disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-500 2xl:size-14';
const reactionButtonClass = 'inline-flex items-center justify-center rounded p-1.5 transition-colors enabled:cursor-pointer disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50';

function formatSeconds(value: number): string {
    const seconds = Math.max(0, Math.floor(value));
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

function handleVolumeChange(volume: number): void {
    playbackVolume.value = volume;
    setSpotifyVolume(volume);
}

function handlePlayerTouchStart(event: TouchEvent): void {
    if (window.innerWidth >= 768 || event.touches.length !== 1) {
        touchStartX.value = null;
        touchStartY.value = null;
        return;
    }

    const touch = event.touches.item?.(0) ?? event.touches[0] ?? null;
    touchStartX.value = touch?.clientX ?? null;
    touchStartY.value = touch?.clientY ?? null;
}

function handlePlayerTouchEnd(event: TouchEvent): void {
    if (window.innerWidth >= 768 || touchStartY.value === null || touchStartX.value === null) {
        return;
    }

    const touch = event.changedTouches.item?.(0) ?? event.changedTouches[0] ?? null;
    if (!touch) {
        return;
    }

    const deltaX = touch.clientX - touchStartX.value;
    const deltaY = touch.clientY - touchStartY.value;
    touchStartX.value = null;
    touchStartY.value = null;

    if (Math.abs(deltaY) < MOBILE_ACTIONS_SWIPE_THRESHOLD || Math.abs(deltaY) < Math.abs(deltaX)) {
        return;
    }

    mobileActionsExpanded.value = deltaY < 0;
}

async function handleReaction(type: ReactionType): Promise<void> {
    if (!currentTrack.value) {
        return;
    }

    const { data } = await window.axios.post<{ reaction: { type: ReactionType } }>(`/api/files/${currentTrack.value.id}/reaction`, {
        type,
    });
    audioPlayer.updateCurrentTrack({ reaction: data.reaction });
}

async function handleBlacklist(): Promise<void> {
    if (!currentTrack.value || currentTrack.value.blacklistedAt) {
        return;
    }

    const { data } = await window.axios.post<{
        results?: Array<{
            id: number;
            blacklisted_at: string | null;
            previewed_count?: number;
        }>;
    }>('/api/files/blacklist/batch', {
        file_ids: [currentTrack.value.id],
    });
    const result = data.results?.find((item) => item.id === currentTrack.value?.id);
    if (!result) {
        return;
    }

    audioPlayer.updateCurrentTrack({
        blacklistedAt: result.blacklisted_at,
        previewedCount: result.previewed_count ?? currentTrack.value.previewedCount,
    });
}

function handleSeekInput(event: Event): void {
    if (!canUsePlaybackControls.value) {
        return;
    }

    handleSeek(event);
    void updateOwnerPlaybackSession();
}

function handleEndedInput(): void {
    if (!canUsePlaybackControls.value) {
        return;
    }

    handleEnded();
    void updateOwnerPlaybackSession();
}

watch(audioPlayer.hasQueue, (hasQueue) => {
    if (!hasQueue) {
        audioPlayer.closeQueueSheet();
    }
});

onBeforeUnmount(() => {
    teardown();
});

</script>

<template>
    <div
        v-if="isQueueSheetOpen"
        class="fixed inset-0 z-[70] cursor-default"
        data-test="audio-queue-backdrop"
        aria-hidden="true"
        @click="audioPlayer.closeQueueSheet"
    />
    <Transition
        enter-active-class="transition duration-500 ease-in-out"
        enter-from-class="translate-x-full opacity-0"
        enter-to-class="translate-x-0 opacity-100"
        leave-active-class="transition duration-300 ease-in-out"
        leave-from-class="translate-x-0 opacity-100"
        leave-to-class="translate-x-full opacity-0"
    >
        <AudioQueueSheet
            v-if="isQueueSheetOpen"
            :tracks="audioPlayer.queue.value"
            :current-track-id="currentTrackId"
            :is-playing="isPlaying"
            :queue-label="audioPlayer.queueLabel.value"
            @close="audioPlayer.closeQueueSheet"
            @play="audioPlayer.playQueueTrack"
            @visible-items-change="handleQueueVisibleItemsChange"
        />
    </Transition>
    <section
        class="relative z-[75] shrink-0 border-t border-twilight-indigo-500 bg-prussian-blue-900 px-4 py-3 text-twilight-indigo-100 shadow-lg lg:px-0 lg:py-0"
        data-test="global-audio-player"
        :data-mobile-actions-expanded="mobileActionsExpanded ? 'true' : 'false'"
        aria-label="Global audio player"
        @touchstart.passive="handlePlayerTouchStart"
        @touchend.passive="handlePlayerTouchEnd"
    >
        <div
            v-if="isQueueSheetOpen"
            class="absolute inset-0 z-10 cursor-default"
            data-test="audio-queue-player-dismiss"
            aria-hidden="true"
            @click="audioPlayer.closeQueueSheet"
        />
        <audio
            ref="audioRef"
            class="hidden"
            :src="nativeAudioSource"
            preload="metadata"
            aria-hidden="true"
            tabindex="-1"
            @loadedmetadata="handleLoadedMetadata"
            @timeupdate="handleTimeUpdate"
            @ended="handleEndedInput"
        ></audio>
        <div class="grid gap-3 md:min-h-24 lg:grid-cols-[minmax(280px,1fr)_minmax(420px,2fr)_minmax(220px,1fr)] lg:items-stretch 2xl:min-h-32">
            <div class="flex h-full min-w-0 items-stretch justify-center gap-3 md:justify-start" data-test="global-audio-player-track">
                <button
                    type="button"
                    class="hidden size-12 shrink-0 items-center justify-center overflow-hidden bg-prussian-blue-700 ring-1 ring-twilight-indigo-500 transition enabled:cursor-pointer enabled:hover:ring-smart-blue-300 disabled:cursor-default md:flex md:size-24 2xl:size-32"
                    data-test="global-audio-player-cover"
                    :disabled="!hasTrack || !canUsePlaybackControls"
                    aria-label="Focus current track in playlist"
                    @click="audioPlayer.requestCurrentTrackFocus"
                >
                    <img
                        v-if="currentTrack?.coverUrl"
                        :src="currentTrack.coverUrl"
                        alt=""
                        class="h-full w-full object-cover"
                    >
                    <Music v-else class="size-6 max-h-full max-w-full text-smart-blue-100 md:size-10 2xl:size-12" />
                </button>
                <div class="min-w-0 self-center text-center md:text-left lg:py-3" data-test="global-audio-player-details">
                    <template v-if="currentTrack && currentTrack.artists !== 'Loading metadata...'">
                        <p
                            class="truncate text-sm font-semibold text-regal-navy-100"
                            data-test="global-audio-player-title"
                        >
                            {{ currentTrack.title }}
                        </p>
                        <p
                            class="mt-1 truncate text-xs text-blue-slate-300"
                            data-test="global-audio-player-subtitle"
                        >
                            {{ trackSubtitle }}
                        </p>
                    </template>
                    <template v-else>
                        <Skeleton
                            class="h-4 w-40 bg-prussian-blue-500/60 max-md:mx-auto"
                            data-test="global-audio-player-title"
                            aria-hidden="true"
                        />
                        <Skeleton
                            class="mt-2 h-3 w-28 bg-prussian-blue-500/60 max-md:mx-auto"
                            data-test="global-audio-player-subtitle"
                            aria-hidden="true"
                        />
                    </template>
                    <div
                        :class="[
                            'mt-2 w-fit items-center justify-center gap-3 max-lg:mx-auto md:flex md:gap-2.5 2xl:mt-3 2xl:gap-3',
                            mobileActionsExpanded ? 'flex' : 'hidden',
                        ]"
                        data-test="global-audio-player-reactions"
                    >
                        <GlobalAudioPlayerReactions
                            :can-use-playback-controls="canUsePlaybackControls"
                            :has-favorite="hasFavorite"
                            :has-funny="hasFunny"
                            :has-like="hasLike"
                            :has-track="hasTrack"
                            :is-blacklisted="isBlacklisted"
                            :reaction-button-class="reactionButtonClass"
                            @blacklist="handleBlacklist"
                            @reaction="handleReaction"
                        />
                    </div>
                </div>
            </div>

            <div class="min-w-0 self-center md:max-lg:mt-3 lg:py-3" data-test="global-audio-player-playback">
                <div class="grid grid-cols-[2.5rem_minmax(0,1fr)_2.5rem] items-center gap-2 text-xs text-blue-slate-300 2xl:grid-cols-[3.75rem_minmax(0,1fr)_3.75rem] 2xl:text-sm">
                    <span class="text-right tabular-nums">{{ currentTimeLabel }}</span>
                    <input
                        type="range"
                        class="audio-seek-slider h-2 w-full rounded-full disabled:cursor-not-allowed disabled:opacity-50 2xl:h-3"
                        aria-label="Playback progress"
                        min="0"
                        step="0.1"
                        :max="durationSeconds"
                        :value="currentTime"
                        :disabled="!canSeek"
                        :aria-valuemax="Math.round(durationSeconds)"
                        :aria-valuenow="Math.round(currentTime)"
                        :style="{ '--seek-progress': progressWidth }"
                        @input="handleSeekInput"
                        @change="handleSeekInput"
                    >
                    <span class="tabular-nums">{{ durationLabel }}</span>
                </div>

                <div class="mt-3 flex items-center justify-center gap-3 md:mt-4 md:gap-5 2xl:mt-4 2xl:gap-6" data-test="global-audio-player-controls">
                    <button
                        type="button"
                        :class="[controlButtonClass, audioPlayer.isShuffleEnabled.value ? 'bg-smart-blue-800 text-smart-blue-100' : '']"
                        :disabled="!canUsePlaybackControls || audioPlayer.queueLength.value === 0"
                        :aria-disabled="!canUsePlaybackControls || audioPlayer.queueLength.value === 0"
                        :aria-pressed="audioPlayer.isShuffleEnabled.value"
                        aria-label="Shuffle queue"
                        @click="audioPlayer.shuffleQueue"
                    >
                        <Shuffle class="size-6 2xl:size-7" />
                    </button>
                    <button
                        type="button"
                        :class="controlButtonClass"
                        :disabled="!canUsePlaybackControls || !audioPlayer.canPlayPrevious.value"
                        :aria-disabled="!canUsePlaybackControls || !audioPlayer.canPlayPrevious.value"
                        aria-label="Previous"
                        @click="audioPlayer.playPrevious"
                    >
                        <SkipBack class="size-7 2xl:size-8" />
                    </button>
                    <button v-if="isObservingRemotePlayback" type="button" class="inline-flex h-12 items-center justify-center gap-2 rounded bg-smart-blue-600 px-4 text-sm font-semibold text-white shadow-lg shadow-smart-blue-900/30 transition enabled:cursor-pointer enabled:hover:bg-smart-blue-500 disabled:cursor-not-allowed disabled:bg-smart-blue-900/60 disabled:text-blue-slate-400 disabled:opacity-60 disabled:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300 2xl:h-14 2xl:px-5" :disabled="!hasTrack || claimRequestInFlight" :aria-disabled="!hasTrack || claimRequestInFlight" data-test="audio-ownership-claim" aria-label="Play on this device" @click="claimPlaybackOnThisDevice">
                        <Play class="size-5 fill-current 2xl:size-6" />
                        <span>Play on this device</span>
                    </button>
                    <button v-else type="button" class="inline-flex size-14 items-center justify-center rounded-full bg-smart-blue-600 text-white shadow-lg shadow-smart-blue-900/30 transition enabled:cursor-pointer enabled:hover:scale-105 enabled:hover:bg-smart-blue-500 disabled:cursor-not-allowed disabled:bg-smart-blue-900/60 disabled:text-blue-slate-400 disabled:opacity-60 disabled:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300 2xl:size-16" :disabled="!hasTrack" :aria-disabled="!hasTrack" :aria-label="isPlaying ? 'Pause' : 'Play'" @click="handlePlaybackClick">
                        <Pause v-if="isPlaying" class="size-7 fill-current 2xl:size-8" />
                        <Play v-else class="ml-0.5 size-7 fill-current 2xl:size-8" />
                    </button>
                    <button
                        type="button"
                        :class="controlButtonClass"
                        :disabled="!canUsePlaybackControls || !audioPlayer.canPlayNext.value"
                        :aria-disabled="!canUsePlaybackControls || !audioPlayer.canPlayNext.value"
                        aria-label="Next"
                        @click="audioPlayer.playNext"
                    >
                        <SkipForward class="size-7 2xl:size-8" />
                    </button>
                    <button
                        type="button"
                        :class="[controlButtonClass, audioPlayer.repeatMode.value !== 'none' ? 'bg-smart-blue-800 text-smart-blue-100' : '']"
                        :disabled="!canUsePlaybackControls || !hasTrack"
                        :aria-disabled="!canUsePlaybackControls || !hasTrack"
                        :aria-label="repeatButtonLabel"
                        :aria-pressed="audioPlayer.repeatMode.value !== 'none'"
                        @click="audioPlayer.cycleRepeatMode"
                    >
                        <Repeat1 v-if="audioPlayer.repeatMode.value === 'one'" class="size-6 2xl:size-7" />
                        <Repeat v-else class="size-6 2xl:size-7" />
                    </button>
                </div>
            </div>

            <div class="hidden min-w-0 items-center justify-end gap-2 lg:flex lg:py-3 lg:pr-4 2xl:gap-3">
                <button
                    type="button"
                    :class="[controlButtonClass, isQueueSheetOpen ? 'bg-smart-blue-800 text-smart-blue-100' : '']"
                    :disabled="!canUsePlaybackControls || !audioPlayer.hasQueue.value"
                    :aria-disabled="!canUsePlaybackControls || !audioPlayer.hasQueue.value"
                    :aria-expanded="isQueueSheetOpen"
                    aria-controls="audio-queue-sheet"
                    aria-label="Queue"
                    @click="audioPlayer.toggleQueueSheet"
                >
                    <ListMusic class="size-4 2xl:size-6" />
                </button>
                <AudioVolumeControl :audio-ref="audioRef" @volume-change="handleVolumeChange" />
                <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="More options">
                    <MoreVertical class="size-4 2xl:size-6" />
                </button>
            </div>
        </div>
    </section>
</template>

<style scoped>
.audio-seek-slider {
    --seek-progress: 0%;
    appearance: none;
    background: linear-gradient(
        to right,
        var(--color-smart-blue-100) 0 var(--seek-progress),
        var(--color-twilight-indigo-500) var(--seek-progress) 100%
    );
    cursor: pointer;
}

.audio-seek-slider::-webkit-slider-thumb {
    width: 14px;
    height: 14px;
    appearance: none;
    border: 0;
    border-radius: 9999px;
    background: var(--color-regal-navy-100);
    box-shadow: 0 0 0 4px rgb(123 190 255 / 18%);
    opacity: 0;
    transition: opacity 120ms ease;
}

.audio-seek-slider:hover::-webkit-slider-thumb,
.audio-seek-slider:focus-visible::-webkit-slider-thumb {
    opacity: 1;
}

.audio-seek-slider::-moz-range-track {
    height: 100%;
    border: 0;
    border-radius: 9999px;
    background: transparent;
}

.audio-seek-slider::-moz-range-thumb {
    width: 14px;
    height: 14px;
    border: 0;
    border-radius: 9999px;
    background: var(--color-regal-navy-100);
    box-shadow: 0 0 0 4px rgb(123 190 255 / 18%);
    opacity: 0;
    transition: opacity 120ms ease;
}

.audio-seek-slider:hover::-moz-range-thumb,
.audio-seek-slider:focus-visible::-moz-range-thumb {
    opacity: 1;
}
</style>
