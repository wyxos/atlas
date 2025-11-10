/// <reference types="spotify-web-playback-sdk" />

import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import axios from 'axios';
import { computed, ref } from 'vue';


export interface AudioTrack {
    id: number;
    url?: string;
    path?: string;
    source?: string;
    source_id?: string;
    mime_type?: string;
    [key: string]: any;
}

interface PlayOptions {
    autoPlay?: boolean;
}

type SpotifyPlayer = Spotify.Player;

class AudioPlayerManager {
    private audio: HTMLAudioElement | null = null;
    private spotifyPlayer: SpotifyPlayer | null = null;
    private spotifyAccessToken: string | null = null;
    private spotifyDeviceId: string | null = null;
    private spotifyPlayerReady = ref<boolean>(false);
    private spotifyTrackEndHandled = false;
    private spotifyLastPosition = 0;
    private spotifyPausedPosition = 0;
    private spotifyPollInterval: number | null = null;
    private isLoadingTrack = false;
    private isNavigating = false; // Flag to prevent concurrent navigation
    private isActive = ref<boolean>(false);
    private currentTrack = ref<AudioTrack | null>(null);
    private queue = ref<AudioTrack[]>([]);
    private currentIndex = ref<number>(-1);
    private isPlaying = ref<boolean>(false);
    private currentTime = ref<number>(0);
    private duration = ref<number>(0);
    private volume = ref<number>(1);
    private userPaused = false;
    private isShuffled = ref<boolean>(false);
    private repeatMode = ref<'off' | 'all' | 'one'>('off');
    private originalQueue: AudioTrack[] = [];
    private originalCurrentTrackId: number | null = null;

    // Reactive refs
    readonly currentTrackRef = computed(() => this.currentTrack.value);
    readonly queueRef = computed(() => [...this.queue.value]);
    readonly currentIndexRef = computed(() => this.currentIndex.value);
    readonly isPlayingRef = computed(() => this.isPlaying.value);
    readonly currentTimeRef = computed(() => this.currentTime.value);
    readonly durationRef = computed(() => this.duration.value);
    readonly volumeRef = computed(() => this.volume.value);
    readonly spotifyPlayerReadyRef = computed(() => this.spotifyPlayerReady.value);
    readonly isShuffledRef = computed(() => this.isShuffled.value);
    readonly repeatModeRef = computed(() => this.repeatMode.value);
    readonly isActiveRef = computed(() => this.isActive.value);

    private isSpotifyTrack(track: AudioTrack): boolean {
        const source = (track.source || '').toString().trim().toLowerCase();
        const mimeType = (track.mime_type || '').toString().trim().toLowerCase();
        return source === 'spotify' || mimeType === 'audio/spotify';
    }

    private getSpotifyTrackUri(track: AudioTrack): string | null {
        if (!this.isSpotifyTrack(track)) {
            return null;
        }

        // If source_id is a full URI, use it
        if (track.source_id && track.source_id.startsWith('spotify:track:')) {
            return track.source_id;
        }

        // Otherwise construct from source_id
        if (track.source_id) {
            return `spotify:track:${track.source_id}`;
        }

        return null;
    }

    private async loadSpotifySDK(): Promise<void> {
        if (typeof window === 'undefined') return;

        // Check if SDK is already loaded
        if ((window as any).Spotify) {
            return;
        }

        return new Promise((resolve, reject) => {
            // Set up the callback that Spotify SDK calls when ready
            (window as any).onSpotifyWebPlaybackSDKReady = () => {
                resolve();
            };

            const script = document.createElement('script');
            script.src = 'https://sdk.scdn.co/spotify-player.js';
            script.async = true;
            script.onerror = () => reject(new Error('Failed to load Spotify SDK'));
            document.head.appendChild(script);
        });
    }

    private async getSpotifyAccessToken(): Promise<string | null> {
        if (this.spotifyAccessToken) {
            return this.spotifyAccessToken;
        }

        try {
            const response = await axios.get('/spotify/token');
            this.spotifyAccessToken = response.data.access_token || null;
            return this.spotifyAccessToken;
        } catch (error) {
            console.error('Failed to get Spotify access token:', error);
            return null;
        }
    }

