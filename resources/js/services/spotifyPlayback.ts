import {
    activateSpotifyDevice,
    apiPlaybackToSnapshot,
    assertSpotifyPlaybackCurrent,
    currentSpotifyPlayback,
    delay,
    fetchAccessToken,
    isSpotifyDeviceAvailable,
    SpotifyPlaybackAuthenticationError,
    SpotifyPlaybackOwnershipError,
    SpotifyPlaybackSupersededError,
    startSpotifyDevicePlayback,
    waitForAtlasPlayback,
    type SpotifyPlaybackSnapshot,
    type SpotifyPlayOptions,
} from './spotifyPlaybackApi';

export type { SpotifyPlaybackSnapshot } from './spotifyPlaybackApi';

type SpotifySdkReadyEvent = { device_id: string };
type SpotifySdkError = { message: string };
type SpotifySdkTrack = { uri?: string };

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
    setVolume(volume: number): Promise<void>;
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

type SpotifyPlaybackOptions = {
    initialVolume?: number;
    onError?: (message: string) => void;
    onRecoveryStateChange?: (isRecovering: boolean) => void;
    onStateChange?: (snapshot: SpotifyPlaybackSnapshot | null) => void;
};

export type SpotifyPlaybackController = ReturnType<typeof createSpotifyPlaybackController>;

const SPOTIFY_SDK_URL = 'https://sdk.scdn.co/spotify-player.js';
const SPOTIFY_DEVICE_RECONNECT_DELAY_MS = 500;

let sdkLoadPromise: Promise<void> | null = null;

export function isSpotifyPlaybackSuperseded(error: unknown): boolean {
    return error instanceof SpotifyPlaybackSupersededError;
}

