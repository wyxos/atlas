<script setup lang="ts">
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import AudioQueuePanel from '@/components/audio/AudioQueuePanel.vue';
import FileReactions from '@/components/audio/FileReactions.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { bus } from '@/lib/bus';
import { audioActions, audioStore } from '@/stores/audio';
import axios from 'axios';
import { ChevronDown, ChevronUp, Menu, Pause, Play, Repeat, Repeat1, Shuffle, SkipBack, SkipForward, Volume2, VolumeX } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import { usePage } from '@inertiajs/vue3';
import { useMediaSession } from '@/composables/useMediaSession';

// Map our store to backup field names
const currentFile = computed<any | null>(() => audioStore.currentTrack || null);
const isPlaying = computed(() => audioStore.isPlaying);
const currentTime = computed(() => audioStore.currentTime || 0);
const duration = computed(() => audioStore.duration || 0);

// Local UI state to mirror backup
const isPlayerMinimized = ref(false);
const isPlayerLoading = ref(false);
const isQueuePanelOpen = ref(false);

// Repeat and volume state
const repeatMode = computed(() => audioStore.repeatMode);
const volume = computed({
    get: () => audioStore.volume,
    set: (v: number) => audioActions.setVolume(v),
});
const volumePercent = computed(() => Math.max(0, Math.min(100, Math.round((volume.value || 0) * 100))));

const currentTitle = computed(() => currentFile.value?.metadata?.payload?.title || '');
const currentArtist = computed(() => {
    const artists = currentFile.value?.artists;
    return artists && artists.length ? artists[0]?.name : 'Unknown Artist';
});

const coverImage = computed((): string | null => {
    const file = currentFile.value;
    if (!file) return null;
    if (file.albums && file.albums.length > 0) {
        for (const album of file.albums) {
            if (album.covers && album.covers.length > 0) {
                return album.covers[0].url || album.covers[0].path;
            }
        }
    }
    if (file.covers && file.covers.length > 0) {
        return file.covers[0].url || file.covers[0].path;
    }
    return null;
});

const spotifyPlaybackError = computed(() => audioStore.spotifyPlaybackError);

const spotifyErrorDialogOpen = computed({
    get: () => spotifyPlaybackError.value != null,
    set: (value: boolean) => {
        if (!value && audioStore.spotifyPlaybackError) {
            void audioActions.resolveSpotifyPlaybackError('stop');
        }
    },
});

async function handleSpotifyErrorSkip(): Promise<void> {
    await audioActions.resolveSpotifyPlaybackError('skip');
}

async function handleSpotifyErrorStop(): Promise<void> {
    await audioActions.resolveSpotifyPlaybackError('stop');
}

function formatTime(seconds: number): string {
    if (!Number.isFinite(seconds)) return '00:00';
    const mins = Math.floor(seconds / 60)
        .toString()
        .padStart(2, '0');
    const secs = Math.floor(seconds % 60)
        .toString()
        .padStart(2, '0');
    return `${mins}:${secs}`;
}

function seekTo(event: MouseEvent): void {
    if ((event as any)?.altKey) {
        event.preventDefault();
        event.stopPropagation();
        return; // ALT+click reserved for reactions; do not seek
    }
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const offsetX = event.clientX - rect.left;
    const percentage = offsetX / rect.width;
    const newTime = percentage * (duration.value || 0);
    audioActions.setCurrentTime(newTime);
}

function togglePlayPause(): void {
    audioActions.toggle();
}

function handlePrevious(): void {
    isPlayerLoading.value = true;
    audioActions.previous();
}

function handleNext(): void {
    isPlayerLoading.value = true;
    audioActions.next();
}

function handleShuffle(): void {
    audioActions.shuffleQueue();
}

function handleRepeat(): void {
    audioActions.toggleRepeatMode();
}


function handleAlbumCoverClick(): void {
    const id = currentFile.value?.id as number | undefined;
    if (!id) return;
    bus.emit('player:scroll-to-current', { id });
}

