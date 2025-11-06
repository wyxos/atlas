import { ref, computed } from 'vue';

export interface AudioTrack {
  id: number;
  url?: string;
  path?: string;
  [key: string]: any;
}

interface PlayOptions {
  autoPlay?: boolean;
}

class AudioPlayerManager {
  private audio: HTMLAudioElement | null = null;
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
    if (!this.audio && this.queue.value.length > 0) {
      await this.loadTrack(this.queue.value[0]);
      this.currentIndex.value = 0;
    }

    if (this.audio) {
      await this.audio.play();
    }
  }

  pause(): void {
    if (this.audio) {
      this.audio.pause();
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

  private async loadTrack(track: AudioTrack): Promise<void> {
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
    if (this.audio) {
      this.audio.currentTime = time;
    }
  }

  setVolume(volume: number): void {
    this.volume.value = Math.max(0, Math.min(1, volume));
    if (this.audio) {
      this.audio.volume = this.volume.value;
    }
  }
}

const audioPlayerManager = new AudioPlayerManager();

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
  };
}
