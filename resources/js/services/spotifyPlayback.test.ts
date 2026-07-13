import { afterEach, describe, expect, it, vi } from 'vitest';
import { createSpotifyPlaybackController } from './spotifyPlayback';

type SpotifyReadyEvent = { device_id: string };
type SpotifyErrorEvent = { message: string };
type SpotifyPlaybackState = {
    duration: number;
    paused: boolean;
    position: number;
    track_window?: {
        current_track?: { uri?: string } | null;
    };
};
type ListenerMap = {
    ready?: (event: SpotifyReadyEvent) => void;
    not_ready?: (event: SpotifyReadyEvent) => void;
    player_state_changed?: (state: SpotifyPlaybackState | null) => void;
    initialization_error?: (event: SpotifyErrorEvent) => void;
    authentication_error?: (event: SpotifyErrorEvent) => void;
    account_error?: (event: SpotifyErrorEvent) => void;
    playback_error?: (event: SpotifyErrorEvent) => void;
    autoplay_failed?: (event: SpotifyErrorEvent) => void;
};

const spotifyPlayerInstances: MockSpotifyPlayer[] = [];

class MockSpotifyPlayer {
    listeners: ListenerMap = {};

    deviceId = '';

    activateElement = vi.fn().mockResolvedValue(undefined);

    connect = vi.fn(async () => {
        const connectionNumber = this.connect.mock.calls.length;
        this.deviceId = connectionNumber === 1
            ? 'atlas-browser-device'
            : `atlas-browser-device-${connectionNumber}`;
        this.listeners.ready?.({ device_id: this.deviceId });

        return true;
    });

    disconnect = vi.fn();

    getCurrentState = vi.fn().mockResolvedValue(null);

    pause = vi.fn().mockResolvedValue(undefined);

    seek = vi.fn().mockResolvedValue(undefined);

    setVolume = vi.fn().mockResolvedValue(undefined);

    constructor() {
        spotifyPlayerInstances.push(this);
    }

    addListener(event: 'ready' | 'not_ready', callback: (event: SpotifyReadyEvent) => void): boolean;
    addListener(event: 'player_state_changed', callback: (state: SpotifyPlaybackState | null) => void): boolean;
    addListener(event: 'initialization_error' | 'authentication_error' | 'account_error' | 'playback_error' | 'autoplay_failed', callback: (event: SpotifyErrorEvent) => void): boolean;
    addListener(event: keyof ListenerMap, callback: NonNullable<ListenerMap[keyof ListenerMap]>): boolean {
        this.listeners[event] = callback as never;

        return true;
    }

    emitNotReady(): void {
        this.listeners.not_ready?.({ device_id: this.deviceId });
    }
}

function installSpotifySdkMock(): void {
    spotifyPlayerInstances.splice(0);
    window.Spotify = { Player: MockSpotifyPlayer };
}

function jsonResponse(payload: unknown, status = 200): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => payload,
    } as Response;
}

function emptyResponse(status = 204): Response {
    return jsonResponse({}, status);
}

function spotifyDevicesResponse(deviceId: string, isActive = true): Response {
    return jsonResponse({
        devices: [{ id: deviceId, is_active: isActive, is_restricted: false }],
    });
}

function spotifyPlaybackResponse(uri: string, deviceId: string, positionMs = 0): Response {
    return jsonResponse({
        device: { id: deviceId, is_active: true },
        is_playing: true,
        item: { duration_ms: 10000, uri },
        progress_ms: positionMs,
    });
}

