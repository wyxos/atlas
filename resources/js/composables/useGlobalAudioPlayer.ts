import { computed, ref, watch } from 'vue';
import { serve } from '@/actions/App/Http/Controllers/FilesController';
import type { ReactionType } from '@/types/reaction';

export type AudioPlayerTrack = {
    id: number;
    title: string;
    source?: string | null;
    sourceId?: string | null;
    spotifyUri?: string | null;
    artists: string;
    album: string;
    coverUrl: string | null;
    duration: string;
    durationSeconds: number | null;
    reaction: { type: ReactionType } | null;
    blacklistedAt: string | null;
    previewedCount: number;
    seenCount: number;
    playCount?: number;
    skipCount?: number;
    playbackUrl: string;
};

export type AudioPlaybackStatsEventDetail = {
    file_id: number;
    last_played_at: string | null;
    last_skipped_at: string | null;
    play_count: number;
    skip_count: number;
};

export const AUDIO_PLAYBACK_STATS_EVENT = 'atlas:audio-playback-stats';

type AudioQueueOptions = {
    queueLabel?: string | null;
};

export type AudioRepeatMode = 'none' | 'all' | 'one';

type PersistedAudioPlayerState = {
    version: 1;
    currentTrackId: number | null;
    isShuffleEnabled: boolean;
    isPlaying: boolean;
    playbackPositionSeconds: number;
    queue: AudioPlayerTrack[];
    queueLabel: string | null;
    repeatMode: AudioRepeatMode;
    unshuffledQueueIds: number[];
};

type AudioTrackFocusRequest = {
    sequence: number;
    trackId: number;
};

export const AUDIO_PLAYER_STATE_STORAGE_KEY = 'atlas:audioPlayerState';

function isAudioRepeatMode(value: unknown): value is AudioRepeatMode {
    return value === 'none' || value === 'all' || value === 'one';
}

function isStoredTrack(value: unknown): value is AudioPlayerTrack {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const track = value as Partial<AudioPlayerTrack>;

    return typeof track.id === 'number'
        && Number.isFinite(track.id)
        && typeof track.title === 'string'
        && (typeof track.source === 'string' || track.source === null || track.source === undefined)
        && (typeof track.sourceId === 'string' || track.sourceId === null || track.sourceId === undefined)
        && (typeof track.spotifyUri === 'string' || track.spotifyUri === null || track.spotifyUri === undefined)
        && typeof track.artists === 'string'
        && typeof track.album === 'string'
        && typeof track.duration === 'string';
}

function withPlaybackUrl(track: Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }): AudioPlayerTrack {
    return {
        ...track,
        source: track.source ?? null,
        sourceId: track.sourceId ?? null,
        spotifyUri: track.spotifyUri ?? null,
        playCount: track.playCount ?? 0,
        skipCount: track.skipCount ?? 0,
        playbackUrl: track.playbackUrl ?? serve.url(track.id),
    };
}

function trackIds(tracks: AudioPlayerTrack[]): number[] {
    return tracks.map((track) => track.id);
}

function storedTrackIds(value: unknown): number[] {
    if (!Array.isArray(value)) {
        return [];
    }

    const seenIds = new Set<number>();

    return value.filter((id): id is number => {
        if (typeof id !== 'number' || !Number.isFinite(id) || seenIds.has(id)) {
            return false;
        }

        seenIds.add(id);
        return true;
    });
}

function orderTracksByIds(tracks: AudioPlayerTrack[], orderedIds: number[]): AudioPlayerTrack[] {
    const tracksById = new Map(tracks.map((track) => [track.id, track]));
    const orderedTracks = orderedIds
        .map((id) => tracksById.get(id))
        .filter((track): track is AudioPlayerTrack => track !== undefined);
    const orderedTrackIds = new Set(orderedTracks.map((track) => track.id));

    return [
        ...orderedTracks,
        ...tracks.filter((track) => !orderedTrackIds.has(track.id)),
    ];
}

function shuffledTracks(tracks: AudioPlayerTrack[]): AudioPlayerTrack[] {
    const shuffled = [...tracks];

    for (let index = shuffled.length - 1; index > 0; index--) {
        const swapIndex = Math.floor(Math.random() * (index + 1));
        [shuffled[index], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[index]];
    }

    return shuffled;
}

