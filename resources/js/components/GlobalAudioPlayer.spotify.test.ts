import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

const mountedWrappers: VueWrapper[] = [];

type SpotifyReadyEvent = {
    device_id: string;
};

type SpotifyPlaybackState = {
    paused: boolean;
    position: number;
    duration: number;
    track_window?: {
        current_track?: {
            uri?: string;
        } | null;
    };
};

type ListenerMap = {
    ready?: (event: SpotifyReadyEvent) => void;
    player_state_changed?: (state: SpotifyPlaybackState | null) => void;
};

type MockSpotifyPlayerHandle = {
    activateElement: ReturnType<typeof vi.fn>;
    listeners: ListenerMap;
    pause: ReturnType<typeof vi.fn>;
    seek: ReturnType<typeof vi.fn>;
};

type SpotifyApiPlaybackMock = {
    device?: {
        id: string;
        is_active: boolean;
    };
    is_playing?: boolean;
    item?: {
        duration_ms?: number;
        uri?: string;
    };
    progress_ms?: number | null;
};

const spotifyPlayerInstances: MockSpotifyPlayerHandle[] = [];
let spotifyCurrentState: SpotifyPlaybackState | null = null;

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: '',
        album: '',
        coverUrl: null,
        duration: `0:${id.toString().padStart(2, '0')}`,
        durationSeconds: id,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

function mountPlayer(): VueWrapper {
    const wrapper = mount(GlobalAudioPlayer);
    mountedWrappers.push(wrapper);

    return wrapper;
}

class MockSpotifyPlayer {
    listeners: ListenerMap = {};

    pause = vi.fn().mockResolvedValue(undefined);

    seek = vi.fn((positionMs: number) => {
        if (spotifyCurrentState) {
            spotifyCurrentState = {
                ...spotifyCurrentState,
                position: positionMs,
            };
        }

        return Promise.resolve();
    });

    activateElement = vi.fn().mockResolvedValue(undefined);

    constructor() {
        spotifyPlayerInstances.push(this);
    }

    addListener(event: 'ready', callback: (event: SpotifyReadyEvent) => void): boolean;
    addListener(event: 'player_state_changed', callback: (state: SpotifyPlaybackState | null) => void): boolean;
    addListener(event: keyof ListenerMap, callback: NonNullable<ListenerMap[keyof ListenerMap]>): boolean {
        this.listeners[event] = callback as never;

        return true;
    }

    connect(): Promise<boolean> {
        this.listeners.ready?.({ device_id: 'atlas-browser-device' });

        return Promise.resolve(true);
    }

    disconnect(): void {}

    getCurrentState(): Promise<SpotifyPlaybackState | null> {
        return Promise.resolve(spotifyCurrentState);
    }
}

function installSpotifySdkMock(): void {
    spotifyPlayerInstances.splice(0);
    spotifyCurrentState = null;

    window.Spotify = {
        Player: MockSpotifyPlayer,
    };
}

function spotifyState(
    spotifyUri: string,
    overrides: Partial<Pick<SpotifyPlaybackState, 'duration' | 'paused' | 'position'>> = {},
): SpotifyPlaybackState {
    return {
        duration: 10000,
        paused: false,
        position: 0,
        track_window: {
            current_track: { uri: spotifyUri },
        },
        ...overrides,
    };
}

function emitSpotifyState(state: SpotifyPlaybackState | null): void {
    spotifyCurrentState = state;
    spotifyPlayerInstances[0]?.listeners.player_state_changed?.(state);
}

