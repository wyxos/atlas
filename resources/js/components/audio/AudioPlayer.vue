<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount, inject } from 'vue';
import {
    Play,
    Pause,
    SkipBack,
    SkipForward,
    Heart,
    ThumbsUp,
    ThumbsDown,
    Shuffle,
    Repeat,
    Repeat1
} from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { router } from '@inertiajs/vue3';
import { audioStore, audioActions } from '@/stores/audioStore';


// Create a single, persistent audio element that will be shared across all instances
// This ensures the audio continues playing during navigation
if (typeof window !== 'undefined' && !window.globalAudioElement) {
    window.globalAudioElement = new Audio();

    // Add global event listeners that persist across component instances
    window.globalAudioElement.addEventListener('ended', () => {
        // When track ends, update the store state
        audioActions.setPlaying(false);
        // Try to play the next track (moveToNext already handles repeat modes)
        audioActions.moveToNext(window.loadFileDetails).then(nextTrack => {
            if (nextTrack) {
                audioActions.setPlaying(true);
            }
        });
    });
}

// Store the loadFileDetails function globally so it can be used by the audio element's event listeners
if (typeof window !== 'undefined') {
    window.loadFileDetails = inject<(id: number, priority?: boolean) => Promise<any>>(
        'loadFileDetails',
        () => Promise.resolve(null)
    );
}

const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentTime = ref(audioStore.currentTime);
const duration = ref(audioStore.duration);
const volume = ref(audioStore.volume);

// Track user interactions with tracks
const isLiked = ref(false);
const isLoved = ref(false);
const isDisliked = ref(false);

// Function to load interaction states from file data
function loadInteractionStates(file: any) {
    if (file) {
        // Load the love status from the file data
        isLiked.value = !!file.liked;
        isLoved.value = !!file.loved;
        isDisliked.value = !!file.disliked;
    } else {
        // Reset when no file
        isLiked.value = false;
        isLoved.value = false;
        isDisliked.value = false;
    }
}

// Load interaction states when the current file changes
watch(() => audioStore.currentFile, loadInteractionStates, { immediate: true });

// Get the current file title for display
const currentTitle = computed(() => {
    if (!audioStore.currentFile) return 'No file selected';
    return audioStore.currentFile.metadata?.payload?.title || 'Untitled';
});

// Get the current file artist for display
const currentArtist = computed(() => {
    if (!audioStore.currentFile) return 'Unknown Artist';
    return audioStore.currentFile.artists && audioStore.currentFile.artists.length > 0
        ? audioStore.currentFile.artists[0].name
        : 'Unknown Artist';
});

// Computed property to get the cover image with priority: album covers first, then file covers
const coverImage = computed((): string | null => {
    if (!audioStore.currentFile) return null;

    // First check for album covers
    if (audioStore.currentFile.albums && audioStore.currentFile.albums.length > 0) {
        for (const album of audioStore.currentFile.albums) {
            if (album.covers && album.covers.length > 0) {
                return album.covers[0].path;
            }
        }
    }

    // Fall back to file covers
    if (audioStore.currentFile.covers && audioStore.currentFile.covers.length > 0) {
        return audioStore.currentFile.covers[0].path;
    }

    return null;
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
    if (typeof window === 'undefined' || !window.globalAudioElement || !duration.value) return;

    const progressBar = event.currentTarget as HTMLElement;
    const rect = progressBar.getBoundingClientRect();
    const offsetX = event.clientX - rect.left;
    const percentage = offsetX / rect.width;

    const newTime = percentage * duration.value;
    window.globalAudioElement.currentTime = newTime;
    audioActions.updateTime(newTime);
}

function excerpt(text: string, length = 30): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}

// Toggle play/pause
function togglePlayPause(): void {
    if (audioStore.isPlaying) {
        audioActions.setPlaying(false);
    } else if (audioStore.currentFile) {
        audioActions.setPlaying(true);
        updatePlayState(true);
    }
}

// Handle previous track
async function handlePrevious(): Promise<void> {
    const previousTrack = await audioActions.moveToPrevious(window.loadFileDetails);
    if (previousTrack) {
        audioActions.setPlaying(true);
    }
}