function readAudioPlayerState(): PersistedAudioPlayerState | null {
    if (typeof window === 'undefined' || !('sessionStorage' in window)) {
        return null;
    }

    try {
        const raw = window.sessionStorage.getItem(AUDIO_PLAYER_STATE_STORAGE_KEY);
        const state = raw ? JSON.parse(raw) as Partial<PersistedAudioPlayerState> : null;

        if (state?.version !== 1 || !Array.isArray(state.queue)) {
            return null;
        }

        const restoredQueue = state.queue.filter(isStoredTrack).map(withPlaybackUrl);
        const restoredUnshuffledQueueIds = storedTrackIds(state.unshuffledQueueIds);
        const restoredTrackIds = new Set(restoredQueue.map((track) => track.id));
        const restoredTrackId = typeof state.currentTrackId === 'number' && restoredTrackIds.has(state.currentTrackId)
            ? state.currentTrackId
            : restoredQueue[0]?.id ?? null;

        return {
            version: 1,
            currentTrackId: restoredTrackId,
            isShuffleEnabled: Boolean(state.isShuffleEnabled && restoredUnshuffledQueueIds.length > 0 && restoredQueue.length > 0),
            isPlaying: Boolean(state.isPlaying && restoredTrackId !== null),
            playbackPositionSeconds: typeof state.playbackPositionSeconds === 'number' && Number.isFinite(state.playbackPositionSeconds)
                ? Math.max(0, state.playbackPositionSeconds)
                : 0,
            queue: restoredQueue,
            queueLabel: typeof state.queueLabel === 'string' ? state.queueLabel : null,
            repeatMode: isAudioRepeatMode(state.repeatMode) ? state.repeatMode : 'none',
            unshuffledQueueIds: restoredUnshuffledQueueIds.length > 0 ? restoredUnshuffledQueueIds : trackIds(restoredQueue),
        };
    } catch {
        return null;
    }
}

const storedState = readAudioPlayerState();

const queue = ref<AudioPlayerTrack[]>(storedState?.queue ?? []);
const queueLabel = ref<string | null>(storedState?.queueLabel ?? null);
const currentTrackId = ref<number | null>(storedState?.currentTrackId ?? null);
const isShuffleEnabled = ref(storedState?.isShuffleEnabled ?? false);
const isPlaying = ref(storedState?.isPlaying ?? false);
const playbackPositionSeconds = ref(storedState?.playbackPositionSeconds ?? 0);
const repeatMode = ref<AudioRepeatMode>(storedState?.repeatMode ?? 'none');
const unshuffledQueueIds = ref<number[]>(storedState?.unshuffledQueueIds ?? trackIds(queue.value));
const trackFocusRequest = ref<AudioTrackFocusRequest | null>(null);
const isQueueSheetOpen = ref(false);
let trackFocusRequestSequence = 0;
let isBatchingAudioPlayerState = false;
let hasPendingAudioPlayerStateWrite = false;
let isSkippingAudioPlayerStateWrite = false;

function writeAudioPlayerState(): void {
    if (typeof window === 'undefined' || !('sessionStorage' in window)) {
        return;
    }

    try {
        if (queue.value.length === 0) {
            window.sessionStorage.removeItem(AUDIO_PLAYER_STATE_STORAGE_KEY);
            return;
        }

        window.sessionStorage.setItem(AUDIO_PLAYER_STATE_STORAGE_KEY, JSON.stringify({
            version: 1,
            currentTrackId: currentTrackId.value,
            isShuffleEnabled: isShuffleEnabled.value,
            isPlaying: isPlaying.value,
            playbackPositionSeconds: playbackPositionSeconds.value,
            queue: queue.value,
            queueLabel: queueLabel.value,
            repeatMode: repeatMode.value,
            unshuffledQueueIds: unshuffledQueueIds.value,
        } satisfies PersistedAudioPlayerState));
    } catch {
        // Ignore storage errors (private mode, quota, etc.).
    }
}

function persistAudioPlayerState(): void {
    if (isSkippingAudioPlayerStateWrite) {
        return;
    }

    if (isBatchingAudioPlayerState) {
        hasPendingAudioPlayerStateWrite = true;
        return;
    }

    writeAudioPlayerState();
}

function batchAudioPlayerState(updates: () => void): void {
    isBatchingAudioPlayerState = true;

    try {
        updates();
    } finally {
        isBatchingAudioPlayerState = false;

        if (hasPendingAudioPlayerStateWrite) {
            hasPendingAudioPlayerStateWrite = false;
            writeAudioPlayerState();
        }
    }
}

