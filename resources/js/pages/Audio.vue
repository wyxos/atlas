<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import {Head, router} from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { Play, Pause } from 'lucide-vue-next';
import {ref, computed, watch} from 'vue';
import { Input } from '@/components/ui/input';
import { Loader2, Heart, ThumbsUp, ThumbsDown } from 'lucide-vue-next';
import debounce from 'lodash/debounce';

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

defineProps<{
    files: any[];
    search: any[];
}>();

// Audio player state
const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentFile = ref<any>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const volume = ref(1); // Default volume (0-1)

// Play the selected audio file
function playAudio(file: any): void {
    if (currentFile.value && currentFile.value.id === file.id) {
        // Toggle play/pause if it's the same file
        if (isPlaying.value) {
            audioPlayer.value?.pause();
            isPlaying.value = false;
        } else {
            audioPlayer.value?.play();
            isPlaying.value = true;
        }
    } else {
        // Play a new file
        currentFile.value = file;
        if (audioPlayer.value) {
            // Use the streaming route instead of direct file path
            audioPlayer.value.src = `/audio/stream/${file.id}`;
            audioPlayer.value.play()
                .then(() => {
                    isPlaying.value = true;
                })
                .catch(error => {
                    console.error('Error playing audio:', error);
                    isPlaying.value = false;
                });
        }
    }
}

// Get the current file title for display
const currentTitle = computed(() => {
    if (!currentFile.value) return 'No file selected';
    return currentFile.value.metadata?.payload?.title || 'Untitled';
});

function excerpt(text: string, length = 30): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

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

// Set volume
// function setVolume(value: number): void {
//     if (!audioPlayer.value) return;
//
//     // Ensure volume is between 0 and 1
//     const newVolume = Math.max(0, Math.min(1, value));
//     audioPlayer.value.volume = newVolume;
//     volume.value = newVolume;
// }

const query = ref('');
const isLoading = ref(false);

// Swipe functionality
const swipedItemId = ref<string | null>(null);
const startX = ref<number | null>(null);
const endX = ref<number | null>(null);
const swipeThreshold = 50; // Minimum distance to trigger swipe
const isDragging = ref(false);

// Handle touch/mouse start
function handleTouchStart(event: TouchEvent | MouseEvent): void {
    if ('touches' in event) {
        startX.value = event.touches[0].clientX;
    } else {
        isDragging.value = true;
        startX.value = event.clientX;
    }
}

// Handle touch/mouse move
function handleTouchMove(event: TouchEvent | MouseEvent): void {
    if ('touches' in event) {
        endX.value = event.touches[0].clientX;
    } else if (isDragging.value) {
        endX.value = event.clientX;
    }
}

// Handle touch/mouse end
function handleTouchEnd(item: any): void {
    if (!startX.value || !endX.value) return;

    const swipeDistance = startX.value - endX.value;

    // If swiped left beyond threshold
    if (swipeDistance > swipeThreshold) {
        // If this item is already open, close it
        if (swipedItemId.value === item.id) {
            swipedItemId.value = null;
        } else {
            // Open this item, closing any previously open item
            swipedItemId.value = item.id;
        }
    }
    // If swiped right beyond threshold
    else if (swipeDistance < -swipeThreshold) {
        // Close the item if it's open
        if (swipedItemId.value === item.id) {
            swipedItemId.value = null;
        }
    }

    // Reset coordinates and dragging state
    startX.value = null;
    endX.value = null;
    isDragging.value = false;
}