// Track details loading: rely on store; only toggle skeleton based on presence of basic metadata
watch(
    () => [
        audioStore.currentTrack ? (audioStore.currentTrack as any).id : null,
        (audioStore.currentTrack as any)?.metadata?.payload?.title,
    ] as const,
    ([id, title]) => {
        if (!id) { isPlayerLoading.value = false; return; }
        isPlayerLoading.value = !title;
    },
);

const stopMediaSession = ref<null | (() => void)>(null);

function handleReactionBroadcast(payload?: { id: number; loved: boolean; liked: boolean; disliked: boolean; funny: boolean }) {
    if (!payload) {
        return;
    }
    const { id, loved, liked, disliked, funny } = payload;
    if (audioStore.currentTrack && audioStore.currentTrack.id === id) {
        audioStore.currentTrack = {
            ...(audioStore.currentTrack as any),
            loved,
            liked,
            disliked,
            funny,
        } as any;
    }

    const queue = audioStore.queue as any[];
    const idx = queue.findIndex((item) => item && item.id === id);
    if (idx >= 0) {
        const updated = {
            ...queue[idx],
            loved,
            liked,
            disliked,
            funny,
        };
        queue.splice(idx, 1, updated);
        if (audioStore.currentIndex === idx) {
            audioStore.currentTrack = updated;
        }
    }
}

onMounted(() => {
    audioActions.initVolumeFromStorage();
    // Initialize OS media session + keyboard media keys
    stopMediaSession.value = useMediaSession();
    bus.on('file:reaction', handleReactionBroadcast);
});

onUnmounted(() => {
    stopMediaSession.value?.();
    bus.off('file:reaction', handleReactionBroadcast);
});

// Listen for membership changes globally to keep queue in sync
const page = usePage();
const authUser = (page.props as any)?.auth?.user;
if (authUser?.id) {
    const channel = `App.Models.User.${authUser.id}`;
    useEcho(channel, '.playlist.membership.changed', (e: { file_id: number; previous_playlist_id: number | null; new_playlist_id: number | null }) => {
        // Only act if the queued playlist is the one being moved away from
        if (audioStore.queuePlaylistId != null && e.previous_playlist_id != null && audioStore.queuePlaylistId === e.previous_playlist_id) {
            const idx = (audioStore.queue as any[]).findIndex((it) => it?.id === e.file_id);
            if (idx >= 0) {
                const isCurrent = audioStore.currentTrack?.id === e.file_id;
                (audioStore.queue as any[]).splice(idx, 1);
                if (isCurrent) {
                    // Advance to the next item now at the same index after removal
                    if ((audioStore.queue as any[]).length > idx) {
                        audioStore.currentIndex = idx;
                        audioStore.currentTrack = (audioStore.queue as any[])[idx];
                        // Reset seekbar immediately
                        audioStore.currentTime = 0;
                        audioStore.duration = 0;
                        audioActions.play();
                    } else {
                        audioStore.currentIndex = (audioStore.queue as any[]).length > 0 ? (audioStore.queue as any[]).length - 1 : -1;
                        audioStore.currentTrack = (audioStore.queue as any[])[audioStore.currentIndex] || null;
                        audioStore.isPlaying = false;
                    }
                } else if (audioStore.currentIndex > idx) {
                    // Adjust current index if removal was before current
                    audioStore.currentIndex = Math.max(0, audioStore.currentIndex - 1);
                }
            }
        }
    });
}

