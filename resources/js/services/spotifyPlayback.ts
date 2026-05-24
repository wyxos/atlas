type SpotifyAccessTokenResponse = {
    access_token?: string;
    message?: string;
};

type SpotifySdkReadyEvent = {
    device_id: string;
};

type SpotifySdkError = {
    message: string;
};

type SpotifySdkTrack = {
    uri?: string;
};

type SpotifySdkPlaybackState = {
    paused: boolean;
    position: number;
    duration: number;
    track_window?: {
        current_track?: SpotifySdkTrack | null;
    };
};

type SpotifyPlayerOptions = {
    name: string;
    getOAuthToken: (callback: (token: string) => void) => void;
    volume?: number;
};

type SpotifyPlayer = {
    addListener(event: 'ready' | 'not_ready', callback: (event: SpotifySdkReadyEvent) => void): boolean;
    addListener(event: 'player_state_changed', callback: (state: SpotifySdkPlaybackState | null) => void): boolean;
    addListener(event: 'initialization_error' | 'authentication_error' | 'account_error' | 'playback_error' | 'autoplay_failed', callback: (event: SpotifySdkError) => void): boolean;
    activateElement(): Promise<void>;
    connect(): Promise<boolean>;
    disconnect(): void;
    getCurrentState(): Promise<SpotifySdkPlaybackState | null>;
    pause(): Promise<void>;
    seek(positionMs: number): Promise<void>;
};

type SpotifyNamespace = {
    Player: new (options: SpotifyPlayerOptions) => SpotifyPlayer;
};

declare global {
    interface Window {
        Spotify?: SpotifyNamespace;
        onSpotifyWebPlaybackSDKReady?: () => void;
    }
}

export type SpotifyPlaybackSnapshot = {
    durationMs: number;
    paused: boolean;
    positionMs: number;
    trackUri: string | null;
};

type SpotifyPlaybackOptions = {
    onError?: (message: string) => void;
    onStateChange?: (snapshot: SpotifyPlaybackSnapshot | null) => void;
};

type SpotifyPlayOptions = {
    shouldContinue?: () => boolean;
};

type SpotifyApiDevice = {
    id: string | null;
    is_active: boolean;
};

type SpotifyApiDevicesResponse = {
    devices?: SpotifyApiDevice[];
};

type SpotifyApiCurrentPlaybackResponse = {
    device?: SpotifyApiDevice | null;
    is_playing?: boolean;
    item?: {
        duration_ms?: number;
        uri?: string;
    } | null;
    progress_ms?: number | null;
};

export type SpotifyPlaybackController = ReturnType<typeof createSpotifyPlaybackController>;

const SPOTIFY_SDK_URL = 'https://sdk.scdn.co/spotify-player.js';
const SPOTIFY_API_BASE_URL = 'https://api.spotify.com/v1';
const SPOTIFY_DEVICE_READY_TIMEOUT_MS = 5000;
const SPOTIFY_DEVICE_READY_POLL_MS = 250;
const SPOTIFY_PLAYBACK_POSITION_TOLERANCE_MS = 3500;
const SPOTIFY_AUTHENTICATION_ERROR_STATUSES = new Set([401, 403, 409]);

let sdkLoadPromise: Promise<void> | null = null;

class SpotifyPlaybackSupersededError extends Error {
    constructor() {
        super('Spotify playback request was superseded.');
        this.name = 'SpotifyPlaybackSupersededError';
    }
}

class SpotifyPlaybackAuthenticationError extends Error {
    constructor(message: string, public readonly status: number | null = null) {
        super(message);
        this.name = 'SpotifyPlaybackAuthenticationError';
    }
}

export function isSpotifyPlaybackSuperseded(error: unknown): boolean {
    return error instanceof SpotifyPlaybackSupersededError;
}

export function isSpotifyPlaybackAuthenticationError(error: unknown): error is SpotifyPlaybackAuthenticationError {
    return error instanceof SpotifyPlaybackAuthenticationError;
}

function assertSpotifyPlaybackCurrent(options?: SpotifyPlayOptions): void {
    if (options?.shouldContinue?.() === false) {
        throw new SpotifyPlaybackSupersededError();
    }
}

