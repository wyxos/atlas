<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { onMounted, provide, watch } from 'vue';
import { audioStore, audioActions } from '@/stores/audioStore';

// Import our audio components and composables
import AudioListItem from '@/components/audio/AudioListItem.vue';
import AudioSearch from '@/components/audio/AudioSearch.vue';
import { useAudioFileLoader } from '@/components/audio/useAudioFileLoader';
import { useAudioSwipeHandler } from '@/components/audio/useAudioSwipeHandler';

interface Playlist {
    id: number;
    name: string;
    description?: string;
    created_at: string;
    user: {
        id: number;
        name: string;
    };
    covers?: any[];
}

const props = defineProps<{
    playlist: Playlist;
    files: any[];
    search: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Playlists',
        href: route('playlists.index'),
    },
    {
        title: props.playlist.name,
        href: route('playlists.show', { playlist: props.playlist.id }),
    },
];

// Use our composables
const {
  loadedFiles,
  loadFileDetails,
  getFileData
} = useAudioFileLoader();

const {
  swipedItemId,
  handleTouchStart,
  handleTouchMove,
  handleTouchEnd,
  handleGlobalClick
} = useAudioSwipeHandler();

// Play audio with smart queue management
async function playAudio(file: any): Promise<void> {
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
        // Different file - check if we're searching and have an existing queue
        const isSearching = props.search.length > 0;
        const hasExistingQueue = audioStore.playlist.length > 0;

        if (isSearching && hasExistingQueue) {
            // Try to find the track in the existing queue first
            const foundInQueue = audioActions.findAndPlayInQueue(fileId, loadFileDetails);
            if (foundInQueue) {
                audioActions.setPlaying(true);
                return;
            }
        }

        // If not found in queue or no existing queue, set up new playlist
        audioActions.setLoading(true);
        const filesToQueue = props.search.length ? props.search : props.files;

        // Queue the entire visible list when playing a new track
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

// Action handlers
function toggleFavorite(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events

    // Optimistically update the UI first
    if (loadedFiles[item.id]) {
        loadedFiles[item.id].loved = !loadedFiles[item.id].loved;
        if (loadedFiles[item.id].loved) {
            loadedFiles[item.id].liked = false;
            loadedFiles[item.id].disliked = false;
            loadedFiles[item.id].funny = false;
        }
    }

    // Also update the current file in the audio store if it matches
    if (audioStore.currentFile && audioStore.currentFile.id === item.id) {
        audioStore.currentFile.loved = loadedFiles[item.id]?.loved || false;
        audioStore.currentFile.liked = loadedFiles[item.id]?.liked || false;
        audioStore.currentFile.disliked = loadedFiles[item.id]?.disliked || false;
        audioStore.currentFile.funny = loadedFiles[item.id]?.funny || false;
    }

    // Send request to backend
    router.post(route('audio.love', { file: item.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            if (loadedFiles[item.id]) {
                loadedFiles[item.id].loved = !loadedFiles[item.id].loved;
            }
            console.error('Failed to toggle love status:', errors);
        }
    });

    // Close the swipe actions after action
    swipedItemId.value = null;
}

function likeItem(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events

    // Optimistically update the UI first
    if (loadedFiles[item.id]) {
        loadedFiles[item.id].liked = !loadedFiles[item.id].liked;
        if (loadedFiles[item.id].liked) {
            loadedFiles[item.id].loved = false;
            loadedFiles[item.id].disliked = false;
            loadedFiles[item.id].funny = false;
        }
    }

    // Also update the current file in the audio store if it matches
    if (audioStore.currentFile && audioStore.currentFile.id === item.id) {
        audioStore.currentFile.loved = loadedFiles[item.id]?.loved || false;
        audioStore.currentFile.liked = loadedFiles[item.id]?.liked || false;
        audioStore.currentFile.disliked = loadedFiles[item.id]?.disliked || false;
        audioStore.currentFile.funny = loadedFiles[item.id]?.funny || false;
    }

    // Send request to backend
    router.post(route('audio.like', { file: item.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            if (loadedFiles[item.id]) {
                loadedFiles[item.id].liked = !loadedFiles[item.id].liked;
            }
            console.error('Failed to toggle like status:', errors);
        }
    });

    // Close the swipe actions after action
    swipedItemId.value = null;
}

function dislikeItem(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events

    // Optimistically update the UI first
    if (loadedFiles[item.id]) {
        loadedFiles[item.id].disliked = !loadedFiles[item.id].disliked;
        if (loadedFiles[item.id].disliked) {
            loadedFiles[item.id].loved = false;
            loadedFiles[item.id].liked = false;
            loadedFiles[item.id].funny = false;
        }
    }

    // Also update the current file in the audio store if it matches
    if (audioStore.currentFile && audioStore.currentFile.id === item.id) {
        audioStore.currentFile.loved = loadedFiles[item.id]?.loved || false;
        audioStore.currentFile.liked = loadedFiles[item.id]?.liked || false;
        audioStore.currentFile.disliked = loadedFiles[item.id]?.disliked || false;
        audioStore.currentFile.funny = loadedFiles[item.id]?.funny || false;
    }

    // Send request to backend
    router.post(route('audio.dislike', { file: item.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            if (loadedFiles[item.id]) {
                loadedFiles[item.id].disliked = !loadedFiles[item.id].disliked;
            }
            console.error('Failed to toggle dislike status:', errors);
        }
    });

    // Close the swipe actions after action
    swipedItemId.value = null;
}

function laughedAtItem(item: any, event: Event): void {
    event.stopPropagation(); // Prevent triggering parent click events

    // Optimistically update the UI first
    if (loadedFiles[item.id]) {
        loadedFiles[item.id].funny = !loadedFiles[item.id].funny;
        if (loadedFiles[item.id].funny) {
            loadedFiles[item.id].loved = false;
            loadedFiles[item.id].liked = false;
            loadedFiles[item.id].disliked = false;
        }
    }

    // Also update the current file in the audio store if it matches
    if (audioStore.currentFile && audioStore.currentFile.id === item.id) {
        audioStore.currentFile.loved = loadedFiles[item.id]?.loved || false;
        audioStore.currentFile.liked = loadedFiles[item.id]?.liked || false;
        audioStore.currentFile.disliked = loadedFiles[item.id]?.disliked || false;
        audioStore.currentFile.funny = loadedFiles[item.id]?.funny || false;
    }

    // Send request to backend
    router.post(route('audio.laughed-at', { file: item.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            if (loadedFiles[item.id]) {
                loadedFiles[item.id].funny = !loadedFiles[item.id].funny;
            }
            console.error('Failed to toggle funny status:', errors);
        }
    });

    // Close the swipe actions after action
    swipedItemId.value = null;
}

// Watch for reaction changes in the current file to sync loadedFiles
watch(() => audioStore.currentFile?.liked, (newValue) => {
    if (audioStore.currentFile && loadedFiles[audioStore.currentFile.id]) {
        loadedFiles[audioStore.currentFile.id].liked = !!newValue;
    }
});

watch(() => audioStore.currentFile?.loved, (newValue) => {
    if (audioStore.currentFile && loadedFiles[audioStore.currentFile.id]) {
        loadedFiles[audioStore.currentFile.id].loved = !!newValue;
    }
});

watch(() => audioStore.currentFile?.disliked, (newValue) => {
    if (audioStore.currentFile && loadedFiles[audioStore.currentFile.id]) {
        loadedFiles[audioStore.currentFile.id].disliked = !!newValue;
    }
});

watch(() => audioStore.currentFile?.funny, (newValue) => {
    if (audioStore.currentFile && loadedFiles[audioStore.currentFile.id]) {
        loadedFiles[audioStore.currentFile.id].funny = !!newValue;
    }
});

onMounted(() => {
    // Provide the loadFileDetails function for the AudioPlayer
    provide('loadFileDetails', loadFileDetails);
});

const initialQuery = window.location.search
    ? new URLSearchParams(window.location.search).get('query') || ''
    : '';

// Scroll timeout for debouncing
let scrollTimeout: number | null = null;

function onScroll(startIndex: number, endIndex: number, visibleStartIndex: number, visibleEndIndex: number): void {
    // Clear previous timeout
    if (scrollTimeout !== null) {
        window.clearTimeout(scrollTimeout);
    }

    // Set a timeout to detect when scrolling stops (debounce)
    scrollTimeout = window.setTimeout(() => {
        // Get the current items list (search results or all files)
        const currentItems = props.search.length ? props.search : props.files;

        // Pre-load item details for visible items
        for (let i = visibleStartIndex; i <= visibleEndIndex; i++) {
            if (i >= 0 && i < currentItems.length) {
                const item = currentItems[i];
                if (item && !loadedFiles[item.id]) {
                    loadFileDetails(item.id, true); // Load with priority
                }
            }
        }
    }, 500); // 500ms debounce to detect scroll stop
}

// Format date
function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString();
}
</script>