// Reactions
async function setReaction(type: 'love' | 'like' | 'dislike' | 'funny') {
    const file = currentFile.value;
    if (!file) return;
    const id = file.id as number;

    // Optimistic update
    const next = { loved: false, liked: false, disliked: false, funny: false } as any;
    const wasOn =
        (file.loved && type === 'love') ||
        (file.liked && type === 'like') ||
        (file.disliked && type === 'dislike') ||
        (file.funny && type === 'funny');
    if (!wasOn) {
        if (type === 'love') next.loved = true;
        else if (type === 'like') next.liked = true;
        else if (type === 'dislike') next.disliked = true;
        else if (type === 'funny') next.funny = true;
    }
    audioStore.currentTrack = { ...(audioStore.currentTrack as any), ...next } as any;

    try {
        const action = AudioController.react({ file: id });
        const res = await axios.post(action.url, { type });
        const data = res.data as { loved: boolean; liked: boolean; disliked: boolean; funny: boolean };
        audioStore.currentTrack = {
            ...(audioStore.currentTrack as any),
            loved: !!data.loved,
            liked: !!data.liked,
            disliked: !!data.disliked,
            funny: !!data.funny,
        } as any;
        bus.emit('file:reaction', { id, loved: !!data.loved, liked: !!data.liked, disliked: !!data.disliked, funny: !!data.funny });
        // Keep seekbar ticking if Spotify is currently playing
        audioActions.ensureTicker();
    } catch (e) {
        console.error('Failed to set reaction from player', e);
        // Still ensure ticker if we are playing Spotify (optimistic UI shouldn't stall)
        audioActions.ensureTicker();
    }
}
function onFav() {
    void setReaction('love');
}
function onLike() {
    void setReaction('like');
}
function onDislike() {
    void setReaction('dislike');
}
function onFunny() {
    void setReaction('funny');
}

function toggleQueuePanel(): void {
    isQueuePanelOpen.value = !isQueuePanelOpen.value;
}
function closeQueuePanel(): void {
    isQueuePanelOpen.value = false;
}
function toggleMinimized(): void {
    isPlayerMinimized.value = !isPlayerMinimized.value;
}

// ALT+mouse shortcuts (parity with Browse):
// - ALT + Left click => like
// - ALT + Middle click => favorite
// - ALT + Right click => dislike
// Do not trigger on interactive controls (buttons, inputs, sliders, links, contenteditable, or [data-no-shortcut])
function isInteractiveTarget(t: EventTarget | null): boolean {
    const el = (t as HTMLElement) || null;
    if (!el) return false;
    return !!el.closest('button, input, select, textarea, a, [role="slider"], [contenteditable], [data-no-shortcut]');
}
function onPlayerMouseDown(e: MouseEvent): void {
    if (!e.altKey || isInteractiveTarget(e.target)) return;
    if (e.button === 0) {
        e.preventDefault(); e.stopPropagation(); onLike();
    } else if (e.button === 1) {
        e.preventDefault(); e.stopPropagation(); onFav();
    } else if (e.button === 2) {
        e.preventDefault(); e.stopPropagation(); onDislike();
    }
}
function onPlayerAuxClick(e: MouseEvent): void {
    if (!e.altKey || isInteractiveTarget(e.target)) return;
    const btn = (e as any).button;
    if (btn === 1) { e.preventDefault(); e.stopPropagation(); onFav(); }
}
function onPlayerContextMenu(e: MouseEvent): void {
    if (e.altKey && !isInteractiveTarget(e.target)) { e.preventDefault(); e.stopPropagation(); onDislike(); }
}
</script>

<template>
    <div v-if="isPlayerLoading || currentFile" class="sticky bottom-0 left-0 w-full border-t border-border bg-card px-4 py-2 md:p-4" @mousedown.capture="onPlayerMouseDown" @auxclick.capture="onPlayerAuxClick" @contextmenu.capture="onPlayerContextMenu">
        <!-- Desktop -->
        <div class="hidden items-center gap-4 md:flex">
            <!-- Minimized -->
            <template v-if="isPlayerMinimized">
                <div class="flex flex-1 items-center gap-4">
                    <!-- Small cover -->
                    <div v-if="isPlayerLoading" class="relative flex h-12 w-12 items-center justify-center">
                        <Skeleton class="h-full w-full" />
                    </div>
                    <div
                        v-else-if="currentFile"
                        class="relative flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
                    >
                        <template v-if="coverImage">
                            <img :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                        </template>
                        <template v-else>
                            <div class="flex h-full w-full items-center justify-center rounded bg-muted text-muted-foreground">
                                <span class="text-xs">♪</span>
                            </div>
                        </template>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list" @click.stop="handleAlbumCoverClick">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-1 items-center gap-4">
                        <div class="flex min-w-0 flex-col">
                            <div v-if="isPlayerLoading" class="flex gap-2 font-medium text-white">
                                <Skeleton class="h-4 w-32" />
                            </div>
                            <div v-else-if="currentFile" class="font-medium text-foreground">