// Handle next track
async function handleNext(): Promise<void> {
    const nextTrack = await audioActions.moveToNext(window.loadFileDetails || loadFileDetails);
    if (nextTrack) {
        audioActions.setPlaying(true);
    }
}

// Handle love/like/dislike actions
function handleLove(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    isLoved.value = !isLoved.value;
    if (isLoved.value) {
        isLiked.value = false;
        isDisliked.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
    }

    // Send request to backend
    router.post(route('audio.love', { file: audioStore.currentFile.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            isLoved.value = !isLoved.value;
            if (audioStore.currentFile) {
                audioStore.currentFile.loved = isLoved.value;
            }
            console.error('Failed to toggle love status:', errors);
        }
    });
}

function handleLike(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    isLiked.value = !isLiked.value;
    if (isLiked.value) {
        isLoved.value = false;
        isDisliked.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
    }

    // Send request to backend
    router.post(route('audio.like', { file: audioStore.currentFile.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            isLiked.value = !isLiked.value;
            if (audioStore.currentFile) {
                audioStore.currentFile.liked = isLiked.value;
            }
            console.error('Failed to toggle like status:', errors);
        }
    });
}

function handleDislike(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    const wasDisliked = isDisliked.value;
    isDisliked.value = !isDisliked.value;
    if (isDisliked.value) {
        isLoved.value = false;
        isLiked.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
    }

    // Automatically go to next track when disliking
    if (isDisliked.value) {
        handleNext();
    }

    // Send request to backend
    router.post(route('audio.dislike', { file: audioStore.currentFile.id }), {}, {
        preserveState: true,
        preserveScroll: true,
        only: [],
        onError: (errors) => {
            // Revert on error
            isDisliked.value = wasDisliked;
            if (audioStore.currentFile) {
                audioStore.currentFile.disliked = wasDisliked;
            }
            console.error('Failed to toggle dislike status:', errors);
        }
    });
}

// Handle shuffle
function handleShuffle(): void {
    audioActions.shufflePlaylist();
}

// Handle repeat
function handleRepeat(): void {
    audioActions.toggleRepeat();
}

// Function to handle play/pause based on isPlaying prop
function updatePlayState(newIsPlaying: boolean): void {
    if (typeof window === 'undefined' || !window.globalAudioElement) {
        return;
    }

    if (newIsPlaying) {
        // Use a promise to ensure play() is handled properly
        const playPromise = window.globalAudioElement.play();

        // Handle play promise to catch any errors
        if (playPromise !== undefined) {
            playPromise.catch((error) => {
                console.error('Error playing audio:', error);
                // If autoplay is blocked, update the store state
                audioActions.setPlaying(false);
            });
        }
    } else {
        window.globalAudioElement.pause();
    }
    audioActions.setPlaying(newIsPlaying);
}

// Function to update the current file
function updateCurrentFile(newFile: any): void {
    if (typeof window === 'undefined' || !window.globalAudioElement) {
        return;
    }

    if (newFile) {
        // Always reset currentTime to 0 when changing tracks
        window.globalAudioElement.currentTime = 0;

        window.globalAudioElement.src = `/audio/stream/${newFile.id}`;
        // Explicitly load the audio before attempting to play
        window.globalAudioElement.load();

        if (audioStore.isPlaying) {
            // Use a promise to ensure play() is called after the audio is loaded
            const playPromise = window.globalAudioElement.play();

            // Handle play promise to catch any errors
            if (playPromise !== undefined) {
                playPromise.catch((error) => {
                    console.error('Error playing audio after file change:', error);
                    audioActions.setPlaying(false);
                });
            }
        }
    }
    audioActions.setCurrentFile(newFile);
}

// Import additional icons for like/love/dislike
import { Heart, ThumbsUp, ThumbsDown } from 'lucide-vue-next';

// Watch for changes to isPlaying and currentFile
watch(() => audioStore.isPlaying, updatePlayState);
watch(() => audioStore.currentFile, updateCurrentFile);

