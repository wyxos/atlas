import { reactive } from 'vue';

// Repeat modes
export type RepeatMode = 'off' | 'all' | 'one';

// Global audio player state
export const audioStore = reactive({
  // Player state
  currentFile: null as any,
  isPlaying: false,
  isPlayerLoading: false,

  // Playlist state
  playlist: [] as any[],
  currentIndex: -1,
  isShuffled: false,
  repeatMode: 'off' as RepeatMode,

  // Audio element state
  currentTime: 0,
  duration: 0,
  volume: 1,

  // UI state
  isPlayerVisible: false,
  isPlayerMinimized: false,
});

// Audio player actions
export const audioActions = {
  async setCurrentFile(file: any, loadFileDetails?: (id: number, priority?: boolean) => Promise<any>) {
    audioStore.isPlayerVisible = !!file; // Set visibility immediately

    // If the file doesn't have complete data (metadata, covers, artists, albums, love status) and we have a loader function, load it
    const needsFullData = file && (
      !file.metadata ||
      !file.covers ||
      !file.artists ||
      !file.albums ||
      file.loved === undefined ||
      file.liked === undefined ||
      file.disliked === undefined
    );

    if (needsFullData && loadFileDetails) {
      try {
        const fullFileData = await loadFileDetails(file.id, true);
        if (fullFileData) {
          audioStore.currentFile = fullFileData;
        } else {
          audioStore.currentFile = file;
        }
      } catch (error) {
        console.error('Failed to load file details:', error);
        audioStore.currentFile = file;
      }
    } else {
      audioStore.currentFile = file;
    }
  },

  setPlaying(playing: boolean) {
    audioStore.isPlaying = playing;
  },

  setLoading(loading: boolean) {
    audioStore.isPlayerLoading = loading;
  },

  setPlaylist(playlist: any[], currentTrack?: any) {
    audioStore.playlist = [...playlist];
    if (currentTrack) {
      audioStore.currentIndex = playlist.findIndex(track => track.id === currentTrack.id);
    } else {
      audioStore.currentIndex = 0;
    }
    audioStore.isShuffled = false;
  },

  shufflePlaylist() {
    if (audioStore.playlist.length === 0) {
      console.warn('No playlist to shuffle');
      return;
    }

    // Create a copy of the current playlist
    const playlistCopy = [...audioStore.playlist];
    const currentTrack = audioStore.currentFile;

    // Remove current track from the copy to shuffle the rest
    const otherTracks = playlistCopy.filter(track => track.id !== currentTrack?.id);

    // Shuffle the other tracks
    const shuffledOthers = otherTracks.sort(() => Math.random() - 0.5);

    if (currentTrack) {
      // Keep current track at the beginning, shuffle the rest
      audioStore.playlist = [currentTrack, ...shuffledOthers];
      audioStore.currentIndex = 0;
    } else {
      // No current track, shuffle everything
      audioStore.playlist = shuffledOthers;
      audioStore.currentIndex = 0;
    }

    audioStore.isShuffled = true;
  },

  getNextTrack(): any | null {
    if (audioStore.playlist.length === 0 || audioStore.currentIndex >= audioStore.playlist.length - 1) {
      return null;
    }
    return audioStore.playlist[audioStore.currentIndex + 1];
  },

  getPreviousTrack(): any | null {
    if (audioStore.playlist.length === 0 || audioStore.currentIndex <= 0) {
      return null;
    }
    return audioStore.playlist[audioStore.currentIndex - 1];
  },

  async moveToNext(loadFileDetails?: (id: number, priority?: boolean) => Promise<any>) {
    // Handle repeat one mode - replay current track
    if (audioStore.repeatMode === 'one' && audioStore.currentFile) {
      await this.setCurrentFile(audioStore.currentFile, loadFileDetails);
      return audioStore.currentFile;
    }

    // Normal next track logic
    if (audioStore.currentIndex < audioStore.playlist.length - 1) {
      audioStore.currentIndex++;
      const nextTrack = audioStore.playlist[audioStore.currentIndex];
      await this.setCurrentFile(nextTrack, loadFileDetails);
      return nextTrack;
    }

    // Handle repeat all mode - go back to first track
    if (audioStore.repeatMode === 'all' && audioStore.playlist.length > 0) {
      audioStore.currentIndex = 0;
      const firstTrack = audioStore.playlist[0];
      await this.setCurrentFile(firstTrack, loadFileDetails);
      return firstTrack;
    }

    // Repeat off mode or no tracks - return null
    return null;
  },

  async moveToPrevious(loadFileDetails?: (id: number, priority?: boolean) => Promise<any>) {
    if (audioStore.currentIndex > 0) {
      audioStore.currentIndex--;
      const prevTrack = audioStore.playlist[audioStore.currentIndex];
      await this.setCurrentFile(prevTrack, loadFileDetails);
      return prevTrack;
    }
    return null;
  },

  updateTime(time: number) {
    audioStore.currentTime = time;
  },

  updateDuration(duration: number) {
    audioStore.duration = duration;
  },

  updateVolume(volume: number) {
    audioStore.volume = volume;
  },

  findAndPlayInQueue(fileId: number, loadFileDetails?: (id: number, priority?: boolean) => Promise<any>): boolean {
    // Find the file in the current playlist
    const index = audioStore.playlist.findIndex(track => track.id === fileId);
    if (index !== -1) {
      // Found in queue, set as current and play
      audioStore.currentIndex = index;
      const track = audioStore.playlist[index];
      this.setCurrentFile(track, loadFileDetails);
      return true;
    }
    return false;
  },

  toggleRepeat() {
    // Cycle through repeat modes: off -> all -> one -> off
    switch (audioStore.repeatMode) {
      case 'off':
        audioStore.repeatMode = 'all';
        break;
      case 'all':
        audioStore.repeatMode = 'one';
        break;
      case 'one':
        audioStore.repeatMode = 'off';
        break;
    }
  },

  toggleMinimized() {
    audioStore.isPlayerMinimized = !audioStore.isPlayerMinimized;
  },

  reset() {
    audioStore.currentFile = null;
    audioStore.isPlaying = false;
    audioStore.isPlayerLoading = false;
    audioStore.playlist = [];
    audioStore.currentIndex = -1;
    audioStore.isShuffled = false;
    audioStore.repeatMode = 'off';
    audioStore.currentTime = 0;
    audioStore.duration = 0;
    audioStore.isPlayerVisible = false;
    audioStore.isPlayerMinimized = false;
  },

  // Scroll to current track functionality
  scrollToCurrentTrack() {
    // Emit a custom event that pages with RecycleScroller can listen to
    if (typeof window !== 'undefined' && audioStore.currentFile) {
      const event = new CustomEvent('scrollToCurrentTrack', {
        detail: {
          currentFileId: audioStore.currentFile.id,
          currentIndex: audioStore.currentIndex
        }
      });
      window.dispatchEvent(event);
    }
  }
};
