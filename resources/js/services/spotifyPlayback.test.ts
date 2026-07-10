import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSpotifyPlaybackController } from './spotifyPlayback';

type SpotifyReadyEvent = {
    device_id: string;
};

type SpotifyPlaybackState = {
    duration: number;
    paused: boolean;
    position: number;
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

const spotifyPlayerInstances: MockSpotifyPlayer[] = [];

class MockSpotifyPlayer {
    listeners: ListenerMap = {};

    activateElement = vi.fn().mockResolvedValue(undefined);

    disconnect = vi.fn();

    getCurrentState = vi.fn().mockResolvedValue(null);

    pause = vi.fn().mockResolvedValue(undefined);

    seek = vi.fn().mockResolvedValue(undefined);

    setVolume = vi.fn().mockResolvedValue(undefined);

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
}

function installSpotifySdkMock(): void {
    spotifyPlayerInstances.splice(0);
    window.Spotify = {
        Player: MockSpotifyPlayer,
    };
}

function jsonResponse(payload: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    } as Response;
}

function emptyResponse(status = 204): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => ({}),
    } as Response;
}

function spotifyPlaybackResponse(uri: string, positionMs = 0): Response {
    return jsonResponse({
        device: { id: 'atlas-browser-device', is_active: true },
        is_playing: true,
        item: {
            duration_ms: 10000,
            uri,
        },
        progress_ms: positionMs,
    });
}

function spotifyDeviceErrorResponse(): Response {
    return jsonResponse({
        error: {
            message: 'Device not found',
        },
    }, 404);
}

function runTimersImmediately(): void {
    vi.spyOn(window, 'setTimeout').mockImplementation((handler: TimerHandler) => {
        if (typeof handler === 'function') {
            handler();
        }

        return 0;
    });
}

describe('Spotify playback service', () => {
    afterEach(() => {
        delete window.Spotify;
        spotifyPlayerInstances.splice(0);
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('starts playback on the SDK device without waiting for Spotify Connect device listing', async () => {
        installSpotifySdkMock();
        const spotifyUri = 'spotify:track:direct-playback';
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                throw new Error('Spotify Connect device listing should not block direct playback.');
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                expect(init?.method).toBe('PUT');

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            paused: false,
            trackUri: spotifyUri,
        });

        expect(fetchMock).not.toHaveBeenCalledWith(
            'https://api.spotify.com/v1/me/player/devices',
            expect.anything(),
        );
    });

    it('retries the same SDK device while Spotify Connect registers it', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:device-registration-race';
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                playAttempts++;

                return playAttempts < 3 ? spotifyDeviceErrorResponse() : emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.disconnect).not.toHaveBeenCalled();
        expect(playAttempts).toBe(3);
    });

    it('reinitializes the SDK player once after a transient playback startup failure', async () => {
        installSpotifySdkMock();
        const spotifyUri = 'spotify:track:retry-startup';
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                playAttempts++;

                return playAttempts === 1
                    ? jsonResponse({ error: { message: 'Service unavailable' } }, 500)
                    : emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(playAttempts).toBe(2);
    });

    it('disconnects the SDK player when playback startup still fails after retry', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:still-failing';
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                playAttempts++;

                return spotifyDeviceErrorResponse();
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).rejects.toThrow('Device not found');

        expect(spotifyPlayerInstances).toHaveLength(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(spotifyPlayerInstances[1]?.disconnect).toHaveBeenCalledOnce();
        expect(playAttempts).toBeGreaterThan(2);
    });
});
