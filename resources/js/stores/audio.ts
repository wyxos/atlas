/// <reference types="spotify-web-playback-sdk" />

import { ref, computed } from 'vue';
import axios from 'axios';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';


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
  skipPause?: boolean;
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
  private currentTrack = ref<AudioTrack | null>(null);
  private queue = ref<AudioTrack[]>([]);
  private currentIndex = ref<number>(-1);
  private isPlaying = ref<boolean>(false);
  private currentTime = ref<number>(0);
  private duration = ref<number>(0);
  private volume = ref<number>(1);

  // Reactive refs
  readonly currentTrackRef = computed(() => this.currentTrack.value);
  readonly queueRef = computed(() => [...this.queue.value]);
  readonly currentIndexRef = computed(() => this.currentIndex.value);
  readonly isPlayingRef = computed(() => this.isPlaying.value);
  readonly currentTimeRef = computed(() => this.currentTime.value);
  readonly durationRef = computed(() => this.duration.value);
  readonly volumeRef = computed(() => this.volume.value);
  readonly spotifyPlayerReadyRef = computed(() => this.spotifyPlayerReady.value);

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
        getOAuthToken: (cb: (token: string) => void) => {
          cb(token);
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

        // Track previous playing state before updating
        const wasPlaying = this.isPlaying.value;
        this.isPlaying.value = !state.paused;
        this.currentTime.value = state.position / 1000; // Convert ms to seconds
        this.duration.value = state.duration / 1000; // Convert ms to seconds

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

  private async playSpotifyTrack(track: AudioTrack): Promise<void> {
    const uri = this.getSpotifyTrackUri(track);
    if (!uri) {
      console.error('Invalid Spotify track URI:', track);
    return;
    }

    // Reset track end flag and position tracking for new track
    this.spotifyTrackEndHandled = false;
    this.spotifyLastPosition = 0;

    const initialized = await this.initSpotifyPlayer();
    if (!initialized || !this.spotifyPlayer) {
      console.error('Spotify player not initialized');
      return;
    }

    // Pause current playback if there's a track loaded
    if (this.spotifyPlayer && this.currentTrack.value) {
      try {
        await this.spotifyPlayer.pause();
      } catch (error) {
        // Ignore errors if nothing is playing or no track loaded
        console.debug('Could not pause Spotify track:', error);
      }
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
      // Pause current playback if there's a track loaded
      if (this.spotifyPlayer && this.currentTrack.value) {
        try {
          await this.spotifyPlayer.pause();
        } catch (error) {
          // Ignore errors if nothing is playing
          console.debug('Could not pause Spotify track:', error);
        }
      }

      // Transfer playback to our device and play the track
      const token = await this.getSpotifyAccessToken();
      if (!token) {
        console.error('No Spotify access token');
        return;
      }

      // Use Spotify Web API to start playback
      await axios.put(
        `https://api.spotify.com/v1/me/player/play?device_id=${this.spotifyDeviceId}`,
        {
          uris: [uri],
        },
        {
          headers: {
            Authorization: `Bearer ${token}`,
          },
        },
      );

      this.currentTrack.value = track;
    } catch (error) {
      console.error('Failed to play Spotify track:', error);
      throw error;
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
      this.next({ autoPlay: true });
    });

    return this.audio;
  }

  async play(): Promise<void> {
    if (this.queue.value.length === 0) return;

    const currentTrack = this.currentTrack.value || this.queue.value[this.currentIndex.value] || this.queue.value[0];
    if (!currentTrack) return;

    if (this.isSpotifyTrack(currentTrack)) {
      // For Spotify tracks, always use playSpotifyTrack() to ensure the correct track is loaded
      // Using resume() can resume the wrong track if we just switched tracks
      await this.playSpotifyTrack(currentTrack);
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

  async pause(): Promise<void> {
    const currentTrack = this.currentTrack.value;
    if (currentTrack && this.isSpotifyTrack(currentTrack)) {
      // Use Spotify SDK pause
      if (this.spotifyPlayer) {
        try {
          await this.spotifyPlayer.pause();
        } catch (error) {
          console.debug('Could not pause Spotify track:', error);
        }
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
      await this.pause();
    } else {
      await this.play();
    }
  }

  async previous(options: PlayOptions = {}): Promise<void> {
    if (this.queue.value.length === 0) return;

    const newIndex = this.currentIndex.value > 0
      ? this.currentIndex.value - 1
      : this.queue.value.length - 1;

    const shouldAutoPlay = options.autoPlay ?? this.isPlaying.value;
    const skipPause = options.skipPause ?? false;
    await this.playTrackAtIndex(newIndex, { autoPlay: shouldAutoPlay, skipPause });
  }

  async next(options: PlayOptions = {}): Promise<void> {
    if (this.queue.value.length === 0) return;

    const newIndex = this.currentIndex.value < this.queue.value.length - 1
      ? this.currentIndex.value + 1
      : 0;

    const shouldAutoPlay = options.autoPlay ?? this.isPlaying.value;
    const skipPause = options.skipPause ?? false;
    await this.playTrackAtIndex(newIndex, { autoPlay: shouldAutoPlay, skipPause });
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

  private async loadTrack(track: AudioTrack): Promise<void> {
    // Pause the previous player when switching tracks
    const previousTrack = this.currentTrack.value;
    if (previousTrack) {
      const wasSpotify = this.isSpotifyTrack(previousTrack);
      const isSpotify = this.isSpotifyTrack(track);

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

    if (this.isSpotifyTrack(track)) {
      // Use Spotify player
      await this.playSpotifyTrack(track);
      // Load track data for current + next 5 + previous 5 (fire and forget)
      void this.loadTrackDataForContext();
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
  }

  async playTrackAtIndex(index: number, options: PlayOptions = {}): Promise<void> {
    if (index < 0 || index >= this.queue.value.length) return;

    const shouldAutoPlay = options.autoPlay ?? this.isPlaying.value;
    const skipPause = options.skipPause ?? false;
    // Wait for pause to complete before loading new track unless skipping
    if (!skipPause) {
      await this.pause();
    }

    this.currentIndex.value = index;
    await this.loadTrack(this.queue.value[index]);

    if (shouldAutoPlay) {
      await this.play();
    }
  }

  async setQueueAndPlay(queue: AudioTrack[], startIndex: number = 0, options: PlayOptions = {}): Promise<void> {
    if (queue.length === 0) return;

    const shouldAutoPlay = options.autoPlay ?? true;
    const skipPause = options.skipPause ?? false;
    if (!skipPause) {
      await this.pause();
    }

    this.queue.value = [...queue];
    this.currentIndex.value = startIndex;
    await this.loadTrack(this.queue.value[startIndex]);

    if (shouldAutoPlay) {
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
    await this.next({ autoPlay: true, skipPause: true });
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

    // Actions
    play: () => audioPlayerManager.play(),
    pause: () => audioPlayerManager.pause(),
    togglePlay: () => audioPlayerManager.togglePlay(),
    previous: (options?: PlayOptions) => audioPlayerManager.previous(options),
    next: (options?: PlayOptions) => audioPlayerManager.next(options),
    seekTo: (time: number) => audioPlayerManager.seekTo(time),
    playTrackAtIndex: (index: number, options?: PlayOptions) =>
      audioPlayerManager.playTrackAtIndex(index, options),
    setQueueAndPlay: (queue: AudioTrack[], startIndex?: number, options?: PlayOptions) =>
      audioPlayerManager.setQueueAndPlay(queue, startIndex, options),
    setVolume: (volume: number) => audioPlayerManager.setVolume(volume),
    cleanup: () => audioPlayerManager.cleanup(),
  };
}
