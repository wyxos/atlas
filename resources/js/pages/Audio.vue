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

// Play the selected audio file
async function playAudio(file: any): Promise<void> {
    // Ensure we have the full file data
    const fileId = file.id;
    let fileData = loadedFiles[fileId];

    if (!fileData) {
        // If file data is not loaded yet, load it with priority
        isPlayerLoading.value = true; // Set loading state to true
        fileData = await loadFileDetails(fileId, true); // Use priority loading
        if (!fileData) {
            console.error('Failed to load file data for playback');
            isPlayerLoading.value = false; // Reset loading state on error
            return;
        }
        // No need to reset isPlayerLoading here as it will be reset after setting the current file
    }

    if (currentFile.value && currentFile.value.id === fileId) {
        // Toggle play/pause if it's the same file
        if (isPlaying.value) {
            isPlaying.value = false;
        } else {
            isPlaying.value = true;
        }
    } else {
        // Play a new file
        isPlayerLoading.value = true; // Set loading state to true
        currentFile.value = fileData;
        isPlaying.value = true;
        isPlayerLoading.value = false; // Reset loading state after setting the current file
    }
}

// Handle player events
function handlePlayerEnded(): void {
    isPlaying.value = false;
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
            />
        </div>
    </AppLayout>
</template>