// Initialize the audio player when the component is mounted
onMounted(() => {
    if (typeof window === 'undefined' || !window.globalAudioElement) {
        return;
    }

    // Set up event listeners for the global audio element
    const audioEl = window.globalAudioElement;

    // Update time display
    const timeUpdateHandler = () => {
        currentTime.value = audioEl.currentTime;
        audioActions.updateTime(audioEl.currentTime);
    };

    // Update duration when metadata is loaded
    const metadataLoadedHandler = () => {
        duration.value = audioEl.duration;
        audioActions.updateDuration(audioEl.duration);
    };

    // Update volume display
    const volumeChangeHandler = () => {
        volume.value = audioEl.volume;
        audioActions.updateVolume(audioEl.volume);
    };

    // Handle mouse button events for next/previous track
    const mouseButtonHandler = (event: MouseEvent) => {
        // Only handle if player is active (has a current file) and is playing or paused
        if (!audioStore.currentFile) return;

        // Mouse button 3 (back button) - previous track
        if (event.button === 3) {
            event.preventDefault();
            event.stopPropagation();
            handlePrevious();
        }
        // Mouse button 4 (forward button) - next track
        else if (event.button === 4) {
            event.preventDefault();
            event.stopPropagation();
            handleNext();
        }
    };

    // Also handle mouseup to catch any missed events
    const mouseUpHandler = (event: MouseEvent) => {
        // Only handle if player is active (has a current file) and is playing or paused
        if (!audioStore.currentFile) return;

        // Mouse button 3 (back button) - previous track
        if (event.button === 3) {
            event.preventDefault();
            event.stopPropagation();
        }
        // Mouse button 4 (forward button) - next track
        else if (event.button === 4) {
            event.preventDefault();
            event.stopPropagation();
        }
    };

    // Add event listeners
    audioEl.addEventListener('timeupdate', timeUpdateHandler);
    audioEl.addEventListener('loadedmetadata', metadataLoadedHandler);
    audioEl.addEventListener('volumechange', volumeChangeHandler);

    // Add mouse button event listeners to the document
    document.addEventListener('mousedown', mouseButtonHandler);
    document.addEventListener('mouseup', mouseUpHandler);

    // Clean up event listeners when component is unmounted
    onBeforeUnmount(() => {
        audioEl.removeEventListener('timeupdate', timeUpdateHandler);
        audioEl.removeEventListener('loadedmetadata', metadataLoadedHandler);
        audioEl.removeEventListener('volumechange', volumeChangeHandler);
        document.removeEventListener('mousedown', mouseButtonHandler);
        document.removeEventListener('mouseup', mouseUpHandler);
    });

    // If we have a current file but no src is set, initialize it
    if (audioStore.currentFile && !audioEl.src) {
        updateCurrentFile(audioStore.currentFile);
    }
});
</script>

