import { afterEach, describe, expect, it, vi } from 'vitest';
import { computed, ref } from 'vue';
import { useAudioPlaybackEngines } from './useAudioPlaybackEngines';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

const spotifyPlaybackMocks = vi.hoisted(() => ({
    authenticationError: new Error('Spotify is not connected for this account.'),
    currentState: vi.fn().mockResolvedValue(null),
    destroy: vi.fn(),
    options: null as {
        onStateChange?: (snapshot: {
            durationMs: number;
            paused: boolean;
            positionMs: number;
            trackUri: string | null;
        } | null) => void;
    } | null,
    pause: vi.fn().mockResolvedValue(undefined),
    play: vi.fn().mockResolvedValue(undefined),
    seek: vi.fn().mockResolvedValue(undefined),
    setVolume: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('@/services/spotifyPlayback', () => ({
    createSpotifyPlaybackController: vi.fn((options) => {
        spotifyPlaybackMocks.options = options;

        return spotifyPlaybackMocks;
    }),
    isSpotifyPlaybackAuthenticationError: vi.fn((error) => error === spotifyPlaybackMocks.authenticationError),
    isSpotifyPlaybackSuperseded: vi.fn(() => false),
}));

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: '',
        album: '',
        coverUrl: null,
        duration: '3:00',
        durationSeconds: 180,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

function createDeferred<T>() {
    let resolve: ((value: T | PromiseLike<T>) => void) | null = null;
    const promise = new Promise<T>((innerResolve) => {
        resolve = innerResolve;
    });

    return {
        promise,
        resolve: (value: T) => {
            resolve?.(value);
        },
    };
}

async function flushMicrotasks(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('useAudioPlaybackEngines', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        spotifyPlaybackMocks.options = null;
        vi.useRealTimers();
        vi.clearAllMocks();
    });

    it('starts a switched Spotify track from the stored position instead of stale visible progress', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'local', spotifyUri: null }),
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 41);

        const currentTime = ref(44);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            player.playNext();
            await playbackEngines.startCurrentPlayback();

            expect(currentTime.value).toBe(0);
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledWith(
                spotifyUri,
                0,
                expect.objectContaining({ shouldContinue: expect.any(Function) }),
            );
        } finally {
            playbackEngines.teardown();
        }
    });

    it('reuses the connected Spotify device without pausing it between Spotify tracks', async () => {
        const firstUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const nextUri = 'spotify:track:2A3B4C5D6E7F8G9H0I1J2K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'spotify', spotifyUri: firstUri }),
            testTrack(91, { source: 'spotify', spotifyUri: nextUri }),
        ], 41);

        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            await playbackEngines.startCurrentPlayback();
            spotifyPlaybackMocks.pause.mockClear();
            spotifyPlaybackMocks.destroy.mockClear();
            spotifyPlaybackMocks.play.mockClear();

            player.playNext();
            await playbackEngines.startCurrentPlayback();

            expect(spotifyPlaybackMocks.pause).not.toHaveBeenCalled();
            expect(spotifyPlaybackMocks.destroy).not.toHaveBeenCalled();
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledOnce();
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledWith(
                nextUri,
                0,
                expect.objectContaining({ shouldContinue: expect.any(Function) }),
            );
        } finally {
            playbackEngines.teardown();
        }
    });

    it('pauses and preserves a confirmed Spotify device across a native track handoff', async () => {
        const firstUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const nextUri = 'spotify:track:2A3B4C5D6E7F8G9H0I1J2K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'spotify', spotifyUri: firstUri }),
            testTrack(61, { source: 'local', spotifyUri: null }),
            testTrack(91, { source: 'spotify', spotifyUri: nextUri }),
        ], 41);

        const audioElement = {
            currentTime: 0,
            pause: vi.fn(),
            play: vi.fn().mockResolvedValue(undefined),
        } as unknown as HTMLAudioElement;
        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(audioElement), currentTime, mediaDuration, durationSeconds);

        try {
            await playbackEngines.startCurrentPlayback();
            spotifyPlaybackMocks.pause.mockClear();
            spotifyPlaybackMocks.destroy.mockClear();
            spotifyPlaybackMocks.play.mockClear();

            player.playNext();
            await playbackEngines.startCurrentPlayback();

            expect(spotifyPlaybackMocks.pause).toHaveBeenCalledOnce();
            expect(spotifyPlaybackMocks.destroy).not.toHaveBeenCalled();
            expect(audioElement.play).toHaveBeenCalledOnce();

            player.playNext();
            await playbackEngines.startCurrentPlayback();

            expect(spotifyPlaybackMocks.destroy).not.toHaveBeenCalled();
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledOnce();
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledWith(
                nextUri,
                0,
                expect.objectContaining({ shouldContinue: expect.any(Function) }),
            );
        } finally {
            playbackEngines.teardown();
        }
    });

    it('does not start native or Spotify playback while this instance is observing', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'local', spotifyUri: null }),
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 41);

        const audioElement = {
            currentTime: 0,
            pause: vi.fn(),
            play: vi.fn().mockResolvedValue(undefined),
        } as unknown as HTMLAudioElement;
        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const isPlaybackOwner = ref(false);
        const playbackEngines = useAudioPlaybackEngines(player, ref(audioElement), currentTime, mediaDuration, durationSeconds, {
            isPlaybackOwner,
        });

        try {
            await playbackEngines.startCurrentPlayback();

            expect(audioElement.play).not.toHaveBeenCalled();
            expect(audioElement.pause).toHaveBeenCalled();

            player.playNext();
            await playbackEngines.startCurrentPlayback();

            expect(spotifyPlaybackMocks.play).not.toHaveBeenCalled();
        } finally {
            playbackEngines.teardown();
        }
    });

    it('stops active output immediately when ownership is lost', async () => {
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(41)], 41);

        const audioElement = {
            currentTime: 0,
            pause: vi.fn(),
            play: vi.fn().mockResolvedValue(undefined),
        } as unknown as HTMLAudioElement;
        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const isPlaybackOwner = ref(true);
        const playbackEngines = useAudioPlaybackEngines(player, ref(audioElement), currentTime, mediaDuration, durationSeconds, {
            isPlaybackOwner,
        });

        try {
            await playbackEngines.startCurrentPlayback();
            expect(audioElement.play).toHaveBeenCalledTimes(1);

            isPlaybackOwner.value = false;
            await playbackEngines.startCurrentPlayback();

            expect(audioElement.pause).toHaveBeenCalled();
        } finally {
            playbackEngines.teardown();
        }
    });

    it('ignores stale paused Spotify snapshots while a new start is pending', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'local', spotifyUri: null }),
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 41);

        const currentTime = ref(58);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            player.playNext();
            await playbackEngines.startCurrentPlayback();

            spotifyPlaybackMocks.options?.onStateChange?.({
                durationMs: 180000,
                paused: true,
                positionMs: 58000,
                trackUri: spotifyUri,
            });

            expect(currentTime.value).toBe(0);
            expect(player.playbackPositionSeconds.value).toBe(0);
            expect(spotifyPlaybackMocks.seek).not.toHaveBeenCalled();
        } finally {
            playbackEngines.teardown();
        }
    });

    it('hard tears down a stale Spotify start before switching to local playback', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const spotifyStart = createDeferred<{
            durationMs: number;
            paused: boolean;
            positionMs: number;
            trackUri: string | null;
        }>();
        spotifyPlaybackMocks.play.mockReturnValueOnce(spotifyStart.promise);
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, { source: 'spotify', spotifyUri }),
            testTrack(41, { source: 'local', spotifyUri: null }),
        ], 91);

        const audioElement = {
            currentTime: 0,
            pause: vi.fn(),
            play: vi.fn().mockResolvedValue(undefined),
        } as unknown as HTMLAudioElement;
        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(audioElement), currentTime, mediaDuration, durationSeconds);

        try {
            const staleSpotifyStart = playbackEngines.startCurrentPlayback();
            await flushMicrotasks();
            expect(spotifyPlaybackMocks.play).toHaveBeenCalledWith(
                spotifyUri,
                0,
                expect.objectContaining({ shouldContinue: expect.any(Function) }),
            );

            player.playNext();
            await playbackEngines.startCurrentPlayback();

            const pauseCallsAfterLocalStart = spotifyPlaybackMocks.pause.mock.calls.length;
            expect(spotifyPlaybackMocks.destroy).toHaveBeenCalledTimes(1);
            expect(audioElement.play).toHaveBeenCalledTimes(1);
            expect(player.currentTrackId.value).toBe(41);

            spotifyStart.resolve({
                durationMs: 180000,
                paused: false,
                positionMs: 0,
                trackUri: spotifyUri,
            });
            await staleSpotifyStart;

            expect(spotifyPlaybackMocks.pause).toHaveBeenCalledTimes(pauseCallsAfterLocalStart + 1);
            expect(player.currentTrackId.value).toBe(41);
            expect(player.isPlaying.value).toBe(true);
        } finally {
            playbackEngines.teardown();
        }
    });

    it('keeps ignoring stale paused Spotify snapshots after a fresh start event', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, { source: 'local', spotifyUri: null }),
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 41);

        const currentTime = ref(58);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            player.playNext();
            await playbackEngines.startCurrentPlayback();

            spotifyPlaybackMocks.options?.onStateChange?.({
                durationMs: 180000,
                paused: false,
                positionMs: 0,
                trackUri: spotifyUri,
            });
            spotifyPlaybackMocks.options?.onStateChange?.({
                durationMs: 180000,
                paused: true,
                positionMs: 58000,
                trackUri: spotifyUri,
            });

            expect(currentTime.value).toBe(0);
            expect(player.playbackPositionSeconds.value).toBe(0);
            expect(player.isPlaying.value).toBe(true);
        } finally {
            playbackEngines.teardown();
        }
    });

    it('starts visible Spotify progress when playback is confirmed without an SDK event', async () => {
        vi.useFakeTimers();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        spotifyPlaybackMocks.currentState.mockResolvedValue(null);
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 91);

        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            await playbackEngines.startCurrentPlayback();
            await vi.advanceTimersByTimeAsync(3500);

            expect(currentTime.value).toBeGreaterThan(0);
            expect(player.playbackPositionSeconds.value).toBeGreaterThan(0);
        } finally {
            playbackEngines.teardown();
        }
    });

    it('corrects confirmed Spotify progress back to authoritative snapshots', async () => {
        vi.useFakeTimers();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        spotifyPlaybackMocks.currentState.mockResolvedValue(null);
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 91);

        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(player, ref(null), currentTime, mediaDuration, durationSeconds);

        try {
            await playbackEngines.startCurrentPlayback();

            spotifyPlaybackMocks.options?.onStateChange?.({
                durationMs: 180000,
                paused: false,
                positionMs: 0,
                trackUri: spotifyUri,
            });
            await vi.advanceTimersByTimeAsync(4000);

            expect(currentTime.value).toBeGreaterThanOrEqual(3.9);

            spotifyPlaybackMocks.options?.onStateChange?.({
                durationMs: 180000,
                paused: false,
                positionMs: 1000,
                trackUri: spotifyUri,
            });

            expect(currentTime.value).toBe(1);
            expect(player.playbackPositionSeconds.value).toBe(1);
        } finally {
            playbackEngines.teardown();
        }
    });

    it('reports Spotify authentication failures and reverts playback state', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const notifySpotifyAuthenticationError = vi.fn();
        spotifyPlaybackMocks.play.mockRejectedValueOnce(spotifyPlaybackMocks.authenticationError);

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 91);

        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(
            player,
            ref(null),
            currentTime,
            mediaDuration,
            durationSeconds,
            { onSpotifyAuthenticationError: notifySpotifyAuthenticationError },
        );

        try {
            await playbackEngines.startCurrentPlayback();

            expect(notifySpotifyAuthenticationError).toHaveBeenCalledWith('Spotify is not connected for this account.');
            expect(player.isPlaying.value).toBe(false);
        } finally {
            playbackEngines.teardown();
        }
    });

    it('applies volume changes to active Spotify playback', async () => {
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const volume = ref(0.7);
        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, { source: 'spotify', spotifyUri }),
        ], 91);

        const currentTime = ref(0);
        const mediaDuration = ref(180);
        const durationSeconds = computed(() => mediaDuration.value || (player.currentTrack.value?.durationSeconds ?? 0));
        const playbackEngines = useAudioPlaybackEngines(
            player,
            ref(null),
            currentTime,
            mediaDuration,
            durationSeconds,
            { volume },
        );

        try {
            await playbackEngines.startCurrentPlayback();
            volume.value = 0.25;
            playbackEngines.setSpotifyVolume(volume.value);

            expect(spotifyPlaybackMocks.setVolume).toHaveBeenCalledWith(0.25);
        } finally {
            playbackEngines.teardown();
        }
    });
});