function sdkStateToSnapshot(state: SpotifySdkPlaybackState | null): SpotifyPlaybackSnapshot | null {
    if (!state) {
        return null;
    }

    return {
        durationMs: Number.isFinite(state.duration) ? state.duration : 0,
        paused: Boolean(state.paused),
        positionMs: Number.isFinite(state.position) ? state.position : 0,
        trackUri: state.track_window?.current_track?.uri ?? null,
    };
}

function apiPlaybackToSnapshot(playback: SpotifyApiCurrentPlaybackResponse | null, deviceId: string | null): SpotifyPlaybackSnapshot | null {
    if (!playback || !deviceId || playback.device?.id !== deviceId) {
        return null;
    }

    const durationMs = typeof playback.item?.duration_ms === 'number' && Number.isFinite(playback.item.duration_ms)
        ? playback.item.duration_ms
        : 0;
    const positionMs = typeof playback.progress_ms === 'number' && Number.isFinite(playback.progress_ms)
        ? playback.progress_ms
        : 0;

    return {
        durationMs,
        paused: playback.is_playing === false,
        positionMs,
        trackUri: playback.item?.uri ?? null,
    };
}

function loadSpotifySdk(): Promise<void> {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('Spotify playback requires a browser.'));
    }

    if (window.Spotify?.Player) {
        return Promise.resolve();
    }

    if (sdkLoadPromise) {
        return sdkLoadPromise;
    }

    sdkLoadPromise = new Promise((resolve, reject) => {
        const existingScript = document.querySelector<HTMLScriptElement>(`script[src="${SPOTIFY_SDK_URL}"]`);
        const previousReadyCallback = window.onSpotifyWebPlaybackSDKReady;

        window.onSpotifyWebPlaybackSDKReady = () => {
            previousReadyCallback?.();
            resolve();
        };

        if (existingScript) {
            existingScript.addEventListener('error', () => reject(new Error('Unable to load Spotify Web Playback SDK.')), { once: true });
            return;
        }

        const script = document.createElement('script');
        script.src = SPOTIFY_SDK_URL;
        script.async = true;
        script.addEventListener('error', () => reject(new Error('Unable to load Spotify Web Playback SDK.')), { once: true });
        document.head.appendChild(script);
    });

    return sdkLoadPromise;
}

async function fetchAccessToken(): Promise<string> {
    const response = await fetch('/api/spotify/playback-token', {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
        },
    });

    const payload = await response.json().catch(() => ({})) as SpotifyAccessTokenResponse;
    if (!response.ok) {
        const message = payload.message ?? 'Unable to load Spotify playback token.';

        if (SPOTIFY_AUTHENTICATION_ERROR_STATUSES.has(response.status)) {
            throw new SpotifyPlaybackAuthenticationError(message, response.status);
        }

        throw new Error(message);
    }

    const token = payload.access_token?.trim();
    if (!token) {
        throw new Error('Spotify playback token response was empty.');
    }

    return token;
}

async function spotifyApiRequest(path: string, token: string, init: RequestInit): Promise<void> {
    const response = await fetch(`${SPOTIFY_API_BASE_URL}${path}`, {
        ...init,
        headers: {
            Authorization: `Bearer ${token}`,
            'Content-Type': 'application/json',
        },
    });

    if (!response.ok && response.status !== 204) {
        throw await spotifyApiError(response);
    }
}