function mockSpotifyFetch(
    spotifyUri: string,
    activeDeviceId = 'atlas-browser-device',
    currentPlaybackOverrides: SpotifyApiPlaybackMock = {},
): ReturnType<typeof vi.fn> {
    const { item: currentPlaybackItemOverrides, ...currentPlaybackBaseOverrides } = currentPlaybackOverrides;

    return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = String(input);
        if (url === '/api/spotify/playback-token') {
            return {
                ok: true,
                status: 200,
                json: async () => ({ access_token: 'spotify-access-token' }),
            } as Response;
        }

        if (url === 'https://api.spotify.com/v1/me/player/devices') {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    devices: [{ id: 'atlas-browser-device', is_active: true }],
                }),
            } as Response;
        }

        if (url === 'https://api.spotify.com/v1/me/player' && init?.method !== 'PUT') {
            return {
                ok: true,
                status: 200,
                json: async () => ({
                    device: { id: activeDeviceId, is_active: true },
                    is_playing: true,
                    progress_ms: 0,
                    ...currentPlaybackBaseOverrides,
                    item: {
                        duration_ms: 10000,
                        uri: spotifyUri,
                        ...currentPlaybackItemOverrides,
                    },
                }),
            } as Response;
        }

        return {
            ok: true,
            status: 204,
            json: async () => ({}),
        } as Response;
    });
}

