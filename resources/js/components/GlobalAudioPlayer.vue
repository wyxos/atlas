<script setup lang="ts">
import { audioStore } from '@/stores/audioStore';
import AudioPlayer from '@/components/audio/AudioPlayer.vue';
</script>

<template>
  <div v-if="audioStore.isPlayerVisible" class="fixed bottom-0 left-0 right-0 z-50 pointer-events-none">
    <div class="pointer-events-auto">
      <AudioPlayer />
    </div>
  </div>
</template>
