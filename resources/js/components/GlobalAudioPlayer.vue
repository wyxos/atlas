<script setup lang="ts">
import AudioPlayer from '@/components/audio/AudioPlayer.vue';
import { useMediaKeys } from '@/composables/useMediaKeys';

// Set up media keys and headset button controls
useMediaKeys();
</script>

<template>
  <!-- Interactive global audio player -->
  <div class="w-full">
    <AudioPlayer />
  </div>
</template>