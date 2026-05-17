<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import {
    Ban,
    Heart,
    ListMusic,
    MoreVertical,
    Music,
    Pause,
    Play,
    Repeat,
    Shuffle,
    SkipBack,
    SkipForward,
    Smile,
    ThumbsUp,
    Volume2,
} from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';
import type { ReactionType } from '@/types/reaction';

const audioPlayer = useGlobalAudioPlayer();
const audioRef = ref<HTMLAudioElement | null>(null);
const currentTime = ref(0);
const mediaDuration = ref(0);

const currentTrack = audioPlayer.currentTrack;
const currentTrackId = audioPlayer.currentTrackId;
const isPlaying = audioPlayer.isPlaying;
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

const durationSeconds = computed(() => {
    if (mediaDuration.value > 0) {
        return mediaDuration.value;
    }

    return currentTrack.value?.durationSeconds ?? 0;
});

const durationLabel = computed(() => durationSeconds.value > 0 ? formatSeconds(durationSeconds.value) : '0:00');
const currentTimeLabel = computed(() => currentTime.value > 0 ? formatSeconds(currentTime.value) : '0:00');
const canSeek = computed(() => hasTrack.value && durationSeconds.value > 0);
const progressWidth = computed(() => {
    if (durationSeconds.value <= 0) {
        return '0%';
    }

    return `${Math.min(100, Math.max(0, (currentTime.value / durationSeconds.value) * 100))}%`;
});

const controlButtonClass = [
    'player-control-button inline-flex size-12 items-center justify-center rounded-full 2xl:size-14',
    'text-blue-slate-300 transition-colors',
    'enabled:cursor-pointer enabled:hover:bg-smart-blue-700 enabled:hover:text-white',
    'disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50',
    'focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-500',
].join(' ');

const reactionButtonClass = [
    'inline-flex items-center justify-center rounded p-1.5 transition-colors',
    'enabled:cursor-pointer disabled:cursor-not-allowed disabled:text-blue-slate-500 disabled:opacity-50',
].join(' ');

function formatSeconds(value: number): string {
    const seconds = Math.max(0, Math.floor(value));
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    return `${minutes}:${remainingSeconds.toString().padStart(2, '0')}`;
}

async function attemptPlay(): Promise<void> {
    await nextTick();

    if (!audioRef.value || !currentTrack.value) {
        return;
    }

    const playResult = audioRef.value.play();
    void (playResult as Promise<void> | undefined)?.catch(() => {});
}

function handleLoadedMetadata(): void {
    mediaDuration.value = audioRef.value?.duration && Number.isFinite(audioRef.value.duration)
        ? audioRef.value.duration
        : currentTrack.value?.durationSeconds ?? 0;
}

function handleTimeUpdate(): void {
    currentTime.value = audioRef.value?.currentTime ?? 0;
}

function handleSeek(event: Event): void {
    if (!canSeek.value || !(event.target instanceof HTMLInputElement)) {
        return;
    }

    const targetTime = Math.min(durationSeconds.value, Math.max(0, event.target.valueAsNumber));
    currentTime.value = targetTime;

    if (audioRef.value) {
        audioRef.value.currentTime = targetTime;
    }
}

function handleEnded(): void {
    audioPlayer.playNext();
}