async function spotifyApiJsonRequest<T>(path: string, token: string): Promise<T> {
    const response = await fetch(`${SPOTIFY_API_BASE_URL}${path}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (!response.ok) {
        throw await spotifyApiError(response);
    }

    return await response.json() as T;
}

async function spotifyApiOptionalJsonRequest<T>(path: string, token: string): Promise<T | null> {
    const response = await fetch(`${SPOTIFY_API_BASE_URL}${path}`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
        },
    });

    if (response.status === 204) {
        return null;
    }

    if (!response.ok) {
        throw await spotifyApiError(response);
    }

    return await response.json() as T;
}

async function spotifyApiErrorMessage(response: Response): Promise<string> {
    const payload = await response.json().catch(() => null) as { error?: { message?: string } } | null;
    const message = payload?.error?.message?.trim();

    return message && message !== ''
        ? message
        : `Spotify playback request failed with HTTP ${response.status}.`;
}

async function spotifyApiError(response: Response): Promise<Error> {
    const message = await spotifyApiErrorMessage(response);

    return SPOTIFY_AUTHENTICATION_ERROR_STATUSES.has(response.status)
        ? new SpotifyPlaybackAuthenticationError(message, response.status)
        : new Error(message);
}

async function delay(milliseconds: number, options?: SpotifyPlayOptions): Promise<void> {
    assertSpotifyPlaybackCurrent(options);

    await new Promise<void>((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });

    assertSpotifyPlaybackCurrent(options);
}

async function findSpotifyDevice(token: string, deviceId: string): Promise<SpotifyApiDevice | null> {
    const payload = await spotifyApiJsonRequest<SpotifyApiDevicesResponse>('/me/player/devices', token);

    return payload.devices?.find((device) => device.id === deviceId) ?? null;
}

async function waitForSpotifyDevice(
    token: string,
    deviceId: string,
    requiresActiveDevice = false,
    options?: SpotifyPlayOptions,
): Promise<SpotifyApiDevice> {
    const startedAt = Date.now();

    do {
        assertSpotifyPlaybackCurrent(options);
        const device = await findSpotifyDevice(token, deviceId);
        assertSpotifyPlaybackCurrent(options);

        if (device && (!requiresActiveDevice || device.is_active)) {
            return device;
        }

        await delay(SPOTIFY_DEVICE_READY_POLL_MS, options);
    } while (Date.now() - startedAt < SPOTIFY_DEVICE_READY_TIMEOUT_MS);

    throw new Error(requiresActiveDevice
        ? 'Spotify did not switch playback to the Atlas browser player.'
        : 'The Atlas Spotify browser player is not available in Spotify Connect yet.');
}

async function currentSpotifyPlayback(token: string): Promise<SpotifyApiCurrentPlaybackResponse | null> {
    return await spotifyApiOptionalJsonRequest<SpotifyApiCurrentPlaybackResponse>('/me/player', token);
}

async function pauseSpotifyDevice(token: string, deviceId: string): Promise<void> {
    await spotifyApiRequest(`/me/player/pause?device_id=${encodeURIComponent(deviceId)}`, token, {
        method: 'PUT',
    });
}

function isPlaybackNearRequestedPosition(
    playback: SpotifyApiCurrentPlaybackResponse | null,
    positionMs: number,
    requestedAt: number,
): boolean {
    if (typeof playback?.progress_ms !== 'number' || !Number.isFinite(playback.progress_ms)) {
        return true;
    }

    const elapsedMs = Date.now() - requestedAt;
    const allowedProgressMs = positionMs + elapsedMs + SPOTIFY_PLAYBACK_POSITION_TOLERANCE_MS;

    return playback.progress_ms >= positionMs - SPOTIFY_PLAYBACK_POSITION_TOLERANCE_MS
        && playback.progress_ms <= allowedProgressMs;
}

async function waitForAtlasPlayback(
    token: string,
    deviceId: string,
    uri: string,
    positionMs: number,
    options?: SpotifyPlayOptions,
): Promise<SpotifyPlaybackSnapshot> {
    const startedAt = Date.now();

    do {
        assertSpotifyPlaybackCurrent(options);
        const playback = await currentSpotifyPlayback(token);
        assertSpotifyPlaybackCurrent(options);

        const activeDeviceId = playback?.device?.id ?? null;
        const activeTrackUri = playback?.item?.uri ?? null;

        if (
            activeDeviceId === deviceId
            && activeTrackUri === uri
            && playback?.is_playing !== false
            && isPlaybackNearRequestedPosition(playback, positionMs, startedAt)
        ) {
            return apiPlaybackToSnapshot(playback, deviceId) ?? {
                durationMs: 0,
                paused: false,
                positionMs,
                trackUri: uri,
            };
        }

        if (activeDeviceId && activeDeviceId !== deviceId && activeTrackUri === uri) {
            await pauseSpotifyDevice(token, activeDeviceId);
            throw new Error('Spotify started playback on another device instead of Atlas, so Atlas paused it.');
        }

        await delay(SPOTIFY_DEVICE_READY_POLL_MS, options);
    } while (Date.now() - startedAt < SPOTIFY_DEVICE_READY_TIMEOUT_MS);

    throw new Error('Spotify did not confirm playback on the Atlas browser player.');
}

export function createSpotifyPlaybackController(options: SpotifyPlaybackOptions = {}) {
    let player: SpotifyPlayer | null = null;
    let deviceId: string | null = null;
    let readyPromise: Promise<string> | null = null;
    let currentAccessToken = '';

    async function ensurePlayer(accessToken: string): Promise<string> {
        await loadSpotifySdk();
        currentAccessToken = accessToken;

        if (player && readyPromise) {
            return readyPromise;
        }

        readyPromise = new Promise((resolve, reject) => {
            if (!window.Spotify?.Player) {
                reject(new Error('Spotify Web Playback SDK is unavailable.'));
                return;
            }

            player = new window.Spotify.Player({
                name: 'Atlas',
                getOAuthToken: (callback) => {
                    callback(currentAccessToken);
                },
                volume: 0.7,
            });

            player.addListener('ready', ({ device_id }) => {
                deviceId = device_id;
                resolve(device_id);
            });
            player.addListener('not_ready', ({ device_id }) => {
                if (deviceId === device_id) {
                    deviceId = null;
                }
            });
            player.addListener('player_state_changed', (state) => {
                options.onStateChange?.(sdkStateToSnapshot(state));
            });

            const rejectWithSdkError = ({ message }: SpotifySdkError): void => {
                options.onError?.(message);
                reject(new Error(message));
            };
            const rejectWithAuthenticationError = ({ message }: SpotifySdkError): void => {
                const authenticationMessage = message || 'Spotify authentication failed. Reconnect Spotify and try again.';
                options.onError?.(authenticationMessage);
                reject(new SpotifyPlaybackAuthenticationError(authenticationMessage));
            };

            player.addListener('initialization_error', rejectWithSdkError);
            player.addListener('authentication_error', rejectWithAuthenticationError);
            player.addListener('account_error', rejectWithSdkError);
            player.addListener('playback_error', ({ message }) => {
                options.onError?.(message);
            });
            player.addListener('autoplay_failed', ({ message }) => {
                options.onError?.(message || 'Spotify autoplay was blocked.');
            });

            void player.connect().then((connected) => {
                if (!connected) {
                    reject(new Error('Spotify Web Playback SDK did not connect.'));
                }
            }).catch(reject);
        });

        return readyPromise;
    }

    return {
        async currentState(): Promise<SpotifyPlaybackSnapshot | null> {
            if (!player) {
                return null;
            }

            const sdkSnapshot = sdkStateToSnapshot(await player.getCurrentState());
            if (sdkSnapshot || !currentAccessToken) {
                return sdkSnapshot;
            }

            return apiPlaybackToSnapshot(await currentSpotifyPlayback(currentAccessToken), deviceId);
        },
        destroy(): void {
            player?.disconnect();
            player = null;
            deviceId = null;
            readyPromise = null;
        },
        async pause(): Promise<void> {
            if (!player) {
                return;
            }

            await player.pause();
        },
        async play(uri: string, positionSeconds: number, options: SpotifyPlayOptions = {}): Promise<SpotifyPlaybackSnapshot> {
            assertSpotifyPlaybackCurrent(options);
            const token = await fetchAccessToken();
            assertSpotifyPlaybackCurrent(options);
            const targetDeviceId = await ensurePlayer(token);
            assertSpotifyPlaybackCurrent(options);
            const positionMs = Math.max(0, Math.round(positionSeconds * 1000));

            await player?.activateElement();
            assertSpotifyPlaybackCurrent(options);
            await waitForSpotifyDevice(token, targetDeviceId, false, options);
            assertSpotifyPlaybackCurrent(options);
            await spotifyApiRequest('/me/player', token, {
                method: 'PUT',
                body: JSON.stringify({
                    device_ids: [targetDeviceId],
                    play: false,
                }),
            });

            assertSpotifyPlaybackCurrent(options);
            await waitForSpotifyDevice(token, targetDeviceId, false, options);
            assertSpotifyPlaybackCurrent(options);
            await spotifyApiRequest(`/me/player/play?device_id=${encodeURIComponent(targetDeviceId)}`, token, {
                method: 'PUT',
                body: JSON.stringify({
                    position_ms: positionMs,
                    uris: [uri],
                }),
            });
            return await waitForAtlasPlayback(token, targetDeviceId, uri, positionMs, options);
        },
        async seek(positionSeconds: number): Promise<void> {
            if (!player) {
                return;
            }

            await player.seek(Math.max(0, Math.round(positionSeconds * 1000)));
        },
    };
}
