<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { ref, onMounted, onBeforeUnmount } from 'vue';

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

// Audio player state
const currentFile = ref<any>(null);
const isPlaying = ref(false);
const isPlayerLoading = ref(false); // Track when player is loading
const playlist = ref<any[]>([]); // Current playlist
const currentIndex = ref(-1); // Current track index in playlist
const isShuffled = ref(false); // Track if playlist is shuffled

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
        isPlayerLoading.value = true;
        fileData = await loadFileDetails(fileId, true);
        if (!fileData) {
            console.error('Failed to load file data for playback');
            isPlayerLoading.value = false;
            return;
        }
    }

    if (currentFile.value && currentFile.value.id === fileId) {
        if (isPlaying.value) {
            isPlaying.value = false;
        } else {
            isPlaying.value = true;
        }
    } else {
        isPlayerLoading.value = true;
        currentFile.value = fileData;
        isPlaying.value = true;
        isPlayerLoading.value = false;
        
        // Set up playlist if not already set or if it's a different list
        if (playlist.value.length === 0 || !isCurrentPlaylist(filesToQueue)) {
            setPlaylist(filesToQueue, fileData);
        } else {
            // Update current index if playing from existing playlist
            currentIndex.value = playlist.value.findIndex(track => track.id === fileData.id);
        }
    }
}

// Check if the given list is the current playlist
function isCurrentPlaylist(tracks: any[]): boolean {
    if (playlist.value.length !== tracks.length) return false;
    return tracks.every((track, index) => {
        const playlistTrack = playlist.value.find(p => p.id === track.id);
        return playlistTrack !== undefined;
    });
}

// Set up the playlist
function setPlaylist(tracks: any[], currentTrack: any) {
    playlist.value = [...tracks];
    currentIndex.value = playlist.value.findIndex(track => track.id === currentTrack.id);
    isShuffled.value = false;
}

// Shuffle the current playlist
function shuffleTracks() {
    if (playlist.value.length === 0) {
        // If no playlist, create one from current list
        const filesToShuffle = props.search.length ? props.search : props.files;
        playlist.value = [...filesToShuffle];
    }
    
    // Keep current track in place, shuffle the rest
    const currentTrack = currentFile.value;
    const otherTracks = playlist.value.filter(track => track.id !== currentTrack?.id);
    const shuffledOthers = otherTracks.sort(() => Math.random() - 0.5);
    
    if (currentTrack) {
        playlist.value = [currentTrack, ...shuffledOthers];
        currentIndex.value = 0;
    } else {
        playlist.value = shuffledOthers;
    }
    
    isShuffled.value = true;
}

// Play next track
async function playNext() {
    if (playlist.value.length === 0 || currentIndex.value >= playlist.value.length - 1) {
        return; // No next track
    }
    
    currentIndex.value++;
    const nextTrack = playlist.value[currentIndex.value];
    
    // Load track data if needed
    let trackData = loadedFiles[nextTrack.id];
    if (!trackData) {
        isPlayerLoading.value = true;
        trackData = await loadFileDetails(nextTrack.id, true);
        if (!trackData) {
            console.error('Failed to load next track data');
            isPlayerLoading.value = false;
            return;
        }
    }
    
    isPlayerLoading.value = true;
    currentFile.value = trackData;
    isPlaying.value = true;
    isPlayerLoading.value = false;
}

// Play previous track
async function playPrevious() {
    if (playlist.value.length === 0 || currentIndex.value <= 0) {
        return; // No previous track
    }
    
    currentIndex.value--;
    const prevTrack = playlist.value[currentIndex.value];
    
    // Load track data if needed
    let trackData = loadedFiles[prevTrack.id];
    if (!trackData) {
        isPlayerLoading.value = true;
        trackData = await loadFileDetails(prevTrack.id, true);
        if (!trackData) {
            console.error('Failed to load previous track data');
            isPlayerLoading.value = false;
            return;
        }
    }
    
    isPlayerLoading.value = true;
    currentFile.value = trackData;
    isPlaying.value = true;
    isPlayerLoading.value = false;
}

// Handle player events
function handlePlayerEnded(): void {
    isPlaying.value = false;
    // Automatically play next track when current track ends
    playNext();
}

function handlePlayerPause(): void {
    isPlaying.value = false;
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
                                    :is-playing="isPlaying"
                                    :current-file-id="currentFile ? currentFile.id : null"
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

            <!-- Audio player component -->
            <AudioPlayer
                :current-file="currentFile"
                :is-playing="isPlaying"
                :is-player-loading="isPlayerLoading"
                @play="playAudio"
                @pause="handlePlayerPause"
                @ended="handlePlayerEnded"
                @previous="playPrevious"
                @next="playNext"
                @shuffle="shuffleTracks"
            />
        </div>
    </AppLayout>
</template>
