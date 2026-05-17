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

const queue = ref<AudioPlayerTrack[]>([]);
const currentTrackId = ref<number | null>(null);
const isPlaying = ref(false);

const currentTrack = computed(() => queue.value.find((track) => track.id === currentTrackId.value) ?? null);
const currentTrackIndex = computed(() => queue.value.findIndex((track) => track.id === currentTrackId.value));
const canPlayPrevious = computed(() => currentTrackIndex.value > 0);
const canPlayNext = computed(() => currentTrackIndex.value >= 0 && currentTrackIndex.value < queue.value.length - 1);

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

function playPrevious(): void {
    if (!canPlayPrevious.value) {
        return;
    }

    currentTrackId.value = queue.value[currentTrackIndex.value - 1]?.id ?? currentTrackId.value;
    isPlaying.value = true;
}

function playNext(): void {
    if (!canPlayNext.value) {
        pause();
        return;
    }

    currentTrackId.value = queue.value[currentTrackIndex.value + 1]?.id ?? currentTrackId.value;
    isPlaying.value = true;
}

function clear(): void {
    queue.value = [];
    currentTrackId.value = null;
    isPlaying.value = false;
}

export function useGlobalAudioPlayer() {
    return {
        canPlayNext,
        canPlayPrevious,
        clear,
        currentTrack,
        currentTrackId,
        isPlaying,
        pause,
        playNext,
        playPrevious,
        queue,
        queueAndPlay,
        resume,
        togglePlayback,
        updateCurrentTrack,
        updateQueuedTracks,
    };
}
