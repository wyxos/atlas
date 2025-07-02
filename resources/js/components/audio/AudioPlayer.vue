<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, inject } from 'vue';
import { audioStore, audioActions } from '@/stores/audioStore';
import { Play, Pause, SkipBack, SkipForward, Shuffle } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';


const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentTime = ref(audioStore.currentTime);
const duration = ref(audioStore.duration);
const volume = ref(audioStore.volume);
const pendingOperations = ref<(() => void)[]>([]);
const observer = ref<MutationObserver | null>(null);
const observerTimeout = ref<number | null>(null);

// Try to get loadFileDetails function from parent context
const loadFileDetails = inject<(id: number, priority?: boolean) => Promise<any>>('loadFileDetails', () => Promise.resolve(null));

// Get the current file title for display
const currentTitle = computed(() => {
  if (!audioStore.currentFile) return 'No file selected';
  return audioStore.currentFile.metadata?.payload?.title || 'Untitled';
});

// Get the current file artist for display
const currentArtist = computed(() => {
  if (!audioStore.currentFile) return 'Unknown Artist';
  return audioStore.currentFile.artists && audioStore.currentFile.artists.length > 0
    ? audioStore.currentFile.artists[0].name
    : 'Unknown Artist';
});

// Format time in MM:SS format
function formatTime(seconds: number): string {
  if (isNaN(seconds) || !isFinite(seconds)) return '00:00';
  const mins = Math.floor(seconds / 60);
  const secs = Math.floor(seconds % 60);
  return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

// Seek to a specific position in the audio
function seekTo(event: MouseEvent): void {
  if (!audioPlayer.value || !duration.value) return;

  const progressBar = event.currentTarget as HTMLElement;
  const rect = progressBar.getBoundingClientRect();
  const offsetX = event.clientX - rect.left;
  const percentage = offsetX / rect.width;

    const newTime = percentage * duration.value;
    audioPlayer.value.currentTime = newTime;
    audioActions.updateTime(newTime);
}

function excerpt(text: string, length = 30): string {
  if (!text) return '';
  return text.length > length ? text.substring(0, length) + '...' : text;
}

// Toggle play/pause
function togglePlayPause(): void {
  if (audioStore.isPlaying) {
    audioActions.setPlaying(false);
  } else if (audioStore.currentFile) {
    audioActions.setPlaying(true);
    updatePlayState(true);
  }
}

// Handle previous track
async function handlePrevious(): Promise<void> {
  const previousTrack = await audioActions.moveToPrevious(loadFileDetails);
  if (previousTrack) {
    audioActions.setPlaying(true);
  }
}

// Handle next track
async function handleNext(): Promise<void> {
  const nextTrack = await audioActions.moveToNext(loadFileDetails);
  if (nextTrack) {
    audioActions.setPlaying(true);
  }
}

// Handle shuffle
function handleShuffle(): void {
  audioActions.shufflePlaylist();
}

// Function to handle play/pause based on isPlaying prop
function updatePlayState(newIsPlaying: boolean): void {
  if (!audioPlayer.value) {
    // Queue the operation for when the audio player is available
    pendingOperations.value.push(() => updatePlayState(newIsPlaying));
    return;
  }

  if (newIsPlaying) {
    // Use a promise to ensure play() is handled properly
    const playPromise = audioPlayer.value.play();

    // Handle play promise to catch any errors
    if (playPromise !== undefined) {
      playPromise.catch(() => {
        // Silently handle the error
      });
    }
  } else {
    audioPlayer.value.pause();
  }
  audioActions.setPlaying(newIsPlaying);
}

// Function to update the current file
function updateCurrentFile(newFile: any): void {
  if (!audioPlayer.value) {
    // Queue the operation for when the audio player is available
    pendingOperations.value.push(() => updateCurrentFile(newFile));
    return;
  }

  if (newFile) {
    audioPlayer.value.src = `/audio/stream/${newFile.id}`;
    // Explicitly load the audio before attempting to play
    audioPlayer.value.load();
    if (audioStore.isPlaying) {
      // Use a promise to ensure play() is called after the audio is loaded
      const playPromise = audioPlayer.value.play();

      // Handle play promise to catch any errors
      if (playPromise !== undefined) {
        playPromise.catch(() => {
          // Silently handle the error
        });
      }
    }
  }
  audioActions.setCurrentFile(newFile);
}

// Function to process pending operations
function processPendingOperations(): void {
  while (pendingOperations.value.length > 0) {
    const operation = pendingOperations.value.shift();
    if (operation) operation();
  }
}

// Watch for changes to isPlaying and currentFile
watch(() => audioStore.isPlaying, updatePlayState);
watch(() => audioStore.currentFile, updateCurrentFile);

// Initialize the audio player when the component is mounted
onMounted(() => {
  // If audioPlayer is already available, process pending operations
  if (!audioPlayer.value) {
    processPendingOperations();
    return;
  }

  // Set up a MutationObserver to detect when the audioPlayer ref is attached
  observer.value = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      if (mutation.type === 'childList' && audioPlayer.value) {
        // audioPlayer is now available, process pending operations
        processPendingOperations();
        // Disconnect the observer as we no longer need it
        if (observer.value) {
          observer.value.disconnect();
          observer.value = null;
        }
        // Clear the timeout
        if (observerTimeout.value !== null) {
          clearTimeout(observerTimeout.value);
          observerTimeout.value = null;
        }
        break;
      }
    }
  });

  // Start observing the document body for changes
  observer.value.observe(document.body, { childList: true, subtree: true });

  // Clean up the observer after a reasonable timeout (e.g., 5 seconds)
  observerTimeout.value = window.setTimeout(() => {
    if (observer.value) {
      observer.value.disconnect();
      observer.value = null;
    }
    observerTimeout.value = null;
  }, 5000);

  // Sync audio element state with global store
  watch(currentTime, newVal => audioActions.updateTime(newVal));
  watch(duration, newVal => audioActions.updateDuration(newVal));
  watch(volume, newVal => audioActions.updateVolume(newVal));
});

