import { ref, reactive } from 'vue';

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
  
  // Audio element state
  currentTime: 0,
  duration: 0,
  volume: 1,
  
  // UI state
  isPlayerVisible: false,
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
    console.log('Playlist shuffled:', audioStore.playlist.length, 'tracks');
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
    if (audioStore.currentIndex < audioStore.playlist.length - 1) {
      audioStore.currentIndex++;
      const nextTrack = audioStore.playlist[audioStore.currentIndex];
      await this.setCurrentFile(nextTrack, loadFileDetails);
      return nextTrack;
    }
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
  
  reset() {
    audioStore.currentFile = null;
    audioStore.isPlaying = false;
    audioStore.isPlayerLoading = false;
    audioStore.playlist = [];
    audioStore.currentIndex = -1;
    audioStore.isShuffled = false;
    audioStore.currentTime = 0;
    audioStore.duration = 0;
    audioStore.isPlayerVisible = false;
  }
};
