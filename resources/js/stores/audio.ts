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
}

interface SpotifyPlayer {
  connect(): Promise<boolean>;
  disconnect(): void;
  getCurrentState(): Promise<SpotifyPlaybackState | null>;
  addListener(event: 'ready' | 'not_ready', callback: (data: { device_id: string }) => void): void;
  addListener(event: 'player_state_changed', callback: (state: SpotifyPlaybackState) => void): void;
  addListener(event: 'initialization_error' | 'authentication_error' | 'account_error' | 'playback_error', callback: (data: { message: string }) => void): void;
  addListener(event: string, callback: (...args: any[]) => void): void;
  removeListener(event: string, callback: (...args: any[]) => void): void;
  pause(): Promise<void>;
  resume(): Promise<void>;
  togglePlay(): Promise<void>;
  seek(positionMs: number): Promise<void>;
  setVolume(volume: number): Promise<void>;
  getVolume(): Promise<number>;
}

interface SpotifyPlaybackState {
  paused: boolean;
  position: number;
  duration: number;
  track_window: {
    current_track: {
      uri: string;
      id: string;
      name: string;
      album: {
        uri: string;
        name: string;
        images: Array<{ url: string }>;
      };
      artists: Array<{ uri: string; name: string }>;
    } | null;
  };
}

class AudioPlayerManager {
  private audio: HTMLAudioElement | null = null;
  private spotifyPlayer: SpotifyPlayer | null = null;
  private spotifyAccessToken: string | null = null;
  private spotifyDeviceId: string | null = null;
  private spotifyPlayerReady = ref<boolean>(false);
  private spotifyTrackEndHandled = false;
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

      const Spotify = (window as any).Spotify;
      if (!Spotify) {
        console.error('Spotify SDK not available');
        return false;
      }

      this.spotifyPlayer = new Spotify.Player({
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

      this.spotifyPlayer.addListener('player_state_changed', (state: SpotifyPlaybackState) => {
        if (!state) return;

        this.isPlaying.value = !state.paused;
        this.currentTime.value = state.position / 1000; // Convert ms to seconds
        this.duration.value = state.duration / 1000; // Convert ms to seconds

        // Handle track end - detect when track has finished
        if (state.duration > 0) {
          const remaining = state.duration - state.position;
          // Track ended if remaining time is very small (less than 500ms) and we haven't handled it yet
          if (remaining < 500 && !this.spotifyTrackEndHandled && this.currentTrack.value) {
            this.spotifyTrackEndHandled = true;
            // Advance to next track in queue
            void this.next({ autoPlay: true });
          } else if (remaining >= 500) {
            // Reset the flag when we're not near the end
            this.spotifyTrackEndHandled = false;
          }
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

    // Reset track end flag for new track
    this.spotifyTrackEndHandled = false;

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

    try {
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
      // Use Spotify player
      if (!this.spotifyPlayer || !this.spotifyPlayerReady.value) {
        await this.initSpotifyPlayer();
      }
      if (this.spotifyPlayer) {
        await this.spotifyPlayer.resume();
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

  pause(): void {
    const currentTrack = this.currentTrack.value;
    if (currentTrack && this.isSpotifyTrack(currentTrack)) {
      // Use Spotify player
      if (this.spotifyPlayer) {
        void this.spotifyPlayer.pause();
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
      this.pause();
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
    await this.playTrackAtIndex(newIndex, { autoPlay: shouldAutoPlay });
  }

  async next(options: PlayOptions = {}): Promise<void> {
    if (this.queue.value.length === 0) return;

    const newIndex = this.currentIndex.value < this.queue.value.length - 1
      ? this.currentIndex.value + 1
      : 0;

    const shouldAutoPlay = options.autoPlay ?? this.isPlaying.value;
    await this.playTrackAtIndex(newIndex, { autoPlay: shouldAutoPlay });
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
    // Pause the previous player if switching between Spotify and non-Spotify
    const previousTrack = this.currentTrack.value;
    if (previousTrack) {
      const wasSpotify = this.isSpotifyTrack(previousTrack);
      const isSpotify = this.isSpotifyTrack(track);

      if (wasSpotify && !isSpotify) {
        // Switching from Spotify to non-Spotify - pause Spotify
        if (this.spotifyPlayer) {
          await this.spotifyPlayer.pause();
        }
      } else if (!wasSpotify && isSpotify) {
        // Switching from non-Spotify to Spotify - pause HTML audio
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
    this.pause();

    this.currentIndex.value = index;
    await this.loadTrack(this.queue.value[index]);

    if (shouldAutoPlay) {
      await this.play();
    }
  }

  async setQueueAndPlay(queue: AudioTrack[], startIndex: number = 0, options: PlayOptions = {}): Promise<void> {
    if (queue.length === 0) return;

    const shouldAutoPlay = options.autoPlay ?? true;
    this.pause();

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
        void this.spotifyPlayer.seek(time * 1000);
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
        void this.spotifyPlayer.setVolume(this.volume.value);
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
}

const audioPlayerManager = new AudioPlayerManager();

// Cleanup on page unload (handles browser navigation, refresh, etc.)
if (typeof window !== 'undefined') {
  window.addEventListener('beforeunload', () => {
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