function withoutAudioPlayerStatePersistence(updates: () => void): void {
    const wasSkippingAudioPlayerStateWrite = isSkippingAudioPlayerStateWrite;
    isSkippingAudioPlayerStateWrite = true;

    try {
        updates();
    } finally {
        isSkippingAudioPlayerStateWrite = wasSkippingAudioPlayerStateWrite;
    }
}

watch([queue, queueLabel, currentTrackId, isShuffleEnabled, isPlaying, playbackPositionSeconds, repeatMode, unshuffledQueueIds], persistAudioPlayerState, {
    flush: 'sync',
});

const currentTrack = computed(() => queue.value.find((track) => track.id === currentTrackId.value) ?? null);
const currentTrackIndex = computed(() => queue.value.findIndex((track) => track.id === currentTrackId.value));
const queueLength = computed(() => queue.value.length);
const hasQueue = computed(() => queue.value.length > 0);
const canPlayPrevious = computed(() => currentTrackIndex.value > 0 || (repeatMode.value === 'all' && queue.value.length > 1));
const canPlayNext = computed(() => currentTrackIndex.value >= 0 && (
    currentTrackIndex.value < queue.value.length - 1
    || (repeatMode.value === 'all' && queue.value.length > 1)
));

function setQueueLabel(options?: AudioQueueOptions): void {
    queueLabel.value = options?.queueLabel?.trim() || null;
}

function queueAndPlay(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>, trackId: number, options?: AudioQueueOptions): void {
    const nextQueue = tracks.map(withPlaybackUrl);

    batchAudioPlayerState(() => {
        queue.value = nextQueue;
        unshuffledQueueIds.value = trackIds(nextQueue);
        isShuffleEnabled.value = false;
        setQueueLabel(options);
        playbackPositionSeconds.value = 0;
        currentTrackId.value = trackId;
        isPlaying.value = true;
    });
}

function queueAndShufflePlay(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>, options?: AudioQueueOptions): void {
    const nextQueue = tracks.map(withPlaybackUrl);

    if (nextQueue.length === 0) {
        return;
    }

    batchAudioPlayerState(() => {
        unshuffledQueueIds.value = trackIds(nextQueue);
        queue.value = nextQueue.length > 1 ? shuffledTracks(nextQueue) : nextQueue;
        isShuffleEnabled.value = true;
        setQueueLabel(options);
        playbackPositionSeconds.value = 0;
        currentTrackId.value = queue.value[0]?.id ?? null;
        isPlaying.value = true;
    });
}

function queueTracks(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>, trackId?: number, options?: AudioQueueOptions): void {
    const nextQueue = tracks.map(withPlaybackUrl);

    if (nextQueue.length === 0) {
        batchAudioPlayerState(() => {
            queue.value = [];
            queueLabel.value = null;
            unshuffledQueueIds.value = [];
            isShuffleEnabled.value = false;
            currentTrackId.value = null;
            playbackPositionSeconds.value = 0;
            isPlaying.value = false;
        });
        return;
    }

    if (currentTrackId.value !== null && queue.value.length > 0 && nextQueue.some((track) => track.id === currentTrackId.value)) {
        return;
    }

    batchAudioPlayerState(() => {
        queue.value = nextQueue;
        unshuffledQueueIds.value = trackIds(nextQueue);
        isShuffleEnabled.value = false;
        setQueueLabel(options);
        playbackPositionSeconds.value = 0;
        currentTrackId.value = nextQueue.find((track) => track.id === trackId)?.id ?? nextQueue[0]?.id ?? null;
    });
}

function updateQueuedTracks(tracks: Array<Omit<AudioPlayerTrack, 'playbackUrl'> & { playbackUrl?: string }>): void {
    if (queue.value.length === 0) {
        return;
    }

    const updatesById = new Map(tracks.map((track) => [track.id, withPlaybackUrl(track)]));

    withoutAudioPlayerStatePersistence(() => {
        queue.value = queue.value.map((track) => updatesById.get(track.id) ?? track);
    });
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

    playbackPositionSeconds.value = 0;
    currentTrackId.value = trackId;
    isPlaying.value = true;
}