<template>
    <Head :title="playlist.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <!-- Playlist Header -->
            <div class="p-6 border-b">
                <div class="flex items-center space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-3xl font-bold truncate" :title="playlist.name">
                            {{ playlist.name }}
                        </h1>
                        <div class="flex items-center space-x-4 text-sm text-gray-500 mt-1">
                            <span>{{ files.length }} {{ files.length === 1 ? 'track' : 'tracks' }}</span>
                            <span>Created {{ formatDate(playlist.created_at) }}</span>
                            <span>by {{ playlist.user.name }}</span>
                        </div>
                        <p v-if="playlist.description" class="text-gray-600 text-sm mt-2">
                            {{ playlist.description }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Search component -->
            <AudioSearch :initial-query="initialQuery">
                <template #noResults="{ query }">
                    <p class="text-gray-500">No match was found for "{{ query }}" in this playlist</p>
                </template>

                <template #default="{ query }">
                    <!-- Results list -->
                    <div class="flex-1 md:p-4">
                        <RecycleScroller
                            class="h-[600px] RecycleScroller"
                            :items="query ? props.search : props.files"
                            :item-size="74"
                            key-field="id"
                            :emit-update="true"
                            @update="onScroll"
                            v-slot="{ item, index }"
                        >
                            <div class="relative overflow-hidden">
                                <!-- List item component -->
                                <AudioListItem
                                    :item="item"
                                    :index="index + 1"
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
                                    @laughed-at="laughedAtItem"
                                />
                            </div>
                        </RecycleScroller>
                    </div>
                </template>
            </AudioSearch>

            <!-- Empty state -->
            <div v-if="files.length === 0" class="flex-1 flex items-center justify-center">
                <div class="text-center py-12">
                    <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-600 mb-2">No Tracks in Playlist</h3>
                    <p class="text-gray-500 mb-4">This playlist is empty. Add some tracks to get started.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
