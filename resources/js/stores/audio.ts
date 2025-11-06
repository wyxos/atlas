import { ref, computed } from 'vue';

export interface AudioTrack {
  id: number;
  url?: string;
  path?: string;
  [key: string]: any;
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
      this.next();
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

  async previous(): Promise<void> {
    if (this.queue.value.length === 0) return;

    const newIndex = this.currentIndex.value > 0
      ? this.currentIndex.value - 1
      : this.queue.value.length - 1;

    await this.playTrackAtIndex(newIndex);
  }

  async next(): Promise<void> {
    if (this.queue.value.length === 0) return;

    const newIndex = this.currentIndex.value < this.queue.value.length - 1
      ? this.currentIndex.value + 1
      : 0;

    await this.playTrackAtIndex(newIndex);
  }

  private async loadTrack(track: AudioTrack): Promise<void> {
    const audioUrl = track.url || track.path;
    if (!audioUrl) return;

    this.initAudio();
    if (!this.audio) return;

    this.audio.src = audioUrl;
    this.currentTrack.value = track;
    await this.audio.load();
  }

  async playTrackAtIndex(index: number): Promise<void> {
    if (index < 0 || index >= this.queue.value.length) return;

    const wasPlaying = this.isPlaying.value;
    this.pause();

    this.currentIndex.value = index;
    await this.loadTrack(this.queue.value[index]);

    if (wasPlaying) {
      await this.play();
    }
  }

  async setQueueAndPlay(queue: AudioTrack[], startIndex: number = 0): Promise<void> {
    if (queue.length === 0) return;

    const wasPlaying = this.isPlaying.value;
    this.pause();

    this.queue.value = [...queue];
    this.currentIndex.value = startIndex;
    await this.loadTrack(this.queue.value[startIndex]);

    if (wasPlaying) {
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
    previous: () => audioPlayerManager.previous(),
    next: () => audioPlayerManager.next(),
    seekTo: (time: number) => audioPlayerManager.seekTo(time),
    playTrackAtIndex: (index: number) => audioPlayerManager.playTrackAtIndex(index),
    setQueueAndPlay: (queue: AudioTrack[], startIndex?: number) =>
      audioPlayerManager.setQueueAndPlay(queue, startIndex),
    setVolume: (volume: number) => audioPlayerManager.setVolume(volume),
  };
}
