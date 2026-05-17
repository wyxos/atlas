import { computed, ref } from 'vue';
import { serve } from '@/actions/App/Http/Controllers/FilesController';
import type { ReactionType } from '@/types/reaction';

export type AudioPlayerTrack = {
    id: number;
    title: string;
    artists: string;
    album: string;
    coverUrl: string | null;
    duration: string;
    durationSeconds: number | null;
    reaction: { type: ReactionType } | null;
    blacklistedAt: string | null;
    previewedCount: number;
    seenCount: number;
    playbackUrl: string;
};

export type AudioRepeatMode = 'none' | 'all' | 'one';

const queue = ref<AudioPlayerTrack[]>([]);
const currentTrackId = ref<number | null>(null);
const isPlaying = ref(false);
const repeatMode = ref<AudioRepeatMode>('none');

const currentTrack = computed(() => queue.value.find((track) => track.id === currentTrackId.value) ?? null);
const currentTrackIndex = computed(() => queue.value.findIndex((track) => track.id === currentTrackId.value));
const queueLength = computed(() => queue.value.length);
const hasQueue = computed(() => queue.value.length > 0);
const canPlayPrevious = computed(() => currentTrackIndex.value > 0 || (repeatMode.value === 'all' && queue.value.length > 1));
const canPlayNext = computed(() => currentTrackIndex.value >= 0 && (
    currentTrackIndex.value < queue.value.length - 1
    || (repeatMode.value === 'all' && queue.value.length > 1)
));

function withPlaybackUrl(track: Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }): AudioPlayerTrack {
    return {
        ...track,
        playbackUrl: track.playbackUrl ?? serve.url(track.id),
    };
}

function queueAndPlay(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>, trackId: number): void {
    queue.value = tracks.map(withPlaybackUrl);
    currentTrackId.value = trackId;
    isPlaying.value = true;
}

function queueTracks(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>, trackId?: number): void {
    const nextQueue = tracks.map(withPlaybackUrl);

    if (nextQueue.length === 0) {
        queue.value = [];
        currentTrackId.value = null;
        isPlaying.value = false;
        return;
    }

    if (currentTrackId.value !== null && queue.value.length > 0 && nextQueue.some((track) => track.id === currentTrackId.value)) {
        return;
    }

    queue.value = nextQueue;
    currentTrackId.value = nextQueue.find((track) => track.id === trackId)?.id ?? nextQueue[0]?.id ?? null;
}

function updateQueuedTracks(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>): void {
    if (queue.value.length === 0) {
        return;
    }

    const updatesById = new Map(tracks.map((track) => [track.id, withPlaybackUrl(track)]));
    queue.value = queue.value.map((track) => updatesById.get(track.id) ?? track);
}

function updateCurrentTrack(updates: Partial<AudioPlayerTrack>): void {
    if (currentTrackId.value === null) {
        return;
    }

    queue.value = queue.value.map((track) => track.id === currentTrackId.value ? { ...track, ...updates } : track);
}

function pause(): void {
    isPlaying.value = false;
}

function resume(): void {
    if (currentTrack.value) {
        isPlaying.value = true;
    }
}

function togglePlayback(): void {
    if (isPlaying.value) {
        pause();
        return;
    }

    resume();
}

function playQueueTrack(trackId: number): void {
    if (!queue.value.some((track) => track.id === trackId)) {
        return;
    }

    currentTrackId.value = trackId;
    isPlaying.value = true;
}

function playPrevious(): void {
    if (currentTrackIndex.value > 0) {
        currentTrackId.value = queue.value[currentTrackIndex.value - 1]?.id ?? currentTrackId.value;
        isPlaying.value = true;
        return;
    }

    if (repeatMode.value === 'all' && queue.value.length > 1) {
        currentTrackId.value = queue.value[queue.value.length - 1]?.id ?? currentTrackId.value;
        isPlaying.value = true;
        return;
    }

    if (!currentTrack.value) {
        return;
    }
}

function playNext(): void {
    if (currentTrackIndex.value >= 0 && currentTrackIndex.value < queue.value.length - 1) {
        currentTrackId.value = queue.value[currentTrackIndex.value + 1]?.id ?? currentTrackId.value;
        isPlaying.value = true;
        return;
    }

    if (repeatMode.value === 'all' && queue.value.length > 0) {
        currentTrackId.value = queue.value[0]?.id ?? currentTrackId.value;
        isPlaying.value = true;
        return;
    }

    pause();
}

function shuffleQueue(): void {
    if (queue.value.length <= 1) {
        return;
    }

    const activeTrack = currentTrack.value;
    const tracksToShuffle = activeTrack
        ? queue.value.filter((track) => track.id !== activeTrack.id)
        : [...queue.value];

    for (let index = tracksToShuffle.length - 1; index > 0; index--) {
        const swapIndex = Math.floor(Math.random() * (index + 1));
        [tracksToShuffle[index], tracksToShuffle[swapIndex]] = [tracksToShuffle[swapIndex], tracksToShuffle[index]];
    }

    queue.value = activeTrack ? [activeTrack, ...tracksToShuffle] : tracksToShuffle;
}

function cycleRepeatMode(): void {
    repeatMode.value = {
        none: 'all',
        all: 'one',
        one: 'none',
    }[repeatMode.value] as AudioRepeatMode;
}

function setRepeatMode(mode: AudioRepeatMode): void {
    repeatMode.value = mode;
}

function restartCurrentTrack(): void {
    if (!currentTrack.value) {
        pause();
        return;
    }

    isPlaying.value = true;
}

function clear(): void {
    queue.value = [];
    currentTrackId.value = null;
    isPlaying.value = false;
    repeatMode.value = 'none';
}

export function useGlobalAudioPlayer() {
    return {
        canPlayNext,
        canPlayPrevious,
        cycleRepeatMode,
        clear,
        currentTrack,
        currentTrackId,
        hasQueue,
        isPlaying,
        pause,
        playNext,
        playPrevious,
        playQueueTrack,
        queue,
        queueLength,
        queueAndPlay,
        queueTracks,
        repeatMode,
        resume,
        restartCurrentTrack,
        setRepeatMode,
        shuffleQueue,
        togglePlayback,
        updateCurrentTrack,
        updateQueuedTracks,
    };
}