<span
                                    class="truncate text-sm font-semibold"
                                    data-test="audio-player-title"
                                    >{{ currentTitle }}</span
                                >
                                <span class="block truncate text-xs text-muted-foreground" data-test="audio-player-artist">{{ currentArtist }}</span>
                            </div>
                        </div>

                        <!-- Basic playback controls -->
                        <div class="flex items-center gap-2">
                            <button class="button circular small empty" title="Previous" @click="handlePrevious">
                                <SkipBack :size="16" />
                                <span class="sr-only">Previous</span>
                            </button>
                            <button
                                :class="{ active: isPlaying, secondary: !isPlaying }"
                                class="button circular empty"
                                title="Play/Pause"
                                @click="togglePlayPause"
                            >
                                <Play v-if="!isPlaying" :size="20" />
                                <Pause v-else :size="20" />
                            </button>
                            <button class="button circular small empty" title="Next" @click="handleNext">
                                <SkipForward :size="16" />
                                <span class="sr-only">Next</span>
                            </button>
                        </div>

                        <!-- Compact progress bar -->
                        <div v-if="currentFile" class="mx-4 min-w-0 flex-1">
                        <div class="h-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)" data-test="audio-player-progress">
                                <div
                                    :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                                    class="h-full rounded-full bg-primary"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Full View -->
            <template v-else>
                <div class="flex w-100 items-center gap-4">
                    <div v-if="isPlayerLoading" class="relative flex h-18 w-18 items-center justify-center md:h-32 md:w-32">
                        <Skeleton class="h-full w-full" />
                    </div>
                    <div
                        v-else-if="currentFile"
                        class="relative flex h-18 w-18 shrink-0 items-center justify-center overflow-hidden bg-muted transition-all duration-300 md:h-32 md:w-32"
                    >
                        <template v-if="coverImage">
                            <img :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                        </template>
                        <template v-else>
                            <div class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                                <span class="text-xs">No Cover</span>
                            </div>
                        </template>
                        <button type="button" class="absolute inset-0" aria-label="Show current in list" @click.stop="handleAlbumCoverClick">
                            <span class="sr-only">Show current in list</span>
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 truncate">
                        <div v-if="isPlayerLoading" class="mb-2 flex flex-col gap-2 font-medium text-white">
                            <Skeleton class="h-4 w-24" />
                            <Skeleton class="h-5 w-32" />
                        </div>
                        <div v-else-if="currentFile" class="mb-2 flex flex-col gap-1 font-medium text-foreground">
<span class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">{{ currentArtist }}</span>
                            <span
                                    class="truncate font-semibold text-foreground"
                                    data-test="audio-player-title"
                                    >{{ currentTitle }}</span
                                >
                        </div>

                        <!-- Reactions placeholder -->
                        <div class="flex flex-1 items-center">
                            <FileReactions
                                :file="currentFile"
                                :size="16"
                                @favorite="onFav"
                                @like="onLike"
                                @dislike="onDislike"
                                @laughed-at="onFunny"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex-1">
                    <div class="mb-2">
                        <div v-if="isPlayerLoading" class="mb-2 flex items-center justify-between gap-4 text-xs text-white">
                            <Skeleton class="h-3 w-10" />
                            <div class="h-2 w-full rounded-full bg-muted"></div>
                            <Skeleton class="h-3 w-10" />
                        </div>
                        <div v-else-if="currentFile" class="mb-2 flex items-center gap-4 text-xs text-muted-foreground">
                            <span>{{ formatTime(currentTime) }}</span>
