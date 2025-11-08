<script setup lang="ts">
import AudioQueuePanel from '@/components/audio/AudioQueuePanel.vue';
import FileReactions from '@/components/audio/FileReactions.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { useAudioPlayer } from '@/stores/audio';
import { ChevronDown, ChevronUp, Menu, Pause, Play, Repeat, Repeat1, Shuffle, SkipBack, SkipForward, Volume2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const {
    currentTrack,
    isPlaying,
    currentTime,
    duration,
    volume,
    isShuffled,
    repeatMode,
    isActive,
    togglePlay,
    previous,
    next,
    seekTo,
    setVolume,
    toggleShuffle,
    toggleRepeat,
} = useAudioPlayer();

const isQueueOpen = ref(false);
const isMinimized = ref(false);

// Format time helper
function formatTime(seconds: number): string {
    if (!seconds || isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs < 10 ? '0' : ''}${secs}`;
}

// Cover image
const coverImage = computed(() => {
    if (!currentTrack.value) return null;
    const track = currentTrack.value as any;

    // Check album covers first
    if (track.albums && track.albums.length > 0) {
        for (const album of track.albums) {
            if (album.covers && album.covers.length > 0) {
                return album.covers[0].url || album.covers[0].path;
            }
        }
    }

    // Fall back to file covers
    if (track.covers && track.covers.length > 0) {
        return track.covers[0].url || track.covers[0].path;
    }

    return null;
});

// Artist name
const artistName = computed(() => {
    if (!currentTrack.value) return 'Unknown Artist';
    const track = currentTrack.value as any;
    const artists = track.artists;
    return artists && artists.length ? artists[0]?.name || 'Unknown Artist' : 'Unknown Artist';
});

// Title
const title = computed(() => {
    if (!currentTrack.value) return 'No track';
    const track = currentTrack.value as any;
    return track.metadata?.payload?.title || track.title || 'Untitled';
});

// Track previous progress to detect forward vs backward movement
const previousProgress = ref(0);
const shouldAnimateProgress = ref(true);

// Progress percentage
const progressPercent = computed(() => {
    if (!duration.value || duration.value === 0) return 0;
    return (currentTime.value / duration.value) * 100;
});

// Watch progress to determine if we should animate (only when moving forward)
watch(
    progressPercent,
    (newVal, oldVal) => {
        // Only animate if the progress is increasing (forward movement)
        // This prevents the "going back down" animation when resetting to 0
        shouldAnimateProgress.value = newVal >= oldVal;
        previousProgress.value = newVal;
    },
    { immediate: true },
);

// Handle seek
function handleSeek(event: MouseEvent): void {
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const percent = x / rect.width;
    const newTime = percent * duration.value;
    seekTo(newTime);
}

// Handle volume seek
function handleVolumeSeek(event: MouseEvent): void {
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const percent = Math.max(0, Math.min(1, x / rect.width));
    setVolume(percent);
}
</script>

<template>
    <div class="sticky bottom-0 left-0 w-full border-t border-border bg-card px-4 py-2 md:p-4">
        <!-- Desktop -->
        <div class="hidden items-center gap-4 md:flex">
            <!-- Minimized -->
            <template v-if="isMinimized && isActive">
                <div class="flex flex-1 items-center gap-4">
                    <!-- Small cover -->
                    <div class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                        <Skeleton v-if="!currentTrack" class="h-full w-full" />
                        <img v-else-if="coverImage" :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                        <div v-else class="flex h-full w-full items-center justify-center rounded bg-muted text-muted-foreground">
                            <span class="text-xs">♪</span>
                        </div>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-1 items-center gap-4">
                        <div class="flex min-w-0 flex-col">
                            <Skeleton v-if="!currentTrack" class="h-3 w-24" />
                            <div v-else class="font-medium text-foreground">
                                <span class="truncate text-sm font-semibold" data-test="audio-player-title">{{ title }}</span>
                                <span class="block truncate text-xs text-muted-foreground" data-test="audio-player-artist">{{ artistName }}</span>
                            </div>
                        </div>

                        <!-- Basic playback controls -->
                        <div class="flex items-center gap-2">
                            <button class="button circular small empty" title="Previous" @click="previous">
                                <SkipBack :size="16" />
                                <span class="sr-only">Previous</span>
                            </button>
                            <button class="button circular empty" title="Play/Pause" @click="togglePlay">
                                <Play v-if="!isPlaying" :size="20" />
                                <Pause v-else :size="20" />
                            </button>
                            <button class="button circular small empty" title="Next" @click="next">
                                <SkipForward :size="16" />
                                <span class="sr-only">Next</span>
                            </button>
                        </div>

                        <!-- Compact progress bar -->
                        <div class="mx-4 min-w-0 flex-1">
                            <div
                                class="h-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80"
                                data-test="audio-player-progress"
                                @click="handleSeek"
                            >
                                <div
                                    class="h-full rounded-full bg-primary"
                                    :class="{ 'transition-all': shouldAnimateProgress }"
                                    :style="{ width: `${progressPercent}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Full View -->
            <template v-if="isActive && !isMinimized">
                <div class="flex w-100 items-center gap-4">
                    <div
                        class="relative flex h-18 w-18 shrink-0 items-center justify-center overflow-hidden bg-muted transition-all duration-300 md:h-32 md:w-32"
                    >
                        <Skeleton v-if="!currentTrack" class="h-full w-full" />
                        <img v-else-if="coverImage" :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                        <div v-else class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                            <span class="text-xs">No Cover</span>
                        </div>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 truncate">
                        <div class="mb-2 flex flex-col gap-1 font-medium text-foreground">
                            <Skeleton v-if="!currentTrack" class="h-3 w-24" />
                            <span v-else class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">{{
                                artistName
                            }}</span>
                            <Skeleton v-if="!currentTrack" class="mt-1 h-4 w-32" />
                            <span v-else class="truncate font-semibold text-foreground" data-test="audio-player-title">{{ title }}</span>
                        </div>

                        <!-- Reactions -->
                        <div class="flex flex-1 items-center">
                            <FileReactions v-if="currentTrack" :file="currentTrack" :size="20" />
                            <div v-else class="flex items-center gap-2">
                                <Skeleton class="h-5 w-5 rounded-md" />
                                <Skeleton class="h-5 w-5 rounded-md" />
                                <Skeleton class="h-5 w-5 rounded-md" />
                                <Skeleton class="h-5 w-5 rounded-md" />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex-1">
                    <div class="mb-2">
                        <div class="mb-2 flex items-center gap-4 text-xs text-muted-foreground">
                            <Skeleton v-if="!currentTrack" class="h-3 w-10" />
                            <span v-else>{{ formatTime(currentTime) }}</span>
                            <div
                                class="h-2 flex-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80"
                                data-test="audio-player-progress"
                                @click="handleSeek"
                            >
                                <div
                                    class="h-full rounded-full bg-primary"
                                    :class="{ 'transition-all': shouldAnimateProgress }"
                                    :style="{ width: `${progressPercent}%` }"
                                ></div>
                            </div>
                            <Skeleton v-if="!currentTrack" class="h-3 w-10" />
                            <span v-else>{{ formatTime(duration) }}</span>
                        </div>

                        <div class="mt-2 flex items-center justify-center gap-4">
                            <div class="flex items-center gap-4">
                                <button
                                    class="button circular small empty"
                                    :class="{ 'bg-primary text-primary-foreground': isShuffled }"
                                    title="Shuffle"
                                    @click="toggleShuffle"
                                >
                                    <Shuffle :size="16" />
                                </button>
                                <button class="button circular small empty" title="Previous" @click="previous">
                                    <SkipBack :size="20" />
                                    <span class="sr-only">Previous</span>
                                </button>
                                <button class="button circular empty" title="Play/Pause" @click="togglePlay">
                                    <Play v-if="!isPlaying" :size="24" />
                                    <Pause v-else :size="24" />
                                </button>
                                <button class="button circular small empty" title="Next" @click="next">
                                    <SkipForward :size="20" />
                                    <span class="sr-only">Next</span>
                                </button>
                                <button
                                    class="button circular small empty"
                                    :class="{ 'bg-primary text-primary-foreground': repeatMode !== 'off' }"
                                    :title="repeatMode === 'off' ? 'Repeat Off' : repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                                    @click="toggleRepeat"
                                >
                                    <Repeat1 v-if="repeatMode === 'one'" :size="16" />
                                    <Repeat v-else :size="16" />
                                </button>
                                <!-- Volume -->
                                <div class="group ml-4 hidden items-center gap-2 md:flex">
                                    <Volume2 :size="16" class="text-muted-foreground group-hover:text-primary-foreground" />
                                    <div class="relative w-28">
                                        <div
                                            class="h-2 w-full cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80"
                                            @click="handleVolumeSeek"
                                        >
                                            <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${volume * 100}%` }"></div>
                                        </div>
                                        <input
                                            aria-label="Volume"
                                            class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                            max="1"
                                            min="0"
                                            step="0.01"
                                            type="range"
                                            :value="volume"
                                            @input="(e) => setVolume(parseFloat((e.target as HTMLInputElement).value))"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex w-100 justify-end gap-2">
                <button class="button circular small empty" :title="isMinimized ? 'Expand Player' : 'Minimize Player'" @click="isMinimized = !isMinimized">
                    <ChevronDown v-if="isMinimized" :size="16" />
                    <ChevronUp v-else :size="16" />
                </button>
                <button class="button circular small empty" title="Show Queue" @click="isQueueOpen = !isQueueOpen">
                    <Menu :size="16" />
                </button>
            </div>
        </div>

        <!-- Queue Panel -->
        <AudioQueuePanel :is-open="isQueueOpen" @close="isQueueOpen = false" />

        <!-- Mobile -->
        <template v-if="isActive">
            <div class="md:hidden">
                <!-- Mobile Minimized -->
                <template v-if="isMinimized">
                    <div class="flex items-center gap-3">
                        <div class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                            <Skeleton v-if="!currentTrack" class="h-full w-full rounded" />
                            <img v-else-if="coverImage" :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                            <div v-else class="flex h-full w-full items-center justify-center rounded bg-muted text-muted-foreground">
                                <span class="text-xs">♪</span>
                            </div>
                        </div>
                        <div class="flex flex-1 items-center gap-3">
                            <div class="flex min-w-0 flex-1 flex-col">
                                <Skeleton v-if="!currentTrack" class="h-3 w-24" />
                                <span v-else class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">{{
                                    artistName
                                }}</span>
                                <Skeleton v-if="!currentTrack" class="mt-1 h-3 w-32" />
                                <span v-else class="truncate text-sm font-semibold text-foreground" data-test="audio-player-title">{{ title }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button class="button circular small empty" title="Previous" @click="previous">
                                    <SkipBack :size="18" />
                                    <span class="sr-only">Previous</span>
                                </button>
                                <button class="button circular empty" title="Play/Pause" @click="togglePlay">
                                    <Play v-if="!isPlaying" :size="20" />
                                    <Pause v-else :size="20" />
                                </button>
                                <button class="button circular small empty" title="Next" @click="next">
                                    <SkipForward :size="18" />
                                    <span class="sr-only">Next</span>
                                </button>
                            </div>
                            <button class="button circular small empty" :title="isMinimized ? 'Expand Player' : 'Minimize Player'" @click="isMinimized = !isMinimized">
                                <ChevronDown v-if="isMinimized" :size="16" />
                                <ChevronUp v-else :size="16" />
                            </button>
                        </div>
                    </div>
                </template>
                <!-- Mobile Full View -->
                <template v-else>
                    <div class="mb-4 flex items-center gap-2">
                        <div class="relative flex h-16 w-16 items-center justify-center">
                            <Skeleton v-if="!currentTrack" class="h-full w-full rounded" />
                            <div v-else class="relative flex h-16 w-16 items-center justify-center overflow-hidden bg-muted transition-all duration-300">
                                <img v-if="coverImage" :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                                <div v-else class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                                    <span class="text-xs">No Cover</span>
                                </div>
                                <button type="button" class="absolute inset-0" aria-label="Show current in list">
                                    <span class="sr-only">Show current in list</span>
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-1 flex-col gap-1 truncate font-medium text-foreground">
                            <Skeleton v-if="!currentTrack" class="h-3 w-24" />
                            <span v-else class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">{{
                                artistName
                            }}</span>
                            <Skeleton v-if="!currentTrack" class="mt-1 h-4 w-32" />
                            <span v-else class="truncate font-semibold text-foreground" data-test="audio-player-title">{{ title }}</span>
                        </div>

                        <button class="button circular small empty" :title="isMinimized ? 'Expand Player' : 'Minimize Player'" @click="isMinimized = !isMinimized">
                            <ChevronDown v-if="isMinimized" :size="16" />
                            <ChevronUp v-else :size="16" />
                        </button>
                    </div>

                    <div class="mb-2">
                        <div
                            class="mb-2 h-2 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80"
                            data-test="audio-player-progress"
                            @click="handleSeek"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :class="{ 'transition-all': shouldAnimateProgress }"
                                :style="{ width: `${progressPercent}%` }"
                            ></div>
                        </div>
                        <div class="mb-2 flex justify-between text-sm text-muted-foreground">
                            <Skeleton v-if="!currentTrack" class="h-3 w-10" />
                            <span v-else>{{ formatTime(currentTime) }}</span>
                            <Skeleton v-if="!currentTrack" class="h-3 w-10" />
                            <span v-else>{{ formatTime(duration) }}</span>
                        </div>
                    </div>

                    <div class="mb-4 flex items-center justify-center gap-6">
                        <button
                            class="button circular small empty"
                            :class="{ 'bg-primary text-primary-foreground': isShuffled }"
                            title="Shuffle"
                            @click="toggleShuffle"
                        >
                            <Shuffle :size="18" />
                        </button>
                        <button class="button circular small empty" title="Previous" @click="previous">
                            <SkipBack :size="26" />
                            <span class="sr-only">Previous</span>
                        </button>
                        <button class="button circular empty" title="Play/Pause" @click="togglePlay">
                            <Play v-if="!isPlaying" :size="32" />
                            <Pause v-else :size="32" />
                        </button>
                        <button class="button circular small empty" title="Next" @click="next">
                            <SkipForward :size="26" />
                            <span class="sr-only">Next</span>
                        </button>
                        <button
                            class="button circular small empty"
                            :class="{ 'bg-primary text-primary-foreground': repeatMode !== 'off' }"
                            :title="repeatMode === 'off' ? 'Repeat Off' : repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                            @click="toggleRepeat"
                        >
                            <Repeat1 v-if="repeatMode === 'one'" :size="18" />
                            <Repeat v-else :size="18" />
                        </button>
                    </div>
                    <!-- Volume (mobile) -->
                    <div class="mb-4 flex items-center justify-center gap-2 md:hidden">
                        <Volume2 :size="16" />
                        <div class="relative w-40">
                            <div class="h-2 w-full cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="handleVolumeSeek">
                                <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${volume * 100}%` }"></div>
                            </div>
                            <input
                                aria-label="Volume"
                                class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                max="1"
                                min="0"
                                step="0.01"
                                type="range"
                                :value="volume"
                                @input="(e) => setVolume(parseFloat((e.target as HTMLInputElement).value))"
                            />
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
