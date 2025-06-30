<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Play, Pause } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';

const props = defineProps<{
  currentFile: any | null;
  isPlaying: boolean;
  isPlayerLoading: boolean;
}>();

const emit = defineEmits<{
  (e: 'play', file: any): void;
  (e: 'pause'): void;
  (e: 'timeUpdate', time: number): void;
  (e: 'durationChange', duration: number): void;
  (e: 'ended'): void;
}>();

const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentTime = ref(0);
const duration = ref(0);
const volume = ref(1); // Default volume (0-1)

// Get the current file title for display
const currentTitle = computed(() => {
  if (!props.currentFile) return 'No file selected';
  return props.currentFile.metadata?.payload?.title || 'Untitled';
});

// Get the current file artist for display
const currentArtist = computed(() => {
  if (!props.currentFile) return 'Unknown Artist';
  return props.currentFile.artists && props.currentFile.artists.length > 0
    ? props.currentFile.artists[0].name
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

  audioPlayer.value.currentTime = percentage * duration.value;
}

function excerpt(text: string, length = 30): string {
  if (!text) return '';
  return text.length > length ? text.substring(0, length) + '...' : text;
}

// Toggle play/pause
function togglePlayPause(): void {
  if (props.isPlaying) {
    emit('pause');
  } else if (props.currentFile) {
    emit('play', props.currentFile);
  }
}

// Watch for changes to isPlaying and currentFile
watch(() => props.isPlaying, (newIsPlaying) => {
  if (audioPlayer.value) {
    if (newIsPlaying) {
      audioPlayer.value.play();
    } else {
      audioPlayer.value.pause();
    }
  }
});

watch(() => props.currentFile, (newFile) => {
  if (audioPlayer.value && newFile) {
    audioPlayer.value.src = `/audio/stream/${newFile.id}`;
    if (props.isPlaying) {
      audioPlayer.value.play();
    }
  }
});
</script>

<template>
  <div class="fixed  bottom-0 left-0 bg-blue-950 p-4 border-t w-full" v-if="isPlayerLoading || currentFile">
    <audio
      ref="audioPlayer"
      class="hidden"
      @ended="emit('ended')"
      @timeupdate="currentTime = audioPlayer?.currentTime || 0; emit('timeUpdate', currentTime)"
      @loadedmetadata="duration = audioPlayer?.duration || 0; emit('durationChange', duration)"
      @volumechange="volume = audioPlayer?.volume || 1"
    ></audio>

    <div class="flex gap-4 items-center">
      <!-- Loading skeleton for player cover -->
      <div v-if="isPlayerLoading" class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
        <Skeleton class="w-full h-full" />
      </div>
      <!-- Actual player cover when loaded -->
      <div v-else-if="currentFile" class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
        <img
          v-if="currentFile.covers && currentFile.covers.length > 0"
          :src="`/storage/${currentFile.covers[0].path}`"
          alt="Cover"
          class="w-full h-full object-cover"
        />
        <div v-else class="w-full h-full bg-blue-300 flex items-center justify-center text-blue-800">
          <span class="text-xs">No Cover</span>
        </div>
        <button class="w-full h-full absolute top-0 left-0 bg-black/50 opacity-0 hover:opacity-100 flex items-center justify-center cursor-pointer text-white" @click="togglePlayPause">
          <Play v-if="!isPlaying" :size="24" />
          <Pause v-else :size="24" />
        </button>
      </div>

      <div class="flex-1">
        <!-- Loading skeleton for player artist and title -->
        <div v-if="isPlayerLoading" class="font-medium text-white mb-2 flex flex-col gap-2">
          <Skeleton class="h-4 w-24" />
          <Skeleton class="h-5 w-32" />
        </div>
        <!-- Actual player artist and title when loaded -->
        <div v-else-if="currentFile" class="font-medium text-white mb-2 flex flex-col gap-1">
          <span class="text-xs font-semibold">{{ excerpt(currentArtist) || 'Untitled' }}</span>
          <span>{{ excerpt(currentTitle) }}</span>
        </div>

        <!-- Progress bar skeleton -->
        <div v-if="isPlayerLoading" class="mb-2">
          <Skeleton class="h-2 w-full mb-2" />
          <div class="flex justify-between text-xs text-white mb-2">
            <Skeleton class="h-3 w-10" />
            <Skeleton class="h-3 w-10" />
          </div>
        </div>
        <!-- Actual progress bar -->
        <div v-else-if="currentFile" class="mb-2">
          <div
            class="h-2 bg-gray-700 rounded-full cursor-pointer mb-2"
            @click="seekTo($event)"
          >
            <div
              class="h-full bg-blue-500 rounded-full"
              :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
            ></div>
          </div>
          <div class="flex justify-between text-xs text-white mb-2">
            <span>{{ formatTime(currentTime) }}</span>
            <span>{{ formatTime(duration) }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