describe('GlobalAudioPlayer Spotify playback', () => {
    afterEach(() => {
        mountedWrappers.splice(0).forEach((wrapper) => wrapper.unmount());
        useGlobalAudioPlayer().clear();
        delete window.Spotify;
        spotifyPlayerInstances.splice(0);
        spotifyCurrentState = null;
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('keeps Spotify playback exclusive from native audio when switching tracks', async () => {
        installSpotifySdkMock();
        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const fetchMock = mockSpotifyFetch(spotifyUri);
        vi.stubGlobal('fetch', fetchMock);

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
                playbackUrl: '/api/files/91/serve',
            }),
            testTrack(41, {
                source: 'local',
                sourceId: null,
                spotifyUri: null,
                playbackUrl: '/api/files/41/serve',
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        expect(wrapper.get('audio').attributes('src')).toBeUndefined();
        expect(spotifyPlayerInstances[0]?.activateElement).toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledWith(
            'https://api.spotify.com/v1/me/player',
            expect.objectContaining({
                body: JSON.stringify({
                    device_ids: ['atlas-browser-device'],
                    play: false,
                }),
                method: 'PUT',
            }),
        );
        expect(fetchMock).toHaveBeenCalledWith(
            'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device',
            expect.objectContaining({
                method: 'PUT',
                body: JSON.stringify({
                    position_ms: 0,
                    uris: [spotifyUri],
                }),
            }),
        );

        await wrapper.get('[aria-label="Next"]').trigger('click');
        await flushPromises();

        expect(spotifyPlayerInstances[0]?.pause).toHaveBeenCalled();
        expect(wrapper.get('audio').attributes('src')).toBe('/api/files/41/serve');
    });

    it('ignores stale Spotify startup failures after rapidly moving to a local track', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri, 'atlas-browser-device', {
            item: {
                uri: 'spotify:track:NOT_THE_REQUESTED_TRACK',
            },
        }));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                spotifyUri,
            }),
            testTrack(41, {
                playbackUrl: '/api/files/41/serve',
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        await wrapper.get('[aria-label="Next"]').trigger('click');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(6000);
        await flushPromises();

        expect(player.currentTrackId.value).toBe(41);
        expect(wrapper.get('audio').attributes('src')).toBe('/api/files/41/serve');
        expect(consoleError).not.toHaveBeenCalledWith('Failed to start Spotify playback:', expect.anything());
    });

    it('smooths Spotify progress between SDK snapshots', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        emitSpotifyState(spotifyState(spotifyUri, { duration: 10000 }));
        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:01');

        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:02');
    });

    it('keeps Spotify visible time on a wall-clock pace when SDK snapshots jump ahead', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        emitSpotifyState(spotifyState(spotifyUri, { duration: 10000 }));
        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:01');

        vi.advanceTimersByTime(500);
        await wrapper.vm.$nextTick();

        emitSpotifyState(spotifyState(spotifyUri, {
            duration: 10000,
            position: 2000,
        }));
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:01');

        vi.advanceTimersByTime(500);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:02');
    });

    it('advances Spotify progress once Web API confirms playback after a local track ends', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(41, {
                duration: '0:01',
                durationSeconds: 1,
                source: 'local',
                sourceId: null,
                spotifyUri: null,
                playbackUrl: '/api/files/41/serve',
            }),
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 41);

        const wrapper = mountPlayer();
        await flushPromises();

        await wrapper.get('audio').trigger('ended');
        await flushPromises(); await flushPromises();

        expect(player.currentTrackId.value).toBe(91);
        expect(wrapper.text()).toContain('0:00');

        await vi.advanceTimersByTimeAsync(2000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:02');

        emitSpotifyState(spotifyState(spotifyUri, { duration: 10000 }));
        await vi.advanceTimersByTimeAsync(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:03');
    });

    it('starts Spotify progress from polling when the SDK event is missed', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises(); await flushPromises();

        spotifyCurrentState = spotifyState(spotifyUri, {
            duration: 10000,
            position: 1000,
        });

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();

        expect(wrapper.text()).toContain('0:01');

        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:02');
    });

    it('starts Spotify progress from Web API polling when the SDK state is missing', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri, 'atlas-browser-device', {
            progress_ms: 1000,
        }));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        vi.advanceTimersByTime(750);
        await flushPromises();

        expect(wrapper.text()).toContain('0:01');

        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:02');
    });

    it('ignores stale Spotify start snapshots from a previous playback position', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:30',
                durationSeconds: 30,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        emitSpotifyState(spotifyState(spotifyUri, {
            duration: 30000,
            position: 25000,
        }));
        await flushPromises();

        expect(wrapper.text()).toContain('0:00');
        expect(spotifyPlayerInstances[0]?.seek).toHaveBeenCalledWith(0);

        emitSpotifyState(spotifyState(spotifyUri, {
            duration: 30000,
            position: 0,
        }));
        vi.advanceTimersByTime(1000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0:01');
    });

    it('auto-plays the next local track when Spotify clears state after ending', async () => {
        vi.useFakeTimers();
        installSpotifySdkMock();

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        vi.stubGlobal('fetch', mockSpotifyFetch(spotifyUri));

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                duration: '0:10',
                durationSeconds: 10,
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
            testTrack(41, {
                source: 'local',
                sourceId: null,
                spotifyUri: null,
                playbackUrl: '/api/files/41/serve',
            }),
        ], 91);

        const wrapper = mountPlayer();
        await flushPromises();

        emitSpotifyState(spotifyState(spotifyUri, {
            duration: 10000,
            position: 0,
        }));
        await wrapper.vm.$nextTick();

        emitSpotifyState(spotifyState(spotifyUri, {
            duration: 10000,
            position: 9500,
        }));
        emitSpotifyState(null);
        await flushPromises();

        expect(player.currentTrackId.value).toBe(41);
        expect(wrapper.get('[data-test="global-audio-player-title"]').text()).toBe('Track 41');
        expect(wrapper.get('audio').attributes('src')).toBe('/api/files/41/serve');
    });

    it('pauses Spotify if the requested track starts on another device', async () => {
        installSpotifySdkMock();
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const spotifyUri = 'spotify:track:1A2B3C4D5E6F7G8H9I0J1K';
        const fetchMock = mockSpotifyFetch(spotifyUri, 'spotify-desktop');
        vi.stubGlobal('fetch', fetchMock);

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([
            testTrack(91, {
                source: 'spotify',
                sourceId: '1A2B3C4D5E6F7G8H9I0J1K',
                spotifyUri,
            }),
        ], 91);

        mountPlayer();
        await flushPromises();

        expect(fetchMock).toHaveBeenCalledWith(
            'https://api.spotify.com/v1/me/player/pause?device_id=spotify-desktop',
            expect.objectContaining({ method: 'PUT' }),
        );
        expect(player.isPlaying.value).toBe(false);
    });
});