export function isSpotifyPlaybackAuthenticationError(error: unknown): error is SpotifyPlaybackAuthenticationError {
    return error instanceof SpotifyPlaybackAuthenticationError;
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

function clampSpotifyVolume(volume: number): number {
    return Number.isFinite(volume) ? Math.min(1, Math.max(0, volume)) : 0.7;
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

export function createSpotifyPlaybackController(controllerOptions: SpotifyPlaybackOptions = {}) {
    let player: SpotifyPlayer | null = null;
    let deviceId: string | null = null;
    let activatedDeviceId: string | null = null;
    let readyPromise: Promise<string> | null = null;
    let resolveReady: ((deviceId: string) => void) | null = null;
    let rejectReady: ((error: unknown) => void) | null = null;
    let currentAccessToken = '';
    let targetVolume = clampSpotifyVolume(controllerOptions.initialVolume ?? 0.7);
    let shouldVerifyDeviceRegistration = false;

    function clearReadyPromise(): void {
        readyPromise = null;
        resolveReady = null;
        rejectReady = null;
    }

    function resolveReadyPromise(readyDeviceId: string): void {
        const resolve = resolveReady;
        clearReadyPromise();
        resolve?.(readyDeviceId);
    }

    function rejectReadyPromise(error: unknown): void {
        const reject = rejectReady;
        clearReadyPromise();
        reject?.(error);
    }

    function createPlayer(): SpotifyPlayer {
        if (!window.Spotify?.Player) {
            throw new Error('Spotify Web Playback SDK is unavailable.');
        }

        const sdkPlayer = new window.Spotify.Player({
            name: 'Atlas',
            getOAuthToken: (callback) => {
                callback(currentAccessToken);
            },
            volume: targetVolume,
        });

        sdkPlayer.addListener('ready', ({ device_id }) => {
            if (player !== sdkPlayer) { return; }

            deviceId = device_id;
            activatedDeviceId = null;
            resolveReadyPromise(device_id);
        });
        sdkPlayer.addListener('not_ready', ({ device_id }) => {
            if (player !== sdkPlayer || deviceId !== device_id) { return; }

            sdkPlayer.disconnect();
            deviceId = null;
            activatedDeviceId = null;
            shouldVerifyDeviceRegistration = false;
            controllerOptions.onStateChange?.(null);
        });
        sdkPlayer.addListener('player_state_changed', (state) => {
            if (player === sdkPlayer) { controllerOptions.onStateChange?.(sdkStateToSnapshot(state)); }
        });

        const rejectWithSdkError = ({ message }: SpotifySdkError): void => {
            if (player !== sdkPlayer) { return; }

            controllerOptions.onError?.(message);
            rejectReadyPromise(new Error(message));
        };
        const rejectWithAuthenticationError = ({ message }: SpotifySdkError): void => {
            if (player !== sdkPlayer) { return; }

            const authenticationMessage = message || 'Spotify authentication failed. Reconnect Spotify and try again.';
            controllerOptions.onError?.(authenticationMessage);
            rejectReadyPromise(new SpotifyPlaybackAuthenticationError(authenticationMessage));
        };

        sdkPlayer.addListener('initialization_error', rejectWithSdkError);
        sdkPlayer.addListener('authentication_error', rejectWithAuthenticationError);
        sdkPlayer.addListener('account_error', rejectWithSdkError);
        sdkPlayer.addListener('playback_error', ({ message }) => {
            if (player === sdkPlayer) { controllerOptions.onError?.(message); }
        });
        sdkPlayer.addListener('autoplay_failed', ({ message }) => {
            if (player === sdkPlayer) { controllerOptions.onError?.(message || 'Spotify autoplay was blocked.'); }
        });

        return sdkPlayer;
    }

    function destroyPlayer(): void {
        const playerToDisconnect = player;
        const connectionError = new SpotifyPlaybackSupersededError();

        player = null;
        deviceId = null;
        activatedDeviceId = null;
        shouldVerifyDeviceRegistration = false;
        rejectReadyPromise(connectionError);
        playerToDisconnect?.disconnect();
    }

    async function connectPlayer(accessToken: string): Promise<string> {
        await loadSpotifySdk();
        currentAccessToken = accessToken;

        if (player && deviceId) {
            return deviceId;
        }

        if (player && readyPromise) {
            return readyPromise;
        }

        const sdkPlayer = player ?? createPlayer();
        player = sdkPlayer;
        readyPromise = new Promise((resolve, reject) => {
            resolveReady = resolve;
            rejectReady = reject;
        });
        const connection = readyPromise;

        void sdkPlayer.connect().then((connected) => {
            if (player === sdkPlayer && !connected && !deviceId) {
                rejectReadyPromise(new Error('Spotify Web Playback SDK did not connect.'));
            }
        }).catch((error: unknown) => {
            if (player === sdkPlayer) { rejectReadyPromise(error); }
        });

        return connection;
    }

    async function reconnectPlayer(accessToken: string, options: SpotifyPlayOptions): Promise<string> {
        const sdkPlayer = player;

        if (!sdkPlayer) {
            return connectPlayer(accessToken);
        }

        sdkPlayer.disconnect();
        deviceId = null;
        activatedDeviceId = null;
        rejectReadyPromise(new SpotifyPlaybackSupersededError());
        await delay(SPOTIFY_DEVICE_RECONNECT_DELAY_MS, options);
        assertSpotifyPlaybackCurrent(options);

        return connectPlayer(accessToken);
    }

    async function reconnectIfDeviceRegistrationExpired(
        token: string,
        options: SpotifyPlayOptions,
        beginRecovery: () => void,
    ): Promise<boolean> {
        if (!shouldVerifyDeviceRegistration || !player || !deviceId) {
            return false;
        }

        const currentDeviceId = deviceId;
        assertSpotifyPlaybackCurrent(options);

        if (await isSpotifyDeviceAvailable(token, currentDeviceId)) {
            shouldVerifyDeviceRegistration = false;

            return false;
        }

        shouldVerifyDeviceRegistration = false;
        beginRecovery();
        await reconnectPlayer(token, options);

        return true;
    }

    async function startPlayback(
        token: string,
        uri: string,
        positionMs: number,
        options: SpotifyPlayOptions,
    ): Promise<SpotifyPlaybackSnapshot> {
        const targetDeviceId = await connectPlayer(token);
        assertSpotifyPlaybackCurrent(options);

        await player?.activateElement();
        assertSpotifyPlaybackCurrent(options);

        const retryDeviceRegistration = activatedDeviceId !== targetDeviceId;
        await activateSpotifyDevice(token, targetDeviceId, options, retryDeviceRegistration);
        assertSpotifyPlaybackCurrent(options);

        if (deviceId !== targetDeviceId) {
            throw new Error('The Atlas Spotify browser player went offline while it was activating.');
        }

        activatedDeviceId = targetDeviceId;

        await startSpotifyDevicePlayback(token, targetDeviceId, uri, positionMs);

        return await waitForAtlasPlayback(token, targetDeviceId, uri, positionMs, options);
    }

    return {
        activateElement(): void {
            void player?.activateElement().catch(() => {
                controllerOptions.onError?.('Spotify autoplay was blocked.');
            });
        },
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
            destroyPlayer();
        },
        markDeviceRegistrationStale(): void {
            if (player && deviceId) {
                shouldVerifyDeviceRegistration = true;
            }
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
            const positionMs = Math.max(0, Math.round(positionSeconds * 1000));
            let isRecovering = false;
            let reconnectedBeforeStart = false;
            const beginRecovery = (): void => {
                if (isRecovering) {
                    return;
                }

                isRecovering = true;
                controllerOptions.onRecoveryStateChange?.(true);
            };

            try {
                reconnectedBeforeStart = await reconnectIfDeviceRegistrationExpired(token, options, beginRecovery);

                return await startPlayback(token, uri, positionMs, options);
            } catch (error) {
                if (
                    isSpotifyPlaybackSuperseded(error)
                    || isSpotifyPlaybackAuthenticationError(error)
                    || error instanceof SpotifyPlaybackOwnershipError
                    || reconnectedBeforeStart
                ) {
                    throw error;
                }

                beginRecovery();
                activatedDeviceId = null;
                await reconnectPlayer(token, options);
                assertSpotifyPlaybackCurrent(options);

                return await startPlayback(token, uri, positionMs, options);
            } finally {
                if (isRecovering) {
                    controllerOptions.onRecoveryStateChange?.(false);
                }
            }
        },
        async seek(positionSeconds: number): Promise<void> {
            if (!player) {
                return;
            }

            await player.seek(Math.max(0, Math.round(positionSeconds * 1000)));
        },
        async setVolume(volume: number): Promise<void> {
            targetVolume = clampSpotifyVolume(volume);

            if (!player) {
                return;
            }

            await player.setVolume(targetVolume);
        },
    };
}