// Close any open item when clicking outside
function handleGlobalClick(): void {
    if (swipedItemId.value) {
        swipedItemId.value = null;
    }
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

const debouncedSearch = debounce((newQuery: string|null, oldQuery: string|null) => {
    if (newQuery && newQuery.trim()) {
        console.log('Searching for:', newQuery);
        isLoading.value = true;
        router.get(route('audio'), { query: newQuery }, {
            preserveState: true,
            only: ['search'],
            replace: true,
            onSuccess: () => {
                console.log('Search completed');
                isLoading.value = false;
            },
            onError: (error) => {
                console.error('Search error:', error);
                isLoading.value = false;
            }
        });
    }

    console.log('Old query:', oldQuery, 'New query:', newQuery);

    if(oldQuery && !newQuery) {
        console.log('Clearing search, resetting results');
        // If query is cleared, reset search results
        isLoading.value = true;
        router.get(route('audio'), {}, {
            preserveState: true,
            only: ['search'],
            replace: true,
            onSuccess: () => {
                console.log('Search reset');
                isLoading.value = false;
            },
            onError: (error) => {
                console.error('Reset error:', error);
                isLoading.value = false;
            }
        });
    }
}, 500); // adjust delay as needed (ms)

watch(query, (newQuery, oldQuery) => {
    debouncedSearch(newQuery, oldQuery);
});
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <div class="p-4">
                <Input type="search" placeholder="Search" v-model="query" />
            </div>
            <div class="flex-1 md:p-4">
                <!-- Loading spinner -->
                <div v-if="isLoading" class="flex justify-center items-center h-[640px]">
                    <Loader2 class="animate-spin" :size="40" />
                </div>

                <!-- No results message -->
                <div v-else-if="query && search.length === 0" class="flex justify-center items-center h-[640px]">
                    <p class="text-gray-500">No match was found for "{{ query }}"</p>
                </div>

                <!-- Results list -->
                <RecycleScroller v-else class="h-[640px]" :items="query ? search : files" :item-size="40 + 16 + 16" key-field="id" v-slot="{ item }">
                    <div class="relative overflow-hidden">
                        <!-- Swipeable container -->
                        <div
                            class="file p-4 flex justify-between items-center rounded border-b-2 border-blue-200 transition-transform duration-300 relative"
                            :class="{
                                'bg-blue-500': currentFile?.id === item.id,
                                'transform -translate-x-32': swipedItemId === item.id
                            }"
                            @touchstart="handleTouchStart"
                            @touchmove="handleTouchMove"
                            @touchend="handleTouchEnd(item)"
                            @mousedown="handleTouchStart"
                            @mousemove="handleTouchMove"
                            @mouseup="handleTouchEnd(item)"
                            @mouseleave="isDragging && handleTouchEnd(item)"
                        >
                            <div class="flex flex-col">
                                <span class="text-xs font-semibold">{{ excerpt(item.metadata?.payload?.artist, 25) || 'Untitled' }}</span>
                                <span>{{ excerpt(item.metadata?.payload?.title, 25) || 'Untitled' }}</span>
                            </div>
                            <button class="cursor-pointer" @click="playAudio(item)">
                                <Play v-if="!isPlaying || currentFile?.id !== item.id" :size="20" />
                                <Pause v-else :size="20" />
                            </button>

                            <!-- Action buttons container -->
                            <div class="absolute top-0 left-full h-full items-center flex gap-4 p-4">
                                <button
                                    class=""
                                    @click="toggleFavorite(item, $event)"
                                >
                                    <Heart :size="20" />
                                </button>
                                <button
                                    class=""
                                    @click="likeItem(item, $event)"
                                >
                                    <ThumbsUp :size="20" />
                                </button>
                                <button
                                    class=""
                                    @click="dislikeItem(item, $event)"
                                >
                                    <ThumbsDown :size="20" />
                                </button>
                            </div>
                        </div>


                    </div>
                </RecycleScroller>
            </div>

            <div class="bg-blue-950 p-4 border-t fixed bottom-0 left-0 right-0 md:static">
                <audio
                    ref="audioPlayer"
                    class="hidden"
                    @ended="isPlaying = false"
                    @timeupdate="currentTime = audioPlayer?.currentTime || 0"
                    @loadedmetadata="duration = audioPlayer?.duration || 0"
                    @volumechange="volume = audioPlayer?.volume || 1"
                ></audio>

                <!-- Title and controls -->
                <div class="flex items-center mb-2">
                    <div class="flex-1">
                        <div class="font-medium text-white">{{ excerpt(currentTitle) }}</div>
                    </div>
                    <div v-if="currentFile" class="flex items-center">
                        <button class="cursor-pointer text-white" @click="playAudio(currentFile)">
                            <Play v-if="!isPlaying" :size="24" />
                            <Pause v-else :size="24" />
                        </button>
                    </div>
                </div>

                <!-- Progress bar -->
                <div v-if="currentFile" class="mb-2">
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

<!--                &lt;!&ndash; Volume control &ndash;&gt;-->
<!--                <div v-if="currentFile" class="flex items-center">-->
<!--                    <span class="text-xs text-white mr-2">Volume</span>-->
<!--                    <input-->
<!--                        type="range"-->
<!--                        min="0"-->
<!--                        max="1"-->
<!--                        step="0.01"-->
<!--                        :value="volume"-->
<!--                        @input="setVolume(parseFloat(($event.target as HTMLInputElement).value))"-->
<!--                        class="w-24"-->
<!--                    />-->
<!--                </div>-->
            </div>
        </div>
    </AppLayout>
</template>