    private async initSpotifyPlayer(): Promise<boolean> {
        if (this.spotifyPlayer && this.spotifyPlayerReady.value) {
            return true;
        }

        try {
            await this.loadSpotifySDK();

            const token = await this.getSpotifyAccessToken();
            if (!token) {
                console.error('No Spotify access token available');
                return false;
            }

            const spotifySDK = (window as any).Spotify;
            if (!spotifySDK) {
                console.error('Spotify SDK not available');
                return false;
            }

            this.spotifyPlayer = new spotifySDK.Player({
                name: 'Atlas Audio Player',
                getOAuthToken: async (cb: (token: string) => void) => {
                    // Always fetch a fresh token when the SDK requests it
                    // This prevents "Token provider returned the same token twice" errors
                    this.spotifyAccessToken = null; // Clear cache to force refresh
                    const freshToken = await this.getSpotifyAccessToken();
                    if (freshToken) {
                        cb(freshToken);
                    } else {
                        console.error('Failed to get fresh Spotify token for SDK');
                    }
                },
                volume: this.volume.value,
            });

            // Set up event listeners
            if (!this.spotifyPlayer) {
                return false;
            }

            this.spotifyPlayer.addListener('ready', ({ device_id }: { device_id: string }) => {
                console.log('Spotify player ready with device ID:', device_id);
                this.spotifyDeviceId = device_id;
                this.spotifyPlayerReady.value = true;
            });

            this.spotifyPlayer.addListener('not_ready', ({ device_id }: { device_id: string }) => {
                console.log('Spotify player not ready:', device_id);
                this.spotifyPlayerReady.value = false;
            });

            this.spotifyPlayer.addListener('initialization_error', ({ message }: { message: string }) => {
                console.error('Spotify initialization error:', message);
            });

            this.spotifyPlayer.addListener('authentication_error', ({ message }: { message: string }) => {
                console.error('Spotify authentication error:', message);
                // Token might be expired, clear it to force refresh
                this.spotifyAccessToken = null;
            });

            this.spotifyPlayer.addListener('account_error', ({ message }: { message: string }) => {
                console.error('Spotify account error:', message);
            });

            this.spotifyPlayer.addListener('playback_error', ({ message }: { message: string }) => {
                console.error('Spotify playback error:', message);
            });

            this.spotifyPlayer.addListener('player_state_changed', (state) => {
                if (!state) return;

                // Ignore state updates during track loading to prevent old track's state from updating UI
                if (this.isLoadingTrack) return;

                // Track previous playing state before updating
                const wasPlaying = this.isPlaying.value;
                this.isPlaying.value = !state.paused;
                this.updateSpotifyTime(state);
                
                // Update paused position when playing (so we can resume from current position)
                if (!state.paused && state.position > 0) {
                    this.spotifyPausedPosition = state.position;
                }

                // Start/stop polling for more frequent updates
                this.updateSpotifyPolling(!state.paused);

                // Handle track end - detect when track has finished
                if (state.duration > 0 && this.currentTrack.value) {
                    const remaining = state.duration - state.position;
                    const position = state.position;

                    // Detect if track has looped (position jumped back to near start after being near end)
                    const wasNearEnd = this.spotifyLastPosition > state.duration - 1000;
                    const hasLooped = position < 1000 && wasNearEnd && !state.paused;

                    // Detect when track is very close to the end (within 500ms) and still playing
                    const isAtEnd = remaining < 500 && remaining > 0 && !state.paused;
                    // Detect when track has ended and reset (paused at very start after playing)
                    const hasEndedAndReset = state.paused && position < 500 && wasPlaying;

                    if ((isAtEnd || hasEndedAndReset || hasLooped) && !this.spotifyTrackEndHandled) {
                        this.spotifyTrackEndHandled = true;
                        this.spotifyLastPosition = position;
                        void this.handleSpotifyTrackEnd();
                        return;
                    }

                    if (!state.paused && remaining >= 1000) {
                        // Reset the flag when we're well away from the end and playing normally
                        this.spotifyTrackEndHandled = false;
                    }

                    // Track last position for loop detection
                    this.spotifyLastPosition = position;
                }
            });

            // Connect to Spotify
            const connected = await this.spotifyPlayer.connect();
            if (!connected) {
                console.error('Failed to connect to Spotify');
                return false;
            }

            return true;
        } catch (error) {
            console.error('Failed to initialize Spotify player:', error);
            return false;
        }
    }

    private updateSpotifyTime(state: Spotify.PlaybackState): void {
        if (state.position !== null) {
            this.currentTime.value = state.position / 1000; // Convert ms to seconds
        }
        if (state.duration !== null) {
            this.duration.value = state.duration / 1000; // Convert ms to seconds
        }
    }

    private updateSpotifyPolling(isPlaying: boolean): void {
        // Clear existing interval
        if (this.spotifyPollInterval !== null) {
            clearInterval(this.spotifyPollInterval);
            this.spotifyPollInterval = null;
        }

        // Start polling if playing
        if (isPlaying && this.spotifyPlayer && this.currentTrack.value && this.isSpotifyTrack(this.currentTrack.value)) {
            // Poll every 250ms for smooth progress updates
            this.spotifyPollInterval = window.setInterval(async () => {
                if (!this.spotifyPlayer) return;
                
                try {
                    const state = await this.spotifyPlayer.getCurrentState();
                    if (state && !state.paused) {
                        this.updateSpotifyTime(state);
                        // Update paused position for resume
                        if (state.position !== null && state.position > 0) {
                            this.spotifyPausedPosition = state.position;
                        }
                    }
                } catch (error) {
                    // Silently handle errors (player might be disconnected)
                    console.debug('Error polling Spotify state:', error);
                }
            }, 250);
        }
    }

