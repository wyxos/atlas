<script setup lang="ts">
import { onBeforeUnmount } from 'vue';
import AudioPlayer from '@/components/audio/AudioPlayer.vue';
import { useAudioPlayer } from '@/stores/audio';

const { cleanup } = useAudioPlayer();

// Cleanup on component unmount
onBeforeUnmount(() => {
  cleanup();
});

// Also handle HMR (Hot Module Reload) in development
if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    cleanup();
  });
}
</script>

<template>
  <!-- Interactive global audio player -->
  <div class="w-full">
    <AudioPlayer />
  </div>
</template>