<div class="h-2 flex-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)" data-test="audio-player-progress">
                                <div
                                    :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                                    class="h-full rounded-full bg-primary"
                                ></div>
                            </div>
                            <span>{{ formatTime(duration) }}</span>
                        </div>

                        <div class="mt-2 flex items-center justify-center gap-4">
                            <div class="flex items-center gap-4">
                                <button class="button circular small empty" title="Shuffle" @click="handleShuffle">
                                    <Shuffle :size="16" />
                                </button>
                                <button class="button circular small empty" title="Previous" @click="handlePrevious">
                                    <SkipBack :size="20" />
                                    <span class="sr-only">Previous</span>
                                </button>
                                <button
                                    :class="{ active: isPlaying, secondary: !isPlaying }"
                                    class="button circular empty"
                                    title="Play/Pause"
                                    @click="togglePlayPause"
                                >
                                    <Play v-if="!isPlaying" :size="24" />
                                    <Pause v-else :size="24" />
                                </button>
                                <button class="button circular small empty" title="Next" @click="handleNext">
                                    <SkipForward :size="20" />
                                    <span class="sr-only">Next</span>
                                </button>
                                <button
                                    :class="{
                                        'bg-primary text-primary-foreground': repeatMode === 'all',
                                        'bg-blue-500 text-white': repeatMode === 'one',
                                    }"
                                    :title="repeatMode === 'off' ? 'Repeat Off' : repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                                    class="button circular small empty"
                                    @click="handleRepeat"
                                >
                                    <Repeat1 v-if="repeatMode === 'one'" :size="16" />
                                    <Repeat v-else :size="16" />
                                </button>
                                <!-- Volume -->
                                <div class="group ml-4 hidden items-center gap-2 md:flex">
                                    <component :is="volume <= 0 ? VolumeX : Volume2" :size="16" class="text-muted-foreground group-hover:text-primary-foreground" />
                                    <input
                                        aria-label="Volume"
                                        class="h-1 w-28 cursor-pointer appearance-none rounded-full"
                                        max="1"
                                        min="0"
                                        step="0.01"
                                        type="range"
                                        v-model.number="volume"
                                        :style="{ background: `linear-gradient(90deg, var(--sidebar-ring) ${volumePercent}%, var(--muted) ${volumePercent}%)` }"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex w-100 justify-end gap-2">
                <button
                    :title="isPlayerMinimized ? 'Restore Player' : 'Minimize Player'"
                    class="button circular small empty"
                    @click="toggleMinimized"
                >
                    <ChevronUp v-if="isPlayerMinimized" :size="16" />
                    <ChevronDown v-else :size="16" />
                </button>
                <button
                    :class="{ 'bg-primary text-primary-foreground': isQueuePanelOpen }"
                    class="button circular small empty"
                    title="Show Queue"
                    @click="toggleQueuePanel"
                >
                    <Menu :size="16" />
                </button>
            </div>
        </div>

        <!-- Queue Panel -->
        <AudioQueuePanel :is-open="isQueuePanelOpen" @close="closeQueuePanel" />

        <!-- Mobile -->
        <div class="md:hidden">
            <div class="mb-4 flex items-center gap-2">
                <div v-if="isPlayerLoading" class="relative flex h-16 w-16 items-center justify-center">
                    <Skeleton class="h-full w-full" />
                </div>
                <div
                    v-else-if="currentFile"
                    class="relative flex h-16 w-16 items-center justify-center overflow-hidden bg-muted transition-all duration-300"
                >
                    <template v-if="coverImage">
                        <img :src="coverImage" alt="Cover" class="h-full w-full object-contain" />
                    </template>
                    <template v-else>
                        <div class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                            <span class="text-xs">No Cover</span>
                        </div>
                    </template>
                    <button type="button" class="absolute inset-0" aria-label="Show current in list" @click.stop="handleAlbumCoverClick">
                        <span class="sr-only">Show current in list</span>
                    </button>
                </div>

                <div v-if="isPlayerLoading" class="flex flex-1 flex-col gap-2 font-medium text-white">
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="h-5 w-32" />
                </div>
                <div v-else-if="currentFile" class="flex flex-1 flex-col gap-1 truncate font-medium text-foreground">
