export type SpotifyPlaybackSnapshot = {
    durationMs: number;
    paused: boolean;
    positionMs: number;
    trackUri: string | null;
};

export type SpotifyPlayOptions = {
    shouldContinue?: () => boolean;
};

type SpotifyAccessTokenResponse = {
    access_token?: string;
    message?: string;
};

type SpotifyApiDevice = {
    id: string | null;
    is_active: boolean;
    is_restricted?: boolean;
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

const SPOTIFY_API_BASE_URL = 'https://api.spotify.com/v1';
const SPOTIFY_DEVICE_READY_TIMEOUT_MS = 5000;
const SPOTIFY_DEVICE_READY_POLL_MS = 250;
const SPOTIFY_DEVICE_REGISTRATION_RETRY_DELAYS_MS = [250, 500, 1000, 2000, 3000, 4000, 5000] as const;
const SPOTIFY_PLAYBACK_POSITION_TOLERANCE_MS = 1500;
const SPOTIFY_AUTHENTICATION_ERROR_STATUSES = new Set([401, 403, 409]);

export class SpotifyPlaybackSupersededError extends Error {
    constructor() {
        super('Spotify playback request was superseded.');
        this.name = 'SpotifyPlaybackSupersededError';
    }
}

export class SpotifyPlaybackAuthenticationError extends Error {
    constructor(message: string, public readonly status: number | null = null) {
        super(message);
        this.name = 'SpotifyPlaybackAuthenticationError';
    }
}

class SpotifyPlaybackApiError extends Error {
    constructor(message: string, public readonly status: number) {
        super(message);
        this.name = 'SpotifyPlaybackApiError';
    }
}

export class SpotifyPlaybackOwnershipError extends Error {
    constructor(message: string) {
        super(message);
        this.name = 'SpotifyPlaybackOwnershipError';
    }
}

export function assertSpotifyPlaybackCurrent(options?: SpotifyPlayOptions): void {
    if (options?.shouldContinue?.() === false) {
        throw new SpotifyPlaybackSupersededError();
    }
}

export async function delay(milliseconds: number, options?: SpotifyPlayOptions): Promise<void> {
    assertSpotifyPlaybackCurrent(options);

    await new Promise<void>((resolve) => {
        window.setTimeout(resolve, milliseconds);
    });

    assertSpotifyPlaybackCurrent(options);
}

export async function fetchAccessToken(): Promise<string> {
    const response = await fetch('/api/spotify/playback-token', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
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

async function spotifyApiError(response: Response): Promise<Error> {
    const payload = await response.json().catch(() => null) as { error?: { message?: string } } | null;
    const responseMessage = payload?.error?.message?.trim();
    const message = responseMessage && responseMessage !== ''
        ? responseMessage
        : `Spotify playback request failed with HTTP ${response.status}.`;

    return SPOTIFY_AUTHENTICATION_ERROR_STATUSES.has(response.status)
        ? new SpotifyPlaybackAuthenticationError(message, response.status)
        : new SpotifyPlaybackApiError(message, response.status);
}

export async function currentSpotifyPlayback(token: string): Promise<SpotifyApiCurrentPlaybackResponse | null> {
    return await spotifyApiOptionalJsonRequest<SpotifyApiCurrentPlaybackResponse>('/me/player', token);
}

async function currentSpotifyDevices(token: string): Promise<SpotifyApiDevice[]> {
    const response = await spotifyApiOptionalJsonRequest<SpotifyApiDevicesResponse>('/me/player/devices', token);

    return Array.isArray(response?.devices) ? response.devices : [];
}

export async function isSpotifyDeviceAvailable(token: string, deviceId: string): Promise<boolean> {
    return (await currentSpotifyDevices(token)).some(({ id }) => id === deviceId);
}

async function pauseSpotifyDevice(token: string, deviceId: string): Promise<void> {
    await spotifyApiRequest(`/me/player/pause?device_id=${encodeURIComponent(deviceId)}`, token, { method: 'PUT' });
}

async function transferSpotifyPlayback(token: string, deviceId: string): Promise<void> {
    await spotifyApiRequest('/me/player', token, {
        method: 'PUT',
        body: JSON.stringify({ device_ids: [deviceId], play: false }),
    });
}

function isSpotifyDeviceNotFoundError(error: unknown): error is SpotifyPlaybackApiError {
    return error instanceof SpotifyPlaybackApiError
        && error.status === 404
        && /device not found/i.test(error.message);
}

export async function activateSpotifyDevice(
    token: string,
    deviceId: string,
    options?: SpotifyPlayOptions,
    retryDeviceRegistration = true,
): Promise<void> {
    const maxAttempts = retryDeviceRegistration
        ? SPOTIFY_DEVICE_REGISTRATION_RETRY_DELAYS_MS.length + 1
        : 1;
    let lastDeviceNotFoundError: SpotifyPlaybackApiError | null = null;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        assertSpotifyPlaybackCurrent(options);

        if (attempt > 1) {
            await delay(SPOTIFY_DEVICE_REGISTRATION_RETRY_DELAYS_MS[attempt - 2] ?? 0, options);
        }

        try {
            await transferSpotifyPlayback(token, deviceId);
            assertSpotifyPlaybackCurrent(options);
            const device = (await currentSpotifyDevices(token)).find(({ id }) => id === deviceId);
            assertSpotifyPlaybackCurrent(options);

            if (device?.is_restricted) {
                throw new Error('The Atlas Spotify browser player is restricted from Web API playback control.');
            }

            if (device?.is_active) {
                return;
            }
        } catch (error) {
            if (!isSpotifyDeviceNotFoundError(error)) {
                throw error;
            }

            lastDeviceNotFoundError = error;
        }
    }

    if (lastDeviceNotFoundError) {
        throw lastDeviceNotFoundError;
    }

    throw new Error('Spotify did not activate the Atlas browser player.');
}

export async function startSpotifyDevicePlayback(
    token: string,
    deviceId: string,
    uri: string,
    positionMs: number,
): Promise<void> {
    await spotifyApiRequest(`/me/player/play?device_id=${encodeURIComponent(deviceId)}`, token, {
        method: 'PUT',
        body: JSON.stringify({ position_ms: positionMs, uris: [uri] }),
    });
}

export function apiPlaybackToSnapshot(
    playback: SpotifyApiCurrentPlaybackResponse | null,
    deviceId: string | null,
): SpotifyPlaybackSnapshot | null {
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

export async function waitForAtlasPlayback(
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
            throw new SpotifyPlaybackOwnershipError('Spotify started playback on another device instead of Atlas, so Atlas paused it.');
        }

        await delay(SPOTIFY_DEVICE_READY_POLL_MS, options);
    } while (Date.now() - startedAt < SPOTIFY_DEVICE_READY_TIMEOUT_MS);

    throw new Error('Spotify did not confirm playback on the Atlas browser player.');
}