    private async playSpotifyTrack(track: AudioTrack): Promise<void> {
        const uri = this.getSpotifyTrackUri(track);
        if (!uri) {
            console.error('Invalid Spotify track URI:', track);
            return;
        }

        // Reset track end flag and position tracking for new track
        this.spotifyTrackEndHandled = false;
        this.spotifyLastPosition = 0;
        this.spotifyPausedPosition = 0;

        const initialized = await this.initSpotifyPlayer();
        if (!initialized || !this.spotifyPlayer) {
            console.error('Spotify player not initialized');
            return;
        }

        // Wait for device ID if not ready yet
        if (!this.spotifyDeviceId) {
            // Wait up to 5 seconds for device to be ready
            let attempts = 0;
            while (!this.spotifyDeviceId && attempts < 50) {
                await new Promise((resolve) => setTimeout(resolve, 100));
                attempts++;
            }

            if (!this.spotifyDeviceId) {
                console.error('Spotify device ID not available');
                return;
            }
        }

        if (!this.spotifyPlayerReady.value || !this.spotifyDeviceId) {
            console.warn('Spotify player not ready or device missing; skipping play');
            return;
        }

        try {
            // Transfer playback to our device and play the track
            const token = await this.getSpotifyAccessToken();
            if (!token) {
                console.error('No Spotify access token');
                return;
            }

            // Use Spotify Web API to start playback (we only use this for playback control, not metadata)
            // Always start from the beginning (position_ms: 0) when playing a new track
            try {
                await axios.put(
                    `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
                    {
                        uris: [uri],
                        position_ms: 0,
                    },
                    {
                        headers: {
                            Authorization: `Bearer ${token}`,
                        },
                    },
                );
            } catch (error: any) {
                // If we get a 401, the token expired - clear cache and retry once
                if (error?.response?.status === 401) {
                    console.warn('Spotify token expired, refreshing...');
                    this.spotifyAccessToken = null;
                    const freshToken = await this.getSpotifyAccessToken();
                    if (freshToken) {
                        await axios.put(
                            `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
                            {
                                uris: [uri],
                                position_ms: 0,
                            },
                            {
                                headers: {
                                    Authorization: `Bearer ${freshToken}`,
                                },
                            },
                        );
                    } else {
                        throw error;
                    }
                } else {
                    throw error;
                }
            }

