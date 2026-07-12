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
    not_ready?: (event: SpotifyReadyEvent) => void;
    player_state_changed?: (state: SpotifyPlaybackState | null) => void;
};

const spotifyPlayerInstances: MockSpotifyPlayer[] = [];

class MockSpotifyPlayer {
    listeners: ListenerMap = {};

    readonly deviceId: string;

    activateElement = vi.fn().mockResolvedValue(undefined);

    disconnect = vi.fn();

    getCurrentState = vi.fn().mockResolvedValue(null);

    pause = vi.fn().mockResolvedValue(undefined);

    seek = vi.fn().mockResolvedValue(undefined);

    setVolume = vi.fn().mockResolvedValue(undefined);

    constructor() {
        this.deviceId = spotifyPlayerInstances.length === 0
            ? 'atlas-browser-device'
            : `atlas-browser-device-${spotifyPlayerInstances.length + 1}`;
        spotifyPlayerInstances.push(this);
    }

    addListener(event: 'ready' | 'not_ready', callback: (event: SpotifyReadyEvent) => void): boolean;
    addListener(event: 'player_state_changed', callback: (state: SpotifyPlaybackState | null) => void): boolean;
    addListener(event: keyof ListenerMap, callback: NonNullable<ListenerMap[keyof ListenerMap]>): boolean {
        this.listeners[event] = callback as never;

        return true;
    }

    connect(): Promise<boolean> {
        this.listeners.ready?.({ device_id: this.deviceId });

        return Promise.resolve(true);
    }

    emitNotReady(): void {
        this.listeners.not_ready?.({ device_id: this.deviceId });
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

function spotifyPlaybackResponse(uri: string, positionMs = 0, deviceId = 'atlas-browser-device'): Response {
    return jsonResponse({
        device: { id: deviceId, is_active: true },
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

function runTimersImmediately(onDelay?: (milliseconds: number) => void): void {
    vi.spyOn(window, 'setTimeout').mockImplementation((handler: TimerHandler, milliseconds?: number) => {
        onDelay?.(milliseconds ?? 0);

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
                return spotifyPlaybackResponse(
                    spotifyUri,
                    0,
                    spotifyPlayerInstances.at(-1)?.deviceId,
                );
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

    it('keeps the same SDK device alive beyond the initial registration window', async () => {
        installSpotifySdkMock();
        const retryDelays: number[] = [];
        runTimersImmediately((milliseconds) => retryDelays.push(milliseconds));
        const spotifyUri = 'spotify:track:device-registration-race';
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                playAttempts++;

                return playAttempts < 7 ? spotifyDeviceErrorResponse() : emptyResponse();
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
        expect(playAttempts).toBe(7);
        expect(retryDelays.reduce((total, milliseconds) => total + milliseconds, 0)).toBeGreaterThan(5000);
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

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device')) {
                playAttempts++;

                return playAttempts === 1
                    ? jsonResponse({ error: { message: 'Service unavailable' } }, 500)
                    : emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(
                    spotifyUri,
                    0,
                    spotifyPlayerInstances.at(-1)?.deviceId,
                );
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

    it('reconnects with a fresh device id after the SDK reports the current device not ready', async () => {
        installSpotifySdkMock();
        const firstUri = 'spotify:track:first-device';
        const nextUri = 'spotify:track:fresh-device';
        let activeDeviceId = '';
        let activeUri = '';
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                activeDeviceId = decodeURIComponent(url.split('device_id=')[1] ?? '');
                activeUri = (JSON.parse(String(init?.body)) as { uris: string[] }).uris[0] ?? '';

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(activeUri, 0, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();
        await controller.play(firstUri, 0);

        const firstPlayer = spotifyPlayerInstances[0]!;
        firstPlayer.emitNotReady();

        await expect(controller.play(nextUri, 0)).resolves.toMatchObject({
            trackUri: nextUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(2);
        expect(firstPlayer.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
        expect(fetchMock).toHaveBeenCalledWith(
            'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device-2',
            expect.anything(),
        );
    });

    it('reconnects once when a previously confirmed device becomes stale between tracks', async () => {
        installSpotifySdkMock();
        const firstUri = 'spotify:track:confirmed-device';
        const nextUri = 'spotify:track:stale-device-recovery';
        let activeDeviceId = '';
        let activeUri = '';
        let staleDeviceAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                const requestedDeviceId = decodeURIComponent(url.split('device_id=')[1] ?? '');
                const requestedUri = (JSON.parse(String(init?.body)) as { uris: string[] }).uris[0] ?? '';

                if (requestedDeviceId === 'atlas-browser-device' && requestedUri === nextUri) {
                    staleDeviceAttempts++;

                    return spotifyDeviceErrorResponse();
                }

                activeDeviceId = requestedDeviceId;
                activeUri = requestedUri;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(activeUri, 0, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();
        await controller.play(firstUri, 0);

        await expect(controller.play(nextUri, 0)).resolves.toMatchObject({
            trackUri: nextUri,
        });

        expect(staleDeviceAttempts).toBe(1);
        expect(spotifyPlayerInstances).toHaveLength(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });

    it('keeps a registered SDK player available after Spotify exceeds the registration timeout', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:still-failing';
        let playAttempts = 0;
        let spotifyDeviceRegistered = false;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                playAttempts++;

                return spotifyDeviceRegistered ? emptyResponse() : spotifyDeviceErrorResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();

        await expect(controller.play(spotifyUri, 0)).rejects.toThrow('Device not found');

        const failedRegistrationAttempts = playAttempts;
        spotifyDeviceRegistered = true;

        await expect(controller.play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.disconnect).not.toHaveBeenCalled();
        expect(failedRegistrationAttempts).toBeGreaterThan(1);
        expect(playAttempts).toBe(failedRegistrationAttempts + 1);
    });
});
