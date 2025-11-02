import { flushMicrotasks } from '@/test/utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

function overrideAudioWithTracker() {
    const Base: any = (globalThis as any).Audio;
    class TrackedAudio extends Base {
        static last: any;
        constructor() {
            super();
            (TrackedAudio as any).last = this;
        }
    }
    (globalThis as any).Audio = TrackedAudio as any;
    return () => {
        (globalThis as any).Audio = Base;
    };
}

async function importStore() {
    const mod = await import('@/stores/audio');
    return mod as unknown as typeof import('@/stores/audio');
}

describe('audio store', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        // Avoid network errors affecting playback flow timing
        vi.spyOn(axios, 'get').mockResolvedValue({ data: { duration_ms: 180000, duration: 180 } } as any);
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    it('resets seekbar to 0 on next/previous (local engine)', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        // Queue two local tracks and start at first
        audioActions.setQueueAndPlay(
            [
                { id: 1, duration: 300 },
                { id: 2, duration: 300 },
            ] as any[],
            1,
        );
        await flushMicrotasks();

        // Simulate being at 2:00, ensure duration set to allow seeking
        audioStore.duration = 300;
        audioActions.setCurrentTime(120);
        expect(audioStore.currentTime).toBe(120);

        // Next -> should reset to 0 immediately
        audioActions.next();
        expect(audioStore.currentTime).toBe(0);

        // Simulate being at 2:00 again on second track
        audioStore.duration = 300;
        audioActions.setCurrentTime(120);
        expect(audioStore.currentTime).toBe(120);

        // Previous -> should reset to 0 immediately
        audioActions.previous();
        expect(audioStore.currentTime).toBe(0);

        restoreAudio();
    });

    it('Spotify pause → resume resumes at last position; next resets seekbar to 0', async () => {
        vi.resetModules();
        // Mock Spotify SDK wrapper with new API (ensure + setStateListener)
        vi.doMock('@/sdk/spotifyPlayer', () => {
            return {
                spotifyPlayer: {
                    ensure: vi.fn().mockResolvedValue(undefined),
                    setStateListener: vi.fn().mockImplementation(() => {}),
                    playUri: vi.fn().mockResolvedValue(undefined),
                    pause: vi.fn().mockResolvedValue(undefined),
                    resume: vi.fn().mockResolvedValue(undefined),
                    seek: vi.fn().mockResolvedValue(undefined),
                    setVolume: vi.fn().mockResolvedValue(undefined),
                    getCurrentState: vi.fn().mockResolvedValue(null),
                },
            };
        });

        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        // Queue Spotify items (mark engine explicitly)
        const items = [
            { id: 101, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:101', duration_ms: 180000 } } },
            { id: 102, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:102', duration_ms: 180000 } } },
        ];
        audioActions.setQueueAndPlay(items as any[], 101);
        await flushMicrotasks();

        // At t=120s
        audioActions.setCurrentTime(120);
        expect(audioStore.currentTime).toBe(120);

        // Pause then resume -> should keep position
        audioActions.pause();
        audioActions.play();
        await flushMicrotasks();
        expect(audioStore.currentTime).toBe(120);

        // Next -> must reset to 0 immediately
        audioActions.next();
        expect(audioStore.currentTime).toBe(0);

        restoreAudio();
    });

    it('Spotify external track change starts from 0 (position_ms=0)', async () => {
        vi.resetModules();
        const playUri = vi.fn().mockResolvedValue(undefined);
        vi.doMock('@/sdk/spotifyPlayer', () => ({
            spotifyPlayer: {
                ensure: vi.fn().mockResolvedValue(undefined),
                setStateListener: vi.fn().mockImplementation(() => {}),
                playUri,
                pause: vi.fn().mockResolvedValue(undefined),
                resume: vi.fn().mockResolvedValue(undefined),
                seek: vi.fn().mockResolvedValue(undefined),
                setVolume: vi.fn().mockResolvedValue(undefined),
                getCurrentState: vi.fn().mockResolvedValue(null),
            },
        }));
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        const items = [
            { id: 201, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:201', duration_ms: 200000 } } },
            { id: 202, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:202', duration_ms: 200000 } } },
        ];
        audioActions.setQueueAndPlay(items as any[], 201);
        await flushMicrotasks();

        // Simulate user has progressed to 2:00 on first track
        audioActions.setCurrentTime(120);

        // External queue change (e.g., membership event): switch to next track directly
        audioStore.currentIndex = 1;
        audioStore.currentTrack = (audioStore.queue as any[])[1];
        // Do not call next() to mimic external change; call play() directly
        audioActions.play();
        await flushMicrotasks();

        // Expect playUri to be called for the new uri with position_ms 0
        expect(playUri).toHaveBeenCalled();
        const args = playUri.mock.calls.at(-1);
        expect(args?.[0]).toBe('spotify:track:202');
        expect(args?.[1]).toBe(0);

        restoreAudio();
    });

    it('Spotify seek failure keeps playback position', async () => {
    vi.resetModules();
    const errSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const restoreAudio = overrideAudioWithTracker();
        try {
            const seek = vi.fn().mockRejectedValue(new Error('Seek forbidden'));
            const getCurrentState = vi.fn().mockResolvedValue({ position: 45000, duration: 180000, paused: false });
            vi.doMock('@/sdk/spotifyPlayer', () => ({
                spotifyPlayer: {
                    ensure: vi.fn().mockResolvedValue(undefined),
                    setStateListener: vi.fn().mockImplementation(() => {}),
                    playUri: vi.fn().mockResolvedValue(undefined),
                    pause: vi.fn().mockResolvedValue(undefined),
                    resume: vi.fn().mockResolvedValue(undefined),
                    seek,
                    setVolume: vi.fn().mockResolvedValue(undefined),
                    getCurrentState,
                },
            }));
            const { audioActions, audioStore } = await importStore();

            const items = [{ id: 301, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:301', duration_ms: 180000 } } }];
            audioActions.setQueueAndPlay(items as any[], 301);
            await flushMicrotasks();

            audioStore.isPlaying = true;
            audioStore.currentTime = 30;
            audioStore.duration = 180;

            audioActions.setCurrentTime(60);

            await flushMicrotasks();
            expect(audioStore.currentTime).toBeCloseTo(45);
            expect(audioStore.duration).toBeCloseTo(180);
            expect(seek).toHaveBeenCalled();
            expect(getCurrentState).toHaveBeenCalled();
            expect(errSpy.mock.calls.length).toBeGreaterThan(0);
        } finally {
            restoreAudio();
            errSpy.mockRestore();
        }
    });

    it('prompts on Spotify 403 and lets the user choose skip or stop', async () => {
    vi.resetModules();
    const errSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const restoreAudio = overrideAudioWithTracker();
        try {
            const pause = vi.fn().mockResolvedValue(undefined);
            const resume = vi.fn().mockResolvedValue(undefined);
            const playUri = vi
                .fn()
                .mockRejectedValueOnce(Object.assign(new Error('Premium required'), { status: 403, body: JSON.stringify({ error: { message: 'Premium required' } }) }))
                .mockResolvedValueOnce(undefined);
            vi.doMock('@/sdk/spotifyPlayer', () => ({
                spotifyPlayer: {
                    ensure: vi.fn().mockResolvedValue(undefined),
                    setStateListener: vi.fn().mockImplementation(() => {}),
                    playUri,
                    pause,
                    resume,
                    seek: vi.fn().mockResolvedValue(undefined),
                    setVolume: vi.fn().mockResolvedValue(undefined),
                    getCurrentState: vi.fn().mockResolvedValue(null),
                },
            }));

            const { audioActions, audioStore } = await importStore();

            const items = [
                { id: 501, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:501', duration_ms: 180000 } } },
                { id: 502, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:502', duration_ms: 180000 } } },
            ];

            audioActions.setQueueAndPlay(items as any[], 501);
            await flushMicrotasks();

            await vi.waitFor(() => {
                expect(audioStore.spotifyPlaybackError).not.toBeNull();
            });
            expect(audioStore.spotifyPlaybackError?.trackId).toBe(501);
            expect(pause).toHaveBeenCalled();

            await audioActions.resolveSpotifyPlaybackError('skip');
            await flushMicrotasks();

            expect(audioStore.spotifyPlaybackError).toBeNull();
            expect(errSpy.mock.calls.length).toBeGreaterThan(0);
            restoreAudio();

            // Continue: second 403 case on resume for next track (device not available)
            expect((audioStore.currentTrack as any)?.id).toBe(502);

            resume.mockRejectedValueOnce(
                Object.assign(new Error('Device not available'), {
                    status: 403,
                    body: JSON.stringify({ error: { message: 'Device not available' } }),
                }),
            );

            audioActions.pause();
            await flushMicrotasks();

            await audioActions.play();
            await flushMicrotasks();

            await vi.waitFor(() => {
                expect(audioStore.spotifyPlaybackError).not.toBeNull();
            });
            expect(audioStore.spotifyPlaybackError?.trackId).toBe(502);

            await audioActions.resolveSpotifyPlaybackError('stop');
            await flushMicrotasks();

            expect(audioStore.spotifyPlaybackError).toBeNull();
            expect(audioStore.isPlaying).toBe(false);
        } finally {
            errSpy.mockRestore();
        }
    });

    it('skips tracks missing duration metadata', async () => {
        vi.resetModules();
        const playUri = vi.fn().mockResolvedValue(undefined);
        vi.doMock('@/sdk/spotifyPlayer', () => ({
            spotifyPlayer: {
                ensure: vi.fn().mockResolvedValue(undefined),
                setStateListener: vi.fn().mockImplementation(() => {}),
                playUri,
                pause: vi.fn().mockResolvedValue(undefined),
                resume: vi.fn().mockResolvedValue(undefined),
                seek: vi.fn().mockResolvedValue(undefined),
                setVolume: vi.fn().mockResolvedValue(undefined),
                getCurrentState: vi.fn().mockResolvedValue(null),
            },
        }));

        const axiosGetMock = vi.mocked(axios.get);
        axiosGetMock.mockReset();
        axiosGetMock.mockResolvedValueOnce({ data: {} } as any);
        axiosGetMock.mockResolvedValueOnce({ data: {} } as any);
        axiosGetMock.mockResolvedValue({ data: { duration_ms: 240000, duration: 240 } } as any);

        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        const items = [
            { id: 401, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:401' } } },
            { id: 402, _engine: 'spotify', listing_metadata: { track: { uri: 'spotify:track:402', duration_ms: 240000 } } },
        ];

        audioActions.setQueueAndPlay(items as any[], 401);
        await flushMicrotasks();
        await flushMicrotasks();

        await vi.waitFor(() => {
            expect((audioStore.currentTrack as any)?.id).toBe(402);
        });
        await vi.waitFor(() => {
            expect(audioStore.queue).toHaveLength(1);
        });
        await vi.waitFor(() => {
            expect(playUri).toHaveBeenCalledWith(
                'spotify:track:402',
                0,
                expect.objectContaining({
                    initialVolume: 1,
                    skipActivation: false,
                }),
            );
        });

        restoreAudio();
    });

    it('setQueueAndPlay enqueues, selects, loads and plays', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        const items = [
            { id: 1, duration_ms: 120000 },
            { id: 2, duration_ms: 150000 },
            { id: 3, duration_ms: 180000 },
        ];
        audioActions.setQueueAndPlay(items as any[], 2);
        await flushMicrotasks();
        await flushMicrotasks();

        expect(audioStore.queue.length).toBe(3);
        expect(audioStore.currentIndex).toBe(1);
        expect((audioStore.currentTrack as any)?.id).toBe(2);
        // Ensure HTMLAudioElement created and src set
        const last = (globalThis as any).Audio.last;
        expect(last).toBeTruthy();
        expect(String(last.src)).toContain('/audio/stream/2');
        // Playing flag should be true
        expect(audioStore.isPlaying).toBe(true);

        restoreAudio();
    });

    it('pause toggles isPlaying false', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        const items = [
            { id: 1, duration: 180 },
            { id: 2, duration: 200 },
        ];
        audioActions.setQueueAndPlay(items as any[], 1);
        await flushMicrotasks();
        await flushMicrotasks();
        expect(audioStore.isPlaying).toBe(true);
        audioActions.pause();
        expect(audioStore.isPlaying).toBe(false);

        restoreAudio();
    });

    it('next and repeat behaviors', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        audioActions.setQueueAndPlay(
            [
                { id: 1, duration: 240 },
                { id: 2, duration: 240 },
            ] as any[],
            1,
        );
        await flushMicrotasks();
        await flushMicrotasks();

        // repeat off (default)
        audioActions.next();
        await flushMicrotasks();
        expect((audioStore.currentTrack as any)?.id).toBe(2);
        audioActions.next();
        // end of queue reached => stop playing
        expect(audioStore.isPlaying).toBe(false);

        // repeat one
        audioActions.setQueueAndPlay(
            [
                { id: 10, duration_ms: 210000 },
                { id: 20, duration_ms: 210000 },
            ] as any[],
            10,
        );
        audioActions.setRepeatMode('one');
        audioActions.next();
        await flushMicrotasks();
        expect((audioStore.currentTrack as any)?.id).toBe(10);
        expect(audioStore.isPlaying).toBe(true);

        // repeat all (wrap)
        audioActions.setRepeatMode('all');
        // jump to end
        audioStore.currentIndex = 1;
        audioStore.currentTrack = (audioStore.queue as any[])[1];
        audioActions.next();
        await flushMicrotasks();
        expect(audioStore.currentIndex).toBe(0);
        expect((audioStore.currentTrack as any)?.id).toBe(10);

        restoreAudio();
    });

    it('previous wraps with repeat all', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        audioActions.setQueue([
            { id: 1, duration: 150 },
            { id: 2, duration: 150 },
        ] as any[]);
        audioActions.setRepeatMode('all');
        // at start
        audioStore.currentIndex = 0;
        audioStore.currentTrack = (audioStore.queue as any[])[0];
        audioActions.previous();
        expect(audioStore.currentIndex).toBe(1);
        expect((audioStore.currentTrack as any)?.id).toBe(2);

        restoreAudio();
    });

    it('setCurrentTime clamps within [0, duration]', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        audioActions.setQueueAndPlay([{ id: 1, duration: 200 }] as any[], 1);
        // Fake metadata load already set duration in setup to 180, but ensure it's > 0
        audioStore.duration = 120;

        audioActions.setCurrentTime(200);
        expect(audioStore.currentTime).toBe(120);
        audioActions.setCurrentTime(-5);
        expect(audioStore.currentTime).toBe(0);

        restoreAudio();
    });

    it('volume persistence via localStorage and clamping', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        audioActions.setQueueAndPlay([{ id: 1, duration_ms: 250000 }] as any[], 1);

        audioActions.setVolume(2);
        expect(audioStore.volume).toBe(1);
        expect(window.localStorage.getItem('atlas:volume')).toBe('1');

        // Pre-set and re-init
        window.localStorage.setItem('atlas:volume', '0.25');
        audioActions.initVolumeFromStorage();
        expect(audioStore.volume).toBeCloseTo(0.25);

        restoreAudio();
    });

    it('shuffleQueue keeps current first and retains items', async () => {
        vi.resetModules();
        const restoreAudio = overrideAudioWithTracker();
        const { audioActions, audioStore } = await importStore();

        audioActions.setQueueAndPlay(
            [
                { id: 1, duration: 160 },
                { id: 2, duration: 160 },
                { id: 3, duration: 160 },
            ] as any[],
            2,
        );
        audioActions.shuffleQueue();
        expect((audioStore.queue[0] as any).id).toBe(2);
        const ids = new Set((audioStore.queue as any[]).map((x) => x.id));
        expect(ids).toEqual(new Set([1, 2, 3]));

        restoreAudio();
    });
});
