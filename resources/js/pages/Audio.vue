<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { ref, onMounted, onBeforeUnmount, provide } from 'vue';
import { audioStore, audioActions } from '@/stores/audioStore';

// Import our new components and composables
import AudioPlayer from '@/components/audio/AudioPlayer.vue';
import AudioListItem from '@/components/audio/AudioListItem.vue';
import AudioSearch from '@/components/audio/AudioSearch.vue';
import { useAudioFileLoader } from '@/components/audio/useAudioFileLoader';
import { useAudioSwipeHandler } from '@/components/audio/useAudioSwipeHandler';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Audio',
        href: '/audio',
    },
];

const props = defineProps<{
    files: any[];
    search: any[];
}>();

// Use global audio store instead of local state

// Use our composables
const {
  loadedFiles,
  loadFileDetails,
  getFileData,
  handleScroll,
  observeItem: baseObserveItem,
  createObserver,
  cleanup: cleanupFileLoader
} = useAudioFileLoader();

const {
  swipedItemId,
  handleTouchStart,
  handleTouchMove,
  handleTouchEnd,
  handleGlobalClick
} = useAudioSwipeHandler();

// Intersection Observer setup
const observer = ref<IntersectionObserver | null>(null);

// Queue the entire list when playing a new track
async function playAudio(file: any): Promise<void> {
    const filesToQueue = props.search.length ? props.search : props.files;
    const fileId = file.id;
    let fileData = loadedFiles[fileId];

    if (!fileData) {
        audioActions.setLoading(true);
        fileData = await loadFileDetails(fileId, true);
        if (!fileData) {
            console.error('Failed to load file data for playback');
            audioActions.setLoading(false);
            return;
        }
    }

    if (audioStore.currentFile && audioStore.currentFile.id === fileId) {
        // Same file - just toggle play/pause
        if (audioStore.isPlaying) {
            audioActions.setPlaying(false);
        } else {
            audioActions.setPlaying(true);
        }
    } else {
        // Different file - set up new playlist with ALL tracks
        audioActions.setLoading(true);
        
        // Always queue the entire visible list when playing a new track
        const playlistData = [];
        for (const item of filesToQueue) {
            let itemData = loadedFiles[item.id];
            if (!itemData) {
                // Load essential data for playlist - we'll load full data when needed
                itemData = item;
            }
            playlistData.push(itemData);
        }
        
        // Set up the complete playlist
        audioActions.setPlaylist(playlistData, fileData);
        // Set current file which will also make player visible
        await audioActions.setCurrentFile(fileData, loadFileDetails);
        audioActions.setPlaying(true);
        audioActions.setLoading(false);
    }
}

// Check if the given list is the current playlist
function isCurrentPlaylist(tracks: any[]): boolean {
    if (audioStore.playlist.length !== tracks.length) return false;
    return tracks.every((track, index) => {
        const playlistTrack = audioStore.playlist.find(p => p.id === track.id);
        return playlistTrack !== undefined;
    });
}

// Handle player events
function handlePlayerPause(): void {
    audioActions.setPlaying(false);
}

// Action handlers
function toggleFavorite(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events
    // Implement favorite toggle logic here
    console.log('Toggle favorite for item:', item.id);
    // Close the swipe actions after action
    swipedItemId.value = null;
}

function likeItem(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events
    // Implement like logic here
    console.log('Like item:', item.id);
    // Close the swipe actions after action
    swipedItemId.value = null;
}

function dislikeItem(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events
    // Implement dislike logic here
    console.log('Dislike item:', item.id);
    // Close the swipe actions after action
    swipedItemId.value = null;
}

// Wrap the observeItem function to include our observer
function observeItem(el: HTMLElement, itemId: string | number): void {
    if (observer.value) {
        baseObserveItem(el, itemId, observer.value);
    }
}

onMounted(() => {
    observer.value = createObserver();

    // Add scroll event listener to detect scrolling
    const scrollContainer = document.querySelector('.RecycleScroller');
    if (scrollContainer) {
        scrollContainer.addEventListener('scroll', handleScroll);
    }
    
    // Provide the loadFileDetails function for the AudioPlayer
    provide('loadFileDetails', loadFileDetails);
});

onBeforeUnmount(() => {
    cleanupFileLoader(observer.value);

    // Clean up scroll event listener
    const scrollContainer = document.querySelector('.RecycleScroller');
    if (scrollContainer) {
        scrollContainer.removeEventListener('scroll', handleScroll);
    }
});

const initialQuery = window.location.search
    ? new URLSearchParams(window.location.search).get('query') || ''
    : '';
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <!-- Search component -->
            <AudioSearch :initial-query="initialQuery">
                <template #noResults="{ query }">
                    <p class="text-gray-500">No match was found for "{{ query }}"</p>
                </template>

                <template #default="{ query }">
                    <!-- Results list -->
                    <div class="flex-1 md:p-4">
                        <RecycleScroller
                            class="h-[600px] RecycleScroller"
                            :items="query ? props.search : props.files"
                            :item-size="74"
                            key-field="id"
                            v-slot="{ item }"
                        >
                            <div class="relative overflow-hidden" :ref="el => el && observeItem(el, item.id)">
                                <!-- List item component -->
                                <AudioListItem
                                    :item="item"
                                    :loaded-file="loadedFiles[item.id]"
                                    :is-playing="audioStore.isPlaying"
                                    :current-file-id="audioStore.currentFile ? audioStore.currentFile.id : null"
                                    :is-swiped-open="swipedItemId === item.id"
                                    @play="playAudio(getFileData(item))"
                                    @touch-start="handleTouchStart"
                                    @touch-move="handleTouchMove"
                                    @touch-end="handleTouchEnd"
                                    @favorite="toggleFavorite"
                                    @like="likeItem"
                                    @dislike="dislikeItem"
                                />
                            </div>
                        </RecycleScroller>
                    </div>
                </template>
            </AudioSearch>

        </div>
    </AppLayout>
</template>