<span class="truncate text-xs font-semibold text-muted-foreground" data-test="audio-player-artist">{{ currentArtist }}</span>
                    <span
                        class="truncate font-semibold text-foreground"
                        data-test="audio-player-title"
                        >{{ currentTitle }}</span
                    >
                </div>

                <button
                    :title="isPlayerMinimized ? 'Restore Player' : 'Minimize Player'"
                    class="button circular small empty"
                    @click="toggleMinimized"
                >
                    <ChevronUp v-if="isPlayerMinimized" :size="16" />
                    <ChevronDown v-else :size="16" />
                </button>
            </div>

            <template v-if="!isPlayerMinimized">
                <div v-if="isPlayerLoading" class="mb-2">
                    <div class="mb-2 h-2 w-full rounded-full bg-muted"></div>
                    <div class="mb-2 flex justify-between text-xs text-white">
                        <Skeleton class="h-3 w-10" />
                        <Skeleton class="h-3 w-10" />
                    </div>
                </div>
                <div v-else-if="currentFile" class="mb-2">
<div class="mb-2 h-2 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)" data-test="audio-player-progress">
                        <div
                            :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                            class="h-full rounded-full bg-primary"
                        ></div>
                    </div>
                    <div class="mb-2 flex justify-between text-sm text-muted-foreground">
                        <span>{{ formatTime(currentTime) }}</span>
                        <span>{{ formatTime(duration) }}</span>
                    </div>
                </div>

                <div class="mb-4 flex items-center justify-center gap-6">
                    <button class="button circular small empty" title="Shuffle" @click="handleShuffle">
                        <Shuffle :size="18" />
                    </button>
                    <button class="button circular small empty" title="Previous" @click="handlePrevious">
                        <SkipBack :size="26" />
                        <span class="sr-only">Previous</span>
                    </button>
                    <button
                        :class="{ active: isPlaying, secondary: !isPlaying }"
                        class="button circular empty"
                        title="Play/Pause"
                        @click="togglePlayPause"
                    >
                        <Play v-if="!isPlaying" :size="32" />
                        <Pause v-else :size="32" />
                    </button>
                    <button class="button circular small empty" title="Next" @click="handleNext">
                        <SkipForward :size="26" />
                        <span class="sr-only">Next</span>
                    </button>
                    <button
                        :class="{ 'bg-primary text-primary-foreground': repeatMode === 'all', 'bg-blue-500 text-white': repeatMode === 'one' }"
                        :title="repeatMode === 'off' ? 'Repeat Off' : repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                        class="button circular small empty"
                        @click="handleRepeat"
                    >
                        <Repeat1 v-if="repeatMode === 'one'" :size="16" />
                        <Repeat v-else :size="18" />
                    </button>
                </div>
                <!-- Volume (mobile) -->
                <div class="mb-4 flex items-center justify-center gap-2 md:hidden">
                    <component :is="volume <= 0 ? VolumeX : Volume2" :size="16" />
                    <input
                        aria-label="Volume"
                        class="h-1 w-40 cursor-pointer appearance-none rounded-full bg-muted accent-primary"
                        max="1"
                        min="0"
                        step="0.01"
                        type="range"
                        v-model.number="volume"
                    />
                </div>
            </template>
        </div>

        <Dialog v-model:open="spotifyErrorDialogOpen">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle>Spotify can&apos;t play this track</DialogTitle>
                    <DialogDescription>
                        {{ spotifyPlaybackError?.message }}
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-if="spotifyPlaybackError?.details"
                    class="whitespace-pre-wrap rounded-md border border-border/60 bg-muted/50 px-3 py-2 text-xs text-muted-foreground"
                >
                    {{ spotifyPlaybackError.details }}
                </p>
                <DialogFooter class="mt-4 flex gap-2">
                    <Button variant="secondary" type="button" @click="handleSpotifyErrorStop">Stop playback</Button>
                    <Button type="button" data-test="spotify-error-skip" @click="handleSpotifyErrorSkip">Skip track</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