<template>
    <div class="sticky bottom-0 left-0 bg-card border-t border-border p-4 w-full"
         v-if="audioStore.isPlayerLoading || audioStore.currentFile">
        <!-- We're using a global audio element attached to window.globalAudioElement -->
        <!-- This is a hidden placeholder for backward compatibility -->
        <audio ref="audioPlayer" class="hidden"></audio>

        <!-- Start of player desktop -->
        <div class="hidden md:flex gap-4 items-center">
            <div class="flex gap-2 items-center w-100">
                <!-- Loading skeleton for player cover -->
                <div v-if="audioStore.isPlayerLoading"
                     class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
                    <Skeleton class="w-full h-full" />
                </div>
                <!-- Actual player cover when loaded -->
                <div v-else-if="audioStore.currentFile"
                     class="flex items-center justify-center relative w-18 h-18 md:w-32 md:h-32">
                    <img
                        v-if="coverImage"
                        :src="`/atlas/${coverImage}`"
                        alt="Cover"
                        class="w-full h-full object-cover"
                    />
                    <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
                        <span class="text-xs">No Cover</span>
                    </div>
                </div>

                <div class="flex flex-col gap-2">
                    <!-- Loading skeleton for player artist and title -->
                    <div v-if="audioStore.isPlayerLoading" class="font-medium text-white mb-2 flex flex-col gap-2">
                        <Skeleton class="h-4 w-24" />
                        <Skeleton class="h-5 w-32" />
                    </div>
                    <!-- Actual player artist and title when loaded -->
                    <div v-else-if="audioStore.currentFile"
                         class="font-medium text-foreground mb-2 flex flex-col gap-1">
                        <span class="text-xs font-semibold text-muted-foreground">{{
                                excerpt(currentArtist) || 'Unknown Artist'
                            }}</span>
                        <span class="text-foreground font-semibold">{{ excerpt(currentTitle) }}</span>
                    </div>

                    <!-- Love/Like controls (right) -->
                    <div class="flex items-center gap-2 flex-1">
                        <button
                            class="button circular small empty"
                            :class="{ 'destructive': isLoved }"
                            @click="handleLove"
                            title="Love"
                        >
                            <Heart :size="16" />
                        </button>

                        <button
                            class="button circular small empty"
                            :class="{ 'active': isLiked }"
                            @click="handleLike"
                            title="Like"
                        >
                            <ThumbsUp :size="16" />
                        </button>

                        <button
                            class="button circular small empty"
                            :class="{ 'disabled': isDisliked }"
                            @click="handleDislike"
                            title="Dislike"
                        >
                            <ThumbsDown :size="16" />
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex-1">
                <!-- Progress bar skeleton -->
                <div v-if="audioStore.isPlayerLoading" class="mb-2">
                    <div class="flex gap-4 justify-between items-center text-xs text-white mb-2">
                        <Skeleton class="h-3 w-10" />
                        <Skeleton class="h-2 w-full" />
                        <Skeleton class="h-3 w-10" />
                    </div>
                </div>
                <!-- Actual progress bar -->
                <div v-else-if="audioStore.currentFile" class="mb-2">

                    <div class="flex gap-4 items-center text-xs text-muted-foreground mb-2">
                        <span>{{ formatTime(currentTime) }}</span>
                        <div
                            class="h-2 bg-muted rounded-full cursor-pointer transition-colors hover:bg-muted/80 flex-1"
                            @click="seekTo($event)"
                        >
                            <div
                                class="h-full bg-primary rounded-full transition-all"
                                :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                            ></div>
                        </div>
                        <span>{{ formatTime(duration) }}</span>
                    </div>

                    <!-- Player controls -->
                    <div class="flex items-center justify-center gap-4 mt-2">
                        <!-- Media controls (center) -->
                        <div class="flex items-center gap-4">
                            <button
                                class="button circular small empty"
                                @click="handleShuffle"
                                title="Shuffle"
                            >
                                <Shuffle :size="16" />
                            </button>

                            <button
                                class="button circular small empty"
                                @click="handlePrevious"
                                title="Previous"
                            >
                                <SkipBack :size="20" />
                            </button>

                            <button
                                class="button circular empty"
                                :class="{
                  'active': audioStore.isPlaying,
                  'secondary': !audioStore.isPlaying
                }"
                                @click="togglePlayPause"
                                title="Play/Pause"
                            >
                                <Play v-if="!audioStore.isPlaying" :size="24" />
                                <Pause v-else :size="24" />
                            </button>

                            <button
                                class="button circular small empty"
                                @click="handleNext"
                                title="Next"
                            >
                                <SkipForward :size="20" />
                            </button>

                            <button
                                class="button circular small empty"
                                :class="{
                  'bg-primary text-primary-foreground': audioStore.repeatMode === 'all',
                  'bg-blue-500 text-white': audioStore.repeatMode === 'one'
                }"
                                @click="handleRepeat"
                                :title="audioStore.repeatMode === 'off' ? 'Repeat Off' : audioStore.repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                            >
                                <Repeat1 v-if="audioStore.repeatMode === 'one'" :size="16" />
                                <Repeat v-else :size="16" />
                            </button>
                        </div>


                    </div>
                </div>
            </div>

            <div class="w-100">
                <!-- display incoming track -->
            </div>
        </div>
        <!-- End of player desktop -->

        <!-- Start of mobile player -->
        <div class="md:hidden">
            <div class="flex gap-2 items-center  mb-4">
                <!-- Loading skeleton for player cover -->
                <div v-if="audioStore.isPlayerLoading" class="flex items-center justify-center relative w-12 h-12">
                    <Skeleton class="w-full h-full" />
                </div>
                <!-- Actual player cover when loaded -->
                <div v-else-if="audioStore.currentFile" class="flex items-center justify-center relative w-12 h-12">
                    <img
                        v-if="coverImage"
                        :src="`/atlas/${coverImage}`"
                        alt="Cover"
                        class="w-full h-full object-cover"
                    />
                    <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
                        <span class="text-xs">No Cover</span>
                    </div>
                </div>

                <!-- Loading skeleton for player artist and title -->
                <div v-if="audioStore.isPlayerLoading" class="font-medium text-white flex flex-col gap-2 flex-1">
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="h-5 w-32" />
                </div>
                <!-- Actual player artist and title when loaded -->
                <div v-else-if="audioStore.currentFile"
                     class="font-medium text-foreground mb-2 flex flex-col gap-1 flex-1">
                    <span class="text-xs font-semibold text-muted-foreground">{{
                            excerpt(currentArtist) || 'Unknown Artist'
                        }}</span>
                    <span class="text-foreground font-semibold">{{ excerpt(currentTitle) }}</span>
                </div>

                <div class="flex items-center justify-center gap-2">
                    <button
                        class="btn-atlas-primary p-2 transition-colors ml-2"
                        @click="togglePlayPause"
                        title="Play/Pause"
                    >
                        <Play v-if="!audioStore.isPlaying" :size="20" />
                        <Pause v-else :size="20" />
                    </button>
                </div>
            </div>

            <!-- Progress bar skeleton -->
            <div v-if="audioStore.isPlayerLoading" class="mb-4">
                <Skeleton class="h-2 w-full mb-2" />
                <!--              <div class="flex justify-between text-xs text-white mb-2">-->
                <!--                  <Skeleton class="h-3 w-10" />-->
                <!--                  <Skeleton class="h-3 w-10" />-->
                <!--              </div>-->
            </div>
            <!-- Actual progress bar -->
            <div v-else-if="audioStore.currentFile" class="mb-4">
                <div
                    class="h-2 bg-muted rounded-full cursor-pointer mb-2 transition-colors hover:bg-muted/80"
                    @click="seekTo($event)"
                >
                    <div
                        class="h-full bg-primary rounded-full transition-all"
                        :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                    ></div>
                </div>
                <!--              <div class="flex justify-between text-xs text-muted-foreground mb-2">-->
                <!--                  <span>{{ formatTime(currentTime) }}</span>-->
                <!--                  <span>{{ formatTime(duration) }}</span>-->
                <!--              </div>-->
            </div>

            <!-- Mobile navigation controls -->
            <div class="flex items-center justify-center gap-4 mb-4">
                <button
                    class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
                    @click="handleShuffle"
                    title="Shuffle"
                >
                    <Shuffle :size="16" />
                </button>

                <button
                    class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
                    @click="handlePrevious"
                    title="Previous"
                >
                    <SkipBack :size="18" />
                </button>

                <button
                    class="btn-atlas-primary p-3 rounded-full hover:bg-primary/90 transition-colors"
                    @click="togglePlayPause"
                    title="Play/Pause"
                >
                    <Play v-if="!audioStore.isPlaying" :size="22" />
                    <Pause v-else :size="22" />
                </button>

                <button
                    class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
                    @click="handleNext"
                    title="Next"
                >
                    <SkipForward :size="18" />
                </button>

                <button
                    class="btn-atlas-secondary p-2 rounded-full hover:bg-secondary/80 transition-colors"
                    :class="{
                    'bg-primary text-primary-foreground': audioStore.repeatMode === 'all',
                    'bg-blue-500 text-white': audioStore.repeatMode === 'one'
                  }"
                    @click="handleRepeat"
                    :title="audioStore.repeatMode === 'off' ? 'Repeat Off' : audioStore.repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                >
                    <Repeat1 v-if="audioStore.repeatMode === 'one'" :size="16" />
                    <Repeat v-else :size="16" />
                </button>
            </div>

            <!-- Mobile reaction controls -->
            <div class="flex items-center justify-center gap-6">
                <button
                    class="button circular small"
                    :class="{ 'destructive': isLoved }"
                    @click="handleLove"
                    title="Love"
                >
                    <Heart :size="24" />
                </button>

                <button
                    class="button circular small"
                    :class="{ 'bg-blue-500 text-white': isLiked }"
                    @click="handleLike"
                    title="Like"
                >
                    <ThumbsUp :size="24" />
                </button>

                <button
                    class="button circular small"
                    :class="{ 'disabled': isDisliked }"
                    @click="handleDislike"
                    title="Dislike"
                >
                    <ThumbsDown :size="24" />
                </button>
            </div>
        </div>
        <!-- End of mobile player -->
    </div>
</template>