function handlePlaybackClick(): void {
    audioPlayer.togglePlayback();
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

watch(currentTrackId, () => {
    currentTime.value = 0;
    mediaDuration.value = currentTrack.value?.durationSeconds ?? 0;

    if (isPlaying.value) {
        void attemptPlay();
    }
});

watch(isPlaying, (playing) => {
    if (playing) {
        void attemptPlay();
        return;
    }

    audioRef.value?.pause();
});
</script>

<template>
    <section
        class="shrink-0 border-t border-twilight-indigo-500 bg-prussian-blue-900 px-4 py-3 text-twilight-indigo-100 shadow-lg lg:px-0 lg:py-0"
        data-test="global-audio-player"
        aria-label="Global audio player"
    >
        <audio
            ref="audioRef"
            class="hidden"
            :src="currentTrack?.playbackUrl"
            preload="metadata"
            aria-hidden="true"
            tabindex="-1"
            @loadedmetadata="handleLoadedMetadata"
            @timeupdate="handleTimeUpdate"
            @ended="handleEnded"
        ></audio>
        <div class="grid gap-3 md:min-h-24 lg:grid-cols-[minmax(280px,1fr)_minmax(420px,2fr)_minmax(220px,1fr)] lg:items-stretch 2xl:min-h-32">
            <div class="flex h-full min-w-0 items-stretch justify-center gap-3 md:justify-start" data-test="global-audio-player-track">
                <div
                    class="hidden size-12 aspect-square shrink-0 items-center justify-center overflow-hidden bg-prussian-blue-700 ring-1 ring-twilight-indigo-500 md:flex md:h-full md:w-auto"
                    data-test="global-audio-player-cover"
                >
                    <img
                        v-if="currentTrack?.coverUrl"
                        :src="currentTrack.coverUrl"
                        alt=""
                        class="h-full w-full object-cover"
                    >
                    <Music v-else class="size-6 max-h-full max-w-full text-smart-blue-100 md:size-10 2xl:size-12" />
                </div>
                <div class="min-w-0 self-center text-center md:text-left lg:py-3" data-test="global-audio-player-details">
                    <template v-if="currentTrack">
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
                        class="mt-2 flex w-fit items-center justify-center gap-3 max-lg:mx-auto md:gap-2.5 2xl:mt-3 2xl:gap-3"
                        data-test="global-audio-player-reactions"
                    >
                        <button
                            type="button"
                            :class="[reactionButtonClass, hasFavorite ? 'bg-red-500 text-white' : 'text-white enabled:hover:text-red-400']"
                            :disabled="!hasTrack"
                            :aria-pressed="hasFavorite"
                            aria-label="Favorite"
                            @click="handleReaction('love')"
                        >
                            <Heart class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, hasLike ? 'bg-smart-blue-500 text-white' : 'text-white enabled:hover:text-smart-blue-400']"
                            :disabled="!hasTrack"
                            :aria-pressed="hasLike"
                            aria-label="Like"
                            @click="handleReaction('like')"
                        >
                            <ThumbsUp class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, isBlacklisted ? 'bg-danger-600 text-white' : 'text-white enabled:hover:text-danger-300']"
                            :disabled="!hasTrack || isBlacklisted"
                            :aria-pressed="isBlacklisted"
                            aria-label="Blacklist"
                            @click="handleBlacklist"
                        >
                            <Ban class="size-6 md:size-8" />
                        </button>
                        <button
                            type="button"
                            :class="[reactionButtonClass, hasFunny ? 'bg-yellow-500 text-white' : 'text-white enabled:hover:text-yellow-400']"
                            :disabled="!hasTrack"
                            :aria-pressed="hasFunny"
                            aria-label="Funny"
                            @click="handleReaction('funny')"
                        >
                            <Smile class="size-6 md:size-8" />
                        </button>
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
                        @input="handleSeek"
                        @change="handleSeek"
                    >
                    <span class="tabular-nums">{{ durationLabel }}</span>
                </div>

                <div class="mt-3 flex items-center justify-center gap-3 md:mt-4 md:gap-5 2xl:mt-4 2xl:gap-6" data-test="global-audio-player-controls">
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Shuffle">
                        <Shuffle class="size-6 2xl:size-7" />
                    </button>
                    <button
                        type="button"
                        :class="controlButtonClass"
                        :disabled="!audioPlayer.canPlayPrevious.value"
                        :aria-disabled="!audioPlayer.canPlayPrevious.value"
                        aria-label="Previous"
                        @click="audioPlayer.playPrevious"
                    >
                        <SkipBack class="size-7 2xl:size-8" />
                    </button>
                    <button
                        type="button"
                        class="inline-flex size-14 items-center justify-center rounded-full bg-smart-blue-600 text-white shadow-lg shadow-smart-blue-900/30 transition enabled:cursor-pointer enabled:hover:scale-105 enabled:hover:bg-smart-blue-500 disabled:cursor-not-allowed disabled:bg-smart-blue-900/60 disabled:text-blue-slate-400 disabled:opacity-60 disabled:shadow-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-smart-blue-300 2xl:size-16"
                        :disabled="!hasTrack"
                        :aria-disabled="!hasTrack"
                        :aria-label="isPlaying ? 'Pause' : 'Play'"
                        @click="handlePlaybackClick"
                    >
                        <Pause v-if="isPlaying" class="size-7 fill-current 2xl:size-8" />
                        <Play v-else class="ml-0.5 size-7 fill-current 2xl:size-8" />
                    </button>
                    <button
                        type="button"
                        :class="controlButtonClass"
                        :disabled="!audioPlayer.canPlayNext.value"
                        :aria-disabled="!audioPlayer.canPlayNext.value"
                        aria-label="Next"
                        @click="audioPlayer.playNext"
                    >
                        <SkipForward class="size-7 2xl:size-8" />
                    </button>
                    <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Repeat">
                        <Repeat class="size-6 2xl:size-7" />
                    </button>
                </div>
            </div>

            <div class="hidden min-w-0 items-center justify-end gap-2 lg:flex lg:py-3 lg:pr-4 2xl:gap-3">
                <button type="button" :class="controlButtonClass" disabled aria-disabled="true" aria-label="Queue">
                    <ListMusic class="size-4 2xl:size-6" />
                </button>
                <div class="flex w-24 items-center gap-2 2xl:w-36 2xl:gap-3">
                    <Volume2 class="size-4 shrink-0 text-blue-slate-300 2xl:size-6" />
                    <div
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-twilight-indigo-500 2xl:h-2.5"
                        role="progressbar"
                        aria-label="Volume"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="70"
                    >
                        <div class="h-full w-2/3 rounded-full bg-blue-slate-300"></div>
                    </div>
                </div>
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