// Clean up resources when the component is unmounted
onBeforeUnmount(() => {
  // Clean up the observer if it exists
  if (observer.value) {
    observer.value.disconnect();
    observer.value = null;
  }

  // Clear the timeout if it exists
  if (observerTimeout.value !== null) {
    clearTimeout(observerTimeout.value);
    observerTimeout.value = null;
  }

  // Clear any pending operations
  pendingOperations.value = [];
});
</script>

<template>
  <div class="fixed bottom-0 left-0 bg-card border-t border-border p-4 w-full md:static" v-if="audioStore.isPlayerLoading || audioStore.currentFile">
    <audio
      ref="audioPlayer"
      class="hidden"
      @ended="handleNext"
      @timeupdate="currentTime = audioPlayer?.currentTime || 0"
      @loadedmetadata="duration = audioPlayer?.duration || 0"
      @volumechange="volume = audioPlayer?.volume || 1"
    ></audio>

<!-- Start of player desktop -->
    <div class="hidden md:flex gap-4 items-center">
      <!-- Loading skeleton for player cover -->
      <div v-if="audioStore.isPlayerLoading" class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
        <Skeleton class="w-full h-full" />
      </div>
      <!-- Actual player cover when loaded -->
      <div v-else-if="audioStore.currentFile" class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
        <img
          v-if="audioStore.currentFile.covers && audioStore.currentFile.covers.length > 0"
          :src="`/storage/${audioStore.currentFile.covers[0].path}`"
          alt="Cover"
          class="w-full h-full object-cover"
        />
        <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
          <span class="text-xs">No Cover</span>
        </div>
        <button class="w-full h-full absolute top-0 left-0 bg-black/50 opacity-0 hover:opacity-100 flex items-center justify-center cursor-pointer text-white transition-opacity" @click="togglePlayPause">
          <Play v-if="!audioStore.isPlaying" :size="24" />
          <Pause v-else :size="24" />
        </button>
      </div>

      <div class="flex-1">
        <!-- Loading skeleton for player artist and title -->
        <div v-if="audioStore.isPlayerLoading" class="font-medium text-white mb-2 flex flex-col gap-2">
          <Skeleton class="h-4 w-24" />
          <Skeleton class="h-5 w-32" />
        </div>
        <!-- Actual player artist and title when loaded -->
        <div v-else-if="audioStore.currentFile" class="font-medium text-foreground mb-2 flex flex-col gap-1">
          <span class="text-xs font-semibold text-muted-foreground">{{ excerpt(currentArtist) || 'Unknown Artist' }}</span>
          <span class="text-foreground font-semibold">{{ excerpt(currentTitle) }}</span>
        </div>

        <!-- Progress bar skeleton -->
        <div v-if="audioStore.isPlayerLoading" class="mb-2">
          <Skeleton class="h-2 w-full mb-2" />
          <div class="flex justify-between text-xs text-white mb-2">
            <Skeleton class="h-3 w-10" />
            <Skeleton class="h-3 w-10" />
          </div>
        </div>
        <!-- Actual progress bar -->
        <div v-else-if="audioStore.currentFile" class="mb-2">
          <div
            class="h-2 bg-muted rounded-full cursor-pointer mb-2 transition-colors hover:bg-muted/80"
            @click="seekTo($event)"
          >
            <div
              class="h-full bg-primary rounded-full transition-all"
              :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
            ></div>
          </div>
          <div class="flex justify-between text-xs text-muted-foreground mb-2">
            <span>{{ formatTime(currentTime) }}</span>
            <span>{{ formatTime(duration) }}</span>
          </div>

          <!-- Player controls -->
          <div class="flex items-center justify-center gap-4 mt-2">
            <button
              class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
              @click="handleShuffle"
              title="Shuffle"
            >
              <Shuffle :size="16" />
            </button>

            <button
              class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
              @click="handlePrevious"
              title="Previous"
            >
              <SkipBack :size="20" />
            </button>

            <button
              class="btn-atlas-primary p-3 rounded-full hover:bg-primary/90 transition-colors"
              @click="togglePlayPause"
              title="Play/Pause"
            >
              <Play v-if="!audioStore.isPlaying" :size="24" />
              <Pause v-else :size="24" />
            </button>

            <button
              class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
              @click="handleNext"
              title="Next"
            >
              <SkipForward :size="20" />
            </button>
          </div>
        </div>
      </div>
    </div>
      <!-- End of player desktop -->

      <!-- Start of mobile player -->
      <div>
          <div class="flex gap-2 items-center md:hidden mb-4">
              <!-- Loading skeleton for player cover -->
              <div v-if="audioStore.isPlayerLoading" class="flex items-center justify-center relative w-12 h-12">
                  <Skeleton class="w-full h-full" />
              </div>
              <!-- Actual player cover when loaded -->
              <div v-else-if="audioStore.currentFile" class="flex items-center justify-center relative w-12 h-12">
                  <img
                      v-if="audioStore.currentFile.covers && audioStore.currentFile.covers.length > 0"
                      :src="`/storage/${audioStore.currentFile.covers[0].path}`"
                      alt="Cover"
                      class="w-full h-full object-cover"
                  />
                  <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
                      <span class="text-xs">No Cover</span>
                  </div>
                  <button class="w-full h-full absolute top-0 left-0 bg-black/50 opacity-0 hover:opacity-100 flex items-center justify-center cursor-pointer text-white transition-opacity" @click="togglePlayPause">
                      <Play v-if="!audioStore.isPlaying" :size="24" />
                      <Pause v-else :size="24" />
                  </button>
              </div>

              <!-- Loading skeleton for player artist and title -->
              <div v-if="audioStore.isPlayerLoading" class="font-medium text-white flex flex-col gap-2 flex-1">
                  <Skeleton class="h-4 w-24" />
                  <Skeleton class="h-5 w-32" />
              </div>
              <!-- Actual player artist and title when loaded -->
              <div v-else-if="audioStore.currentFile" class="font-medium text-foreground mb-2 flex flex-col gap-1 flex-1">
                  <span class="text-xs font-semibold text-muted-foreground">{{ excerpt(currentArtist) || 'Unknown Artist' }}</span>
                  <span class="text-foreground font-semibold">{{ excerpt(currentTitle) }}</span>
              </div>

              <div class="flex items-center justify-center gap-4">
                  <button
                      class="btn-atlas-primary p-3 transition-colors"
                      @click="togglePlayPause"
                      title="Play/Pause"
                  >
                      <Play v-if="!audioStore.isPlaying" :size="24" />
                      <Pause v-else :size="24" />
                  </button>
              </div>
          </div>


          <!-- Progress bar skeleton -->
          <div v-if="audioStore.isPlayerLoading" class="mb-2">
              <Skeleton class="h-2 w-full mb-2" />
<!--              <div class="flex justify-between text-xs text-white mb-2">-->
<!--                  <Skeleton class="h-3 w-10" />-->
<!--                  <Skeleton class="h-3 w-10" />-->
<!--              </div>-->
          </div>
          <!-- Actual progress bar -->
          <div v-else-if="audioStore.currentFile" class="mb-2">
              <div
                  class="h-2 bg-muted rounded-full cursor-pointer mb-2 transition-colors hover:bg-muted/80"
                  @click="seekTo($event)"
              >
                  <div
                      class="h-full bg-primary rounded-full transition-all"
                      :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                  ></div>
              </div>
<!--              <div class="flex justify-between text-xs text-muted-foreground mb-2">-->
<!--                  <span>{{ formatTime(currentTime) }}</span>-->
<!--                  <span>{{ formatTime(duration) }}</span>-->
<!--              </div>-->
          </div>
      </div>
      <!-- End of mobile player -->
  </div>
</template>