            this.currentTrack.value = track;
            // Reset paused position when starting a new track
            this.spotifyPausedPosition = 0;
        } catch (error) {
            console.error('Failed to play Spotify track:', error);
            throw error;
        }
    }

    private async resumeSpotifyTrack(positionMs: number): Promise<void> {
        const currentTrack = this.currentTrack.value;
        if (!currentTrack) {
            console.error('No current track to resume');
            return;
        }

        const uri = this.getSpotifyTrackUri(currentTrack);
        if (!uri) {
            console.error('Invalid Spotify track URI:', currentTrack);
            return;
        }

        // Force reconnection if player is not ready or device is missing
        if (!this.spotifyPlayerReady.value || !this.spotifyDeviceId) {
            // Clear cached device ID and force reconnection
            this.spotifyDeviceId = null;
            this.spotifyPlayerReady.value = false;
            
            // Disconnect existing player if it exists
            if (this.spotifyPlayer) {
                try {
                    await this.spotifyPlayer.disconnect();
                } catch (error) {
                    console.debug('Error disconnecting Spotify player:', error);
                }
                this.spotifyPlayer = null;
            }
        }

        const initialized = await this.initSpotifyPlayer();
        if (!initialized || !this.spotifyPlayer) {
            console.error('Spotify player not initialized');
            return;
        }

        // Wait for device ID if not ready yet
        if (!this.spotifyDeviceId) {
            let attempts = 0;
            while (!this.spotifyDeviceId && attempts < 50) {
                await new Promise((resolve) => setTimeout(resolve, 100));
                attempts++;
            }

            if (!this.spotifyDeviceId) {
                console.error('Spotify device ID not available');
                return;
            }
        }

        if (!this.spotifyPlayerReady.value || !this.spotifyDeviceId) {
            console.warn('Spotify player not ready or device missing; skipping resume');
            return;
        }

        try {
            // Force token refresh to ensure we have a valid token
            this.spotifyAccessToken = null;
            const token = await this.getSpotifyAccessToken();
            if (!token) {
                console.error('No Spotify access token');
                return;
            }

            // Use Spotify Web API to resume playback from saved position
            // Include both URI and position_ms to ensure the correct track resumes from the correct position
            await axios.put(
                `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
                {
                    uris: [uri],
                    position_ms: positionMs,
                },
                {
                    headers: {
                        Authorization: `Bearer ${token}`,
                    },
                },
            );

            this.isPlaying.value = true;
        } catch (error: any) {
            // Handle 401 - token expired, refresh and retry
            if (error?.response?.status === 401) {
                console.warn('Spotify token expired during resume, refreshing...');
                this.spotifyAccessToken = null;
                const freshToken = await this.getSpotifyAccessToken();
                if (freshToken) {
                    try {
                        await axios.put(
                            `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
                            {
                                uris: [uri],
                                position_ms: positionMs,
                            },
                            {
                                headers: {
                                    Authorization: `Bearer ${freshToken}`,
                                },
                            },
                        );
                        this.isPlaying.value = true;
                        return; // Success, exit early
                    } catch (retryError) {
                        console.error('Failed to resume after token refresh:', retryError);
                        // Fall through to 404/ERR_BAD_REQUEST handling
                    }
                }
            }
            
            // Handle 404 or other errors - device might have disconnected
            if (error?.response?.status === 404 || error?.code === 'ERR_BAD_REQUEST') {
                console.warn('Spotify device not found, reconnecting...');
                
                // Clear device state and force reconnection
                this.spotifyDeviceId = null;
                this.spotifyPlayerReady.value = false;
                
                // Disconnect and reconnect
                if (this.spotifyPlayer) {
                    try {
                        await this.spotifyPlayer.disconnect();
                    } catch (disconnectError) {
                        console.debug('Error disconnecting during reconnect:', disconnectError);
                    }
                    this.spotifyPlayer = null;
                }
                
                // Retry with fresh connection
                const reinitialized = await this.initSpotifyPlayer();
                if (!reinitialized || !this.spotifyPlayer) {
                    console.error('Failed to reconnect Spotify player');
                    return;
                }
                
                // Wait for new device ID
                let attempts = 0;
                while (!this.spotifyDeviceId && attempts < 50) {
                    await new Promise((resolve) => setTimeout(resolve, 100));
                    attempts++;
                }
                
                if (!this.spotifyDeviceId || !this.spotifyPlayerReady.value) {
                    console.error('Spotify device ID not available after reconnect');
                    return;
                }
                
                // Retry resume with fresh token and device
                this.spotifyAccessToken = null;
                const freshToken = await this.getSpotifyAccessToken();
                if (!freshToken) {
                    console.error('No Spotify access token after reconnect');
                    return;
                }
                
                try {
                    await axios.put(
                        `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
                        {
                            uris: [uri],
                            position_ms: positionMs,
                        },
                        {
                            headers: {
                                Authorization: `Bearer ${freshToken}`,
                            },
                        },
                    );
                    
                    this.isPlaying.value = true;
                } catch (retryError) {
                    console.error('Failed to resume Spotify track after reconnect:', retryError);
                    throw retryError;
                }
            } else {
                console.error('Failed to resume Spotify track:', error);
                throw error;
            }
        }
    }

    private initAudio(): HTMLAudioElement {
        if (this.audio) {
            return this.audio;
        }

        this.audio = new Audio();
        this.audio.volume = this.volume.value;

        this.audio.addEventListener('timeupdate', () => {
            if (this.audio) {
                this.currentTime.value = this.audio.currentTime;
            }
        });

        this.audio.addEventListener('loadedmetadata', () => {
            if (this.audio) {
                this.duration.value = this.audio.duration;
            }
        });

        this.audio.addEventListener('play', () => {
            this.isPlaying.value = true;
        });

        this.audio.addEventListener('pause', () => {
            this.isPlaying.value = false;
        });

        this.audio.addEventListener('ended', () => {
            void this.handleTrackEnd();
        });

        return this.audio;
    }

    async play(): Promise<void> {
        if (this.queue.value.length === 0) return;

        const currentTrack = this.currentTrack.value || this.queue.value[this.currentIndex.value] || this.queue.value[0];
        if (!currentTrack) return;

        this.userPaused = false;

        if (this.isSpotifyTrack(currentTrack)) {
            // Check if we're resuming the same track that was paused
            // Only resume if: same track ID, position > 0, AND currentTrack is already set (not null)
            const isResumingSameTrack = 
                this.currentTrack.value?.id === currentTrack.id && 
                this.spotifyPausedPosition > 0 &&
                this.currentTrack.value !== null;
            
            if (isResumingSameTrack) {
                // Resume from saved position
                await this.resumeSpotifyTrack(this.spotifyPausedPosition);
            } else {
                // For new tracks or first play, use playSpotifyTrack() to ensure the correct track is loaded
                // Reset position to ensure we start from beginning
                this.spotifyPausedPosition = 0;
                await this.playSpotifyTrack(currentTrack);
            }
        } else {
            // Use HTML Audio API
            if (!this.audio && this.queue.value.length > 0) {
                await this.loadTrack(this.queue.value[0]);
                this.currentIndex.value = 0;
            }

            if (this.audio) {
                await this.audio.play();
            }
        }
    }

    async pause(options: { userInitiated?: boolean } = {}): Promise<void> {
        const { userInitiated = true } = options;
        this.userPaused = userInitiated;
        this.isPlaying.value = false;
        this.updateSpotifyPolling(false);
        const currentTrack = this.currentTrack.value;
        if (currentTrack) {
            // Intentionally avoid noisy console logs during tests and runtime
        }
        if (currentTrack && this.isSpotifyTrack(currentTrack)) {
            // Get the most up-to-date position directly from Spotify player state
            if (this.spotifyPlayer) {
                try {
                    // Get current state to capture the exact position at pause time
                    const state = await this.spotifyPlayer.getCurrentState();
                    if (state && !state.paused && state.position !== null) {
                        // Use the position from the player state (most accurate)
                        this.spotifyPausedPosition = state.position;
                    } else {
                        // Fallback to stored currentTime if state is not available
                        this.spotifyPausedPosition = this.currentTime.value * 1000;
                    }
                    
                    await this.spotifyPlayer.pause();
                } catch (error) {
                    console.debug('Could not pause Spotify track:', error);
                    // Fallback to stored currentTime if getCurrentState fails
                    this.spotifyPausedPosition = this.currentTime.value * 1000;
                }
            } else {
                // Fallback if player is not available
                this.spotifyPausedPosition = this.currentTime.value * 1000;
            }
        } else {
            // Use HTML Audio API
            if (this.audio) {
                this.audio.pause();
            }
        }
    }

    async togglePlay(): Promise<void> {
        if (this.isPlaying.value) {
            await this.pause({ userInitiated: true });
        } else {
            await this.play();
        }
    }

    async previous(options: PlayOptions = {}): Promise<void> {
        if (this.queue.value.length === 0) return;
        
        // Prevent concurrent navigation
        if (this.isNavigating) {
            return;
        }

        const newIndex = this.currentIndex.value > 0 ? this.currentIndex.value - 1 : this.queue.value.length - 1;

        const shouldAutoPlay = options.autoPlay ?? !this.userPaused;
        this.isNavigating = true;
        try {
            await this.playTrackAtIndex(newIndex, { ...options, autoPlay: shouldAutoPlay });
        } finally {
            this.isNavigating = false;
        }
    }

    async next(options: PlayOptions = {}): Promise<void> {
        if (this.queue.value.length === 0) return;
        
        // Prevent concurrent navigation
        if (this.isNavigating) {
            return;
        }

        const newIndex = this.currentIndex.value < this.queue.value.length - 1 ? this.currentIndex.value + 1 : 0;

        const shouldAutoPlay = options.autoPlay ?? !this.userPaused;
        this.isNavigating = true;
        try {
            await this.playTrackAtIndex(newIndex, { ...options, autoPlay: shouldAutoPlay });
        } finally {
            this.isNavigating = false;
        }
    }

    private async loadTrackDataForContext(): Promise<void> {
        if (this.currentIndex.value < 0 || this.queue.value.length === 0) return;

        const currentIdx = this.currentIndex.value;
        const queueLength = this.queue.value.length;

        // Calculate range: current + next 5 + previous 5
        const startIdx = Math.max(0, currentIdx - 5);
        const endIdx = Math.min(queueLength - 1, currentIdx + 5);

        // Collect IDs that need loading (don't have full metadata yet)
        const idsToLoad: number[] = [];
        for (let i = startIdx; i <= endIdx; i++) {
            const track = this.queue.value[i];
            if (track && track.id) {
                // Check if track already has metadata (artists, metadata.payload, etc.)
                const hasMetadata = track.artists || track.metadata?.payload;
                if (!hasMetadata) {
                    idsToLoad.push(track.id);
                }
            }
        }

        if (idsToLoad.length === 0) return;

        try {
            const action = AudioController.batchDetails();
            const response = await axios.post(action.url, {
                file_ids: idsToLoad,
            });

            // Merge loaded data into queue items
            const batchData = response.data;
            for (let i = startIdx; i <= endIdx; i++) {
                const track = this.queue.value[i];
                if (track && track.id && batchData[track.id]) {
                    // Merge loaded data into the track, preserving url
                    const url = track.url;
                    Object.assign(track, batchData[track.id]);
                    track.url = url; // Ensure URL is preserved
                }
            }

            // If current track was updated, refresh the ref
            if (this.currentTrack.value && batchData[this.currentTrack.value.id]) {
                const currentUrl = this.currentTrack.value.url;
                Object.assign(this.currentTrack.value, batchData[this.currentTrack.value.id]);
                this.currentTrack.value.url = currentUrl;
            }
        } catch (error) {
            console.error('Error loading track data for context:', error);
        }
    }

    private async ensureTrackMetadata(track: AudioTrack): Promise<void> {
        if (!track || typeof track.id !== 'number') {
            return;
        }

        const hasSourceInformation = Boolean(
            (track.source && track.source.toString().trim().length > 0) ||
                (track.mime_type && track.mime_type.toString().trim().length > 0) ||
                track.listing_metadata?.source ||
                track.metadata?.payload?.source,
        );

        if (hasSourceInformation) {
            return;
        }

        try {
            const action = AudioController.details({ file: track.id });
            const response = await axios.get(action.url);
            const payload = response.data;
            const existingUrl = track.url;

            Object.assign(track, payload);

            if (existingUrl) {
                track.url = existingUrl;
            }
        } catch (error) {
            console.error('Failed to load track metadata before playback:', error);
        }
    }

    private async loadTrack(track: AudioTrack): Promise<void> {
        // Set flag to ignore state updates during track loading
        this.isLoadingTrack = true;
        
        // Pause the previous player when switching tracks
        const previousTrack = this.currentTrack.value;
        if (previousTrack) {
            const wasSpotify = this.isSpotifyTrack(previousTrack);

            if (wasSpotify) {
                // Switching from Spotify - pause SDK player
                if (this.spotifyPlayer) {
                    try {
                        await this.spotifyPlayer.pause();
                    } catch (error) {
                        // Ignore errors if nothing is playing
                        console.debug('Could not pause Spotify track:', error);
                    }
                }
            } else {
                // Switching from non-Spotify - pause HTML audio
                if (this.audio) {
                    this.audio.pause();
                }
            }
        }

        await this.ensureTrackMetadata(track);

        if (this.isSpotifyTrack(track)) {
            // Set current track but defer playback to the caller
            this.currentTrack.value = track;
            this.isPlaying.value = false;
            // Stop polling when loading a new track
            this.updateSpotifyPolling(false);
            // Load track data for current + next 5 + previous 5 (fire and forget)
            void this.loadTrackDataForContext();
            
            // Clear loading flag after a short delay to allow new track to start
            setTimeout(() => {
                this.isLoadingTrack = false;
            }, 500);
            return;
        }

        // Use HTML Audio API for non-Spotify tracks
        // Only use url, never fall back to path (path is a disk path, not a valid URL)
        const audioUrl = track.url;
        if (!audioUrl) {
            console.error('Audio track missing URL:', track);
            return;
        }

        this.initAudio();
        if (!this.audio) return;

        this.audio.src = audioUrl;
        this.currentTrack.value = track;
        await this.audio.load();

        // Load track data for current + next 5 + previous 5 (fire and forget)
        void this.loadTrackDataForContext();
        
        // Clear loading flag after a short delay to allow new track to start
        setTimeout(() => {
            this.isLoadingTrack = false;
        }, 500);
    }

    async playTrackAtIndex(index: number, options: PlayOptions = {}): Promise<void> {
        if (index < 0 || index >= this.queue.value.length) return;

        const shouldAutoPlay = options.autoPlay ?? !this.userPaused;
        // Wait for pause to complete before loading new track
        await this.pause({ userInitiated: false });
        
        // Reset paused position, current time, and duration when switching tracks
        this.spotifyPausedPosition = 0;
        this.currentTime.value = 0;
        this.duration.value = 0;

        this.currentIndex.value = index;
        await this.loadTrack(this.queue.value[index]);

        this.userPaused = !shouldAutoPlay;

        if (shouldAutoPlay) {
            await this.play();
        }
    }

    async setQueueAndPlay(queue: AudioTrack[], startIndex: number = 0, options: PlayOptions = {}): Promise<void> {
        if (queue.length === 0) return;

        // Activate player when queue is set
        this.isActive.value = true;

        const shouldAutoPlay = options.autoPlay ?? true;
        await this.pause({ userInitiated: false });
        
        // Reset paused position, current time, and duration when setting new queue
        this.spotifyPausedPosition = 0;
        this.currentTime.value = 0;
        this.duration.value = 0;

        this.queue.value = [...queue];
        this.originalQueue = [...queue];
        this.isShuffled.value = false;
        this.originalCurrentTrackId = null;
        this.currentIndex.value = startIndex;
        await this.loadTrack(this.queue.value[startIndex]);

        this.userPaused = !shouldAutoPlay;

        if (shouldAutoPlay) {
            await this.play();
        }
    }

    async setQueueAndShuffle(shuffledQueue: AudioTrack[], originalQueue: AudioTrack[], options: PlayOptions = {}): Promise<void> {
        if (shuffledQueue.length === 0) return;

        // Activate player when queue is set
        this.isActive.value = true;

        const shouldAutoPlay = options.autoPlay ?? true;
        await this.pause({ userInitiated: false });
        
        // Stop polling to prevent async updates to spotifyPausedPosition
        this.updateSpotifyPolling(false);
        
        // Clear current track to ensure we're starting fresh
        this.currentTrack.value = null;

        // Reset paused position, current time, and duration when setting new queue
        this.spotifyPausedPosition = 0;
        this.currentTime.value = 0;
        this.duration.value = 0;

        this.queue.value = [...shuffledQueue];
        this.originalQueue = [...originalQueue];
        this.isShuffled.value = true;
        this.originalCurrentTrackId = null;
        this.currentIndex.value = 0;
        await this.loadTrack(this.queue.value[0]);

        // Reset paused position again right before play to ensure it's 0
        // (in case any async listeners updated it)
        this.spotifyPausedPosition = 0;

        this.userPaused = !shouldAutoPlay;

        if (shouldAutoPlay) {
            await this.play();
        }
    }

    private shuffleArray<T>(array: T[]): T[] {
        const shuffled = [...array];
        for (let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
        }
        return shuffled;
    }

    async toggleShuffle(): Promise<void> {
        if (this.queue.value.length === 0) return;

        if (this.isShuffled.value) {
            // Unshuffle: restore original order and keep playing current track
            const currentTrackId = this.currentTrack.value?.id;
            
            // Restore original queue
            this.queue.value = [...this.originalQueue];
            this.isShuffled.value = false;
            this.originalCurrentTrackId = null;

            if (!currentTrackId) {
                this.currentIndex.value = 0;
                return;
            }

            // Find currently playing track in original queue
            const originalIndex = this.originalQueue.findIndex((track) => track.id === currentTrackId);
            if (originalIndex === -1) {
                // Track not in original queue, just update index to 0
                this.currentIndex.value = 0;
                return;
            }

            // Set index to current track's position in original queue
            // Don't reload - keep playing whatever is currently playing
            this.currentIndex.value = originalIndex;
        } else {
            // Shuffle: save original order and current track before shuffling
            this.originalQueue = [...this.queue.value];
            this.originalCurrentTrackId = this.currentTrack.value?.id ?? null;

            // Shuffle the queue
            const shuffled = this.shuffleArray(this.queue.value);

            // Ensure original current track is not first (if it exists and queue has more than 1 item)
            if (this.originalCurrentTrackId && shuffled[0]?.id === this.originalCurrentTrackId && shuffled.length > 1) {
                // Swap first with another random position
                const swapIndex = Math.floor(Math.random() * (shuffled.length - 1)) + 1;
                [shuffled[0], shuffled[swapIndex]] = [shuffled[swapIndex], shuffled[0]];
            }

            this.queue.value = shuffled;
            this.isShuffled.value = true;
            this.currentIndex.value = 0;

            // Reset paused position when shuffling to ensure we start from beginning
            this.spotifyPausedPosition = 0;

            // Load and play the first track
            await this.loadTrack(this.queue.value[0]);
            this.userPaused = false;
            await this.play();
        }
    }

    seekTo(time: number): void {
        const currentTrack = this.currentTrack.value;
        if (currentTrack && this.isSpotifyTrack(currentTrack)) {
            // Use Spotify player (time is in seconds, Spotify expects milliseconds)
            if (this.spotifyPlayer) {
                try {
                    void this.spotifyPlayer.seek(time * 1000);
                } catch (error) {
                    // SDK seek() requires a track to be loaded first
                    console.debug('Could not seek Spotify track:', error);
                }
            }
        } else {
            // Use HTML Audio API
            if (this.audio) {
                this.audio.currentTime = time;
            }
        }
    }

    setVolume(volume: number): void {
        this.volume.value = Math.max(0, Math.min(1, volume));

        const currentTrack = this.currentTrack.value;
        if (currentTrack && this.isSpotifyTrack(currentTrack)) {
            // Use Spotify player
            if (this.spotifyPlayer) {
                try {
                    void this.spotifyPlayer.setVolume(this.volume.value);
                } catch (error) {
                    // SDK setVolume() might fail if player isn't ready
                    console.debug('Could not set Spotify volume:', error);
                }
            }
        } else {
            // Use HTML Audio API
            if (this.audio) {
                this.audio.volume = this.volume.value;
            }
        }
    }

    cleanup(): void {
        // Stop polling
        if (this.spotifyPollInterval !== null) {
            clearInterval(this.spotifyPollInterval);
            this.spotifyPollInterval = null;
        }

        // Pause and stop HTML Audio playback
        if (this.audio) {
            this.audio.pause();
            this.audio.src = '';
            this.audio.load();
        }

        // Disconnect Spotify player
        if (this.spotifyPlayer) {
            this.spotifyPlayer.disconnect();
            this.spotifyPlayer = null;
            this.spotifyPlayerReady.value = false;
            this.spotifyDeviceId = null;
        }

        // Clear state
        this.currentTrack.value = null;
        this.queue.value = [];
        this.currentIndex.value = -1;
        this.isPlaying.value = false;
        this.currentTime.value = 0;
        this.duration.value = 0;
        this.spotifyAccessToken = null;
        this.userPaused = false;
        this.isShuffled.value = false;
        this.repeatMode.value = 'off';
        this.originalQueue = [];
        this.originalCurrentTrackId = null;
    }

    private async handleSpotifyTrackEnd(): Promise<void> {
        // Pause current track before advancing
        if (this.spotifyPlayer) {
            try {
                await this.spotifyPlayer.pause();
            } catch (error) {
                console.debug('Could not pause Spotify track:', error);
            }
        }
        await this.handleTrackEnd();
    }

    private async handleTrackEnd(): Promise<void> {
        if (this.repeatMode.value === 'one') {
            // Repeat single track - restart current track
            if (this.currentTrack.value) {
                // Don't call pause() - just reset position and play
                // pause() is already called by handleSpotifyTrackEnd() or the ended event
                this.currentTime.value = 0;
                if (this.isSpotifyTrack(this.currentTrack.value)) {
                    this.spotifyPausedPosition = 0;
                    // For Spotify, we need to stop current playback first
                    if (this.spotifyPlayer) {
                        try {
                            await this.spotifyPlayer.pause();
                        } catch (error) {
                            console.debug('Could not pause Spotify track for repeat:', error);
                        }
                    }
                } else if (this.audio) {
                    this.audio.pause();
                }
                await this.play();
            }
        } else if (this.repeatMode.value === 'all') {
            // Repeat all - if at end, go to first track, otherwise next
            if (this.currentIndex.value >= this.queue.value.length - 1) {
                // At end of queue, go to first track
                await this.playTrackAtIndex(0, { autoPlay: true });
            } else {
                // Not at end, go to next
                await this.next({ autoPlay: true });
            }
        } else {
            // Repeat off - go to next track (or stop if at end)
            await this.next({ autoPlay: true });
        }
    }

    toggleRepeat(): void {
        if (this.repeatMode.value === 'off') {
            this.repeatMode.value = 'all';
        } else if (this.repeatMode.value === 'all') {
            this.repeatMode.value = 'one';
        } else {
            this.repeatMode.value = 'off';
        }
    }
}

const audioPlayerManager = new AudioPlayerManager();

// Cleanup on page unload (handles browser navigation, refresh, etc.)
if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', () => {
        audioPlayerManager.cleanup();
    });
}

if (import.meta.hot) {
    import.meta.hot.dispose(() => {
        audioPlayerManager.cleanup();
    });
}

export function useAudioPlayer() {
    return {
        // State
        currentTrack: audioPlayerManager.currentTrackRef,
        queue: audioPlayerManager.queueRef,
        currentIndex: audioPlayerManager.currentIndexRef,
        isPlaying: audioPlayerManager.isPlayingRef,
        currentTime: audioPlayerManager.currentTimeRef,
        duration: audioPlayerManager.durationRef,
        volume: audioPlayerManager.volumeRef,
        spotifyPlayerReady: audioPlayerManager.spotifyPlayerReadyRef,
        isShuffled: audioPlayerManager.isShuffledRef,
        repeatMode: audioPlayerManager.repeatModeRef,
        isActive: audioPlayerManager.isActiveRef,

        // Actions
        play: () => audioPlayerManager.play(),
        pause: () => audioPlayerManager.pause(),
        togglePlay: () => audioPlayerManager.togglePlay(),
        previous: (options?: PlayOptions) => audioPlayerManager.previous(options),
        next: (options?: PlayOptions) => audioPlayerManager.next(options),
        seekTo: (time: number) => audioPlayerManager.seekTo(time),
        playTrackAtIndex: (index: number, options?: PlayOptions) => audioPlayerManager.playTrackAtIndex(index, options),
        setQueueAndPlay: (queue: AudioTrack[], startIndex?: number, options?: PlayOptions) =>
            audioPlayerManager.setQueueAndPlay(queue, startIndex, options),
        setQueueAndShuffle: (shuffledQueue: AudioTrack[], originalQueue: AudioTrack[], options?: PlayOptions) =>
            audioPlayerManager.setQueueAndShuffle(shuffledQueue, originalQueue, options),
        setVolume: (volume: number) => audioPlayerManager.setVolume(volume),
        toggleShuffle: () => audioPlayerManager.toggleShuffle(),
        toggleRepeat: () => audioPlayerManager.toggleRepeat(),
        cleanup: () => audioPlayerManager.cleanup(),
    };
}