function spotifyDeviceErrorResponse(): Response {
    return jsonResponse({ error: { message: 'Device not found' } }, 404);
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

function requestedDeviceId(url: string): string {
    return decodeURIComponent(url.split('device_id=')[1] ?? '');
}

function requestedUri(init?: RequestInit): string {
    return (JSON.parse(String(init?.body)) as { uris: string[] }).uris[0] ?? '';
}

describe('Spotify playback service', () => {
    afterEach(() => {
        delete window.Spotify;
        spotifyPlayerInstances.splice(0);
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('transfers playback to the SDK device and confirms it active before playing', async () => {
        installSpotifySdkMock();
        const spotifyUri = 'spotify:track:activated-device';
        const requestOrder: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                requestOrder.push('transfer');
                expect(JSON.parse(String(init.body))).toEqual({
                    device_ids: ['atlas-browser-device'],
                    play: false,
                });

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                requestOrder.push('devices');

                return spotifyDevicesResponse('atlas-browser-device');
            }

            if (url === 'https://api.spotify.com/v1/me/player/play?device_id=atlas-browser-device') {
                requestOrder.push('play');

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                requestOrder.push('state');

                return spotifyPlaybackResponse(spotifyUri, 'atlas-browser-device');
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            paused: false,
            trackUri: spotifyUri,
        });

        expect(requestOrder).toEqual(['transfer', 'devices', 'play', 'state']);
    });

    it('waits for Spotify to register the same SDK device before playing', async () => {
        installSpotifySdkMock();
        const retryDelays: number[] = [];
        runTimersImmediately((milliseconds) => retryDelays.push(milliseconds));
        const spotifyUri = 'spotify:track:registration-race';
        let transferAttempts = 0;
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                transferAttempts++;

                return transferAttempts < 7 ? spotifyDeviceErrorResponse() : emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse('atlas-browser-device');
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                playAttempts++;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri, 'atlas-browser-device');
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledOnce();
        expect(spotifyPlayerInstances[0]?.disconnect).not.toHaveBeenCalled();
        expect(transferAttempts).toBe(7);
        expect(playAttempts).toBe(1);
        expect(retryDelays.reduce((total, milliseconds) => total + milliseconds, 0)).toBeGreaterThan(5000);
    });

    it('reconnects the same SDK player once after a transient playback failure', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:retry-startup';
        let activeDeviceId = '';
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                activeDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                playAttempts++;

                return playAttempts === 1
                    ? jsonResponse({ error: { message: 'Service unavailable' } }, 500)
                    : emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledTimes(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
        expect(playAttempts).toBe(2);
    });

    it('reconnects the same player after the SDK reports its device not ready', async () => {
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

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                activeDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                activeDeviceId = requestedDeviceId(url);
                activeUri = requestedUri(init);

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(activeUri, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();
        await controller.play(firstUri, 0);
        const sdkPlayer = spotifyPlayerInstances[0]!;
        sdkPlayer.emitNotReady();

        await expect(controller.play(nextUri, 0)).resolves.toMatchObject({ trackUri: nextUri });

        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(sdkPlayer.connect).toHaveBeenCalledTimes(2);
        expect(sdkPlayer.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });

    it('reconnects once when a confirmed device becomes stale between tracks', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const firstUri = 'spotify:track:confirmed-device';
        const nextUri = 'spotify:track:stale-device-recovery';
        let activeDeviceId = '';
        let activeUri = '';
        let firstDeviceWasActivated = false;
        let staleTransferAttempts = 0;
        let stalePlayAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                const transferDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                if (transferDeviceId === 'atlas-browser-device' && firstDeviceWasActivated) {
                    staleTransferAttempts++;

                    return spotifyDeviceErrorResponse();
                }

                activeDeviceId = transferDeviceId;
                firstDeviceWasActivated = true;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                const nextDeviceId = requestedDeviceId(url);
                const nextRequestedUri = requestedUri(init);

                if (nextDeviceId === 'atlas-browser-device' && nextRequestedUri === nextUri) {
                    stalePlayAttempts++;

                    return spotifyDeviceErrorResponse();
                }

                activeDeviceId = nextDeviceId;
                activeUri = nextRequestedUri;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(activeUri, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();
        await controller.play(firstUri, 0);
        await expect(controller.play(nextUri, 0)).resolves.toMatchObject({ trackUri: nextUri });

        expect(staleTransferAttempts).toBe(1);
        expect(stalePlayAttempts).toBe(0);
        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledTimes(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });

    it('verifies an idle device before resuming and reconnects the same SDK player when it expired', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:resume-after-idle';
        const recoveryStates: boolean[] = [];
        let activeDeviceId = '';
        let firstPlaybackConfirmed = false;
        let staleTransferAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                const transferDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                if (firstPlaybackConfirmed && transferDeviceId === 'atlas-browser-device') {
                    staleTransferAttempts++;

                    return spotifyDeviceErrorResponse();
                }

                activeDeviceId = transferDeviceId;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                if (firstPlaybackConfirmed && activeDeviceId === 'atlas-browser-device') {
                    return jsonResponse({ devices: [] });
                }

                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri, activeDeviceId, firstPlaybackConfirmed ? 4000 : 0);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController({
            onRecoveryStateChange: (isRecovering) => recoveryStates.push(isRecovering),
        });
        await controller.play(spotifyUri, 0);
        firstPlaybackConfirmed = true;
        controller.markDeviceRegistrationStale();

        await expect(controller.play(spotifyUri, 4)).resolves.toMatchObject({ trackUri: spotifyUri });

        expect(staleTransferAttempts).toBe(0);
        expect(recoveryStates).toEqual([true, false]);
        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledTimes(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });

    it('reconnects after repeated registration failures without creating another SDK player', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:registration-recovery';
        let activeDeviceId = '';
        let firstDeviceTransferAttempts = 0;
        let playAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                const transferDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                if (transferDeviceId === 'atlas-browser-device') {
                    firstDeviceTransferAttempts++;

                    return spotifyDeviceErrorResponse();
                }

                activeDeviceId = transferDeviceId;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                playAttempts++;

                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(createSpotifyPlaybackController().play(spotifyUri, 0)).resolves.toMatchObject({
            trackUri: spotifyUri,
        });

        expect(firstDeviceTransferAttempts).toBe(8);
        expect(playAttempts).toBe(1);
        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledTimes(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });

    it('stops after one reconnect and keeps the player available for a later retry', async () => {
        installSpotifySdkMock();
        runTimersImmediately();
        const spotifyUri = 'spotify:track:bounded-recovery';
        let spotifyDeviceRegistered = false;
        let activeDeviceId = '';
        let transferAttempts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = String(input);

            if (url === '/api/spotify/playback-token') {
                return jsonResponse({ access_token: 'spotify-access-token' });
            }

            if (url === 'https://api.spotify.com/v1/me/player' && init?.method === 'PUT') {
                transferAttempts++;
                activeDeviceId = (JSON.parse(String(init.body)) as { device_ids: string[] }).device_ids[0] ?? '';

                return spotifyDeviceRegistered ? emptyResponse() : spotifyDeviceErrorResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player/devices') {
                return spotifyDevicesResponse(activeDeviceId);
            }

            if (url.startsWith('https://api.spotify.com/v1/me/player/play?device_id=')) {
                return emptyResponse();
            }

            if (url === 'https://api.spotify.com/v1/me/player') {
                return spotifyPlaybackResponse(spotifyUri, activeDeviceId);
            }

            throw new Error(`Unexpected Spotify request: ${url}`);
        });
        vi.stubGlobal('fetch', fetchMock);

        const controller = createSpotifyPlaybackController();
        await expect(controller.play(spotifyUri, 0)).rejects.toThrow('Device not found');

        const failedTransferAttempts = transferAttempts;
        spotifyDeviceRegistered = true;

        await expect(controller.play(spotifyUri, 0)).resolves.toMatchObject({ trackUri: spotifyUri });

        expect(failedTransferAttempts).toBe(16);
        expect(transferAttempts).toBe(failedTransferAttempts + 1);
        expect(spotifyPlayerInstances).toHaveLength(1);
        expect(spotifyPlayerInstances[0]?.connect).toHaveBeenCalledTimes(2);
        expect(spotifyPlayerInstances[0]?.disconnect).toHaveBeenCalledOnce();
        expect(activeDeviceId).toBe('atlas-browser-device-2');
    });
});
