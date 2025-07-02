<script setup lang="ts">
import { provide, ref } from 'vue';
import { audioStore } from '@/stores/audioStore';
import AudioPlayer from '@/components/audio/AudioPlayer.vue';

// Create a global loadFileDetails function that can work with any file
const loadFileDetails = async (id: number, priority: boolean = false): Promise<any> => {
  try {
    const response = await fetch(`/audio/${id}/details`, {
      method: 'GET',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });
    
    if (!response.ok) {
      throw new Error(`Failed to load file details: ${response.statusText}`);
    }
    
    const data = await response.json();
    return data;
  } catch (error) {
    console.error('Error loading file details:', error);
    return null;
  }
};

// Provide the loadFileDetails function to children
provide('loadFileDetails', loadFileDetails);
</script>

<template>
  <div v-if="audioStore.isPlayerVisible" class="fixed bottom-0 left-0 right-0 z-50 pointer-events-none">
    <div class="pointer-events-auto">
      <AudioPlayer />
    </div>
  </div>
</template>