function playPrevious(): void {
    if (currentTrackIndex.value > 0) {
        batchAudioPlayerState(() => {
            playbackPositionSeconds.value = 0;
            currentTrackId.value = queue.value[currentTrackIndex.value - 1]?.id ?? currentTrackId.value;
            isPlaying.value = true;
        });
        return;
    }

    if (repeatMode.value === 'all' && queue.value.length > 1) {
        batchAudioPlayerState(() => {
            playbackPositionSeconds.value = 0;
            currentTrackId.value = queue.value[queue.value.length - 1]?.id ?? currentTrackId.value;
            isPlaying.value = true;
        });
        return;
    }

    if (!currentTrack.value) {
        return;
    }
}

function playNext(): void {
    if (currentTrackIndex.value >= 0 && currentTrackIndex.value < queue.value.length - 1) {
        batchAudioPlayerState(() => {
            playbackPositionSeconds.value = 0;
            currentTrackId.value = queue.value[currentTrackIndex.value + 1]?.id ?? currentTrackId.value;
            isPlaying.value = true;
        });
        return;
    }

    if (repeatMode.value === 'all' && queue.value.length > 0) {
        batchAudioPlayerState(() => {
            playbackPositionSeconds.value = 0;
            currentTrackId.value = queue.value[0]?.id ?? currentTrackId.value;
            isPlaying.value = true;
        });
        return;
    }

    pause();
}

function shuffleQueue(): void {
    if (queue.value.length === 0) {
        batchAudioPlayerState(() => {
            unshuffledQueueIds.value = trackIds(queue.value);
            isShuffleEnabled.value = false;
        });
        return;
    }

    if (isShuffleEnabled.value) {
        batchAudioPlayerState(() => {
            queue.value = orderTracksByIds(queue.value, unshuffledQueueIds.value);
            isShuffleEnabled.value = false;
        });
        return;
    }

    const activeTrack = currentTrack.value;
    const tracksToShuffle = activeTrack
        ? queue.value.filter((track) => track.id !== activeTrack.id)
        : [...queue.value];

    const shuffled = shuffledTracks(tracksToShuffle);
    batchAudioPlayerState(() => {
        unshuffledQueueIds.value = trackIds(queue.value);
        queue.value = activeTrack ? [activeTrack, ...shuffled] : shuffled;
        isShuffleEnabled.value = true;
    });
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

    batchAudioPlayerState(() => {
        playbackPositionSeconds.value = 0;
        isPlaying.value = true;
    });
}

function clear(): void {
    batchAudioPlayerState(() => {
        queue.value = [];
        queueLabel.value = null;
        unshuffledQueueIds.value = [];
        isShuffleEnabled.value = false;
        currentTrackId.value = null;
        playbackPositionSeconds.value = 0;
        isPlaying.value = false;
        repeatMode.value = 'none';
        isQueueSheetOpen.value = false;
    });
}

function updatePlaybackPosition(seconds: number): void {
    if (!Number.isFinite(seconds)) {
        return;
    }

    playbackPositionSeconds.value = Math.max(0, Math.round(seconds * 10) / 10);
}

function requestCurrentTrackFocus(): void {
    if (currentTrackId.value === null) {
        return;
    }

    trackFocusRequest.value = {
        sequence: ++trackFocusRequestSequence,
        trackId: currentTrackId.value,
    };
}

function openQueueSheet(): void {
    if (!hasQueue.value) {
        return;
    }

    isQueueSheetOpen.value = true;
}

function closeQueueSheet(): void {
    isQueueSheetOpen.value = false;
}

function toggleQueueSheet(): void {
    if (isQueueSheetOpen.value) {
        closeQueueSheet();
        return;
    }

    openQueueSheet();
}

export function useGlobalAudioPlayer() {
    return {
        canPlayNext,
        canPlayPrevious,
        closeQueueSheet,
        cycleRepeatMode,
        clear,
        currentTrack,
        currentTrackId,
        hasQueue,
        isQueueSheetOpen,
        isShuffleEnabled,
        isPlaying,
        openQueueSheet,
        pause,
        playbackPositionSeconds,
        playNext,
        playPrevious,
        playQueueTrack,
        queue,
        queueLabel,
        queueLength,
        queueAndPlay,
        queueAndShufflePlay,
        queueTracks,
        requestCurrentTrackFocus,
        repeatMode,
        resume,
        restartCurrentTrack,
        setRepeatMode,
        shuffleQueue,
        togglePlayback,
        toggleQueueSheet,
        trackFocusRequest,
        updateCurrentTrack,
        updatePlaybackPosition,
        updateQueuedTracks,
    };
}
