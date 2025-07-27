<script lang="ts" setup>
import AudioQueuePanel from '@/components/audio/AudioQueuePanel.vue';
import AudioReactions from '@/components/audio/AudioReactions.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { audioActions, audioStore, getAudioElement } from '@/stores/audioStore';
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Menu, Pause, Play, Repeat, Repeat1, Shuffle, SkipBack, SkipForward } from 'lucide-vue-next';
import { computed, inject, onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Inject the loadFileDetails function from the parent component
const loadFileDetails = inject<(id: number, priority?: boolean) => Promise<any>>('loadFileDetails', () => Promise.resolve(null));

const currentTime = ref(audioStore.currentTime);
const duration = ref(audioStore.duration);
const volume = ref(audioStore.volume);

// Queue panel state
const isQueuePanelOpen = ref(false);

// Track user interactions with tracks
const isLiked = ref(false);
const isLoved = ref(false);
const isDisliked = ref(false);
const isLaughedAt = ref(false);

// Function to load interaction states from file data
function loadInteractionStates(file: any) {
    if (file) {
        // Load the love status from the file data
        isLiked.value = !!file.liked;
        isLoved.value = !!file.loved;
        isDisliked.value = !!file.disliked;
        isLaughedAt.value = !!file.funny;
    } else {
        // Reset when no file
        isLiked.value = false;
        isLoved.value = false;
        isDisliked.value = false;
        isLaughedAt.value = false;
    }
}

// Load interaction states when the current file changes
watch(() => audioStore.currentFile, loadInteractionStates, { immediate: true });

// Watch for reaction changes in the current file to sync local state
watch(
    () => audioStore.currentFile?.liked,
    (newValue) => {
        if (audioStore.currentFile && isLiked.value !== !!newValue) {
            isLiked.value = !!newValue;
        }
    },
);

watch(
    () => audioStore.currentFile?.loved,
    (newValue) => {
        if (audioStore.currentFile && isLoved.value !== !!newValue) {
            isLoved.value = !!newValue;
        }
    },
);

watch(
    () => audioStore.currentFile?.disliked,
    (newValue) => {
        if (audioStore.currentFile && isDisliked.value !== !!newValue) {
            isDisliked.value = !!newValue;
        }
    },
);

watch(
    () => audioStore.currentFile?.funny,
    (newValue) => {
        if (audioStore.currentFile && isLaughedAt.value !== !!newValue) {
            isLaughedAt.value = !!newValue;
        }
    },
);

// Get the current file title for display
const currentTitle = computed(() => {
    if (!audioStore.currentFile) return 'No file selected';
    return audioStore.currentFile.metadata?.payload?.title || 'Untitled';
});

// Get the current file artist for display
const currentArtist = computed(() => {
    if (!audioStore.currentFile) return 'Unknown Artist';
    return audioStore.currentFile.artists && audioStore.currentFile.artists.length > 0 ? audioStore.currentFile.artists[0].name : 'Unknown Artist';
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
    const audioElement = getAudioElement();
    if (!audioElement || !duration.value) return;

    const progressBar = event.currentTarget as HTMLElement;
    const rect = progressBar.getBoundingClientRect();
    const offsetX = event.clientX - rect.left;
    const percentage = offsetX / rect.width;

    const newTime = percentage * duration.value;
    audioElement.currentTime = newTime;
    audioActions.updateTime(newTime);
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
    const previousTrack = await audioActions.moveToPrevious(loadFileDetails);
    if (previousTrack) {
        audioActions.setPlaying(true);
    }
}

// Handle next track
async function handleNext(): Promise<void> {
    const nextTrack = await audioActions.moveToNext(loadFileDetails);
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
        isLaughedAt.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
        audioStore.currentFile.funny = isLaughedAt.value;
    }

    // Send request to backend
    router.post(
        route('audio.love', { file: audioStore.currentFile.id }),
        {},
        {
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
            },
        },
    );
}

function handleLike(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    isLiked.value = !isLiked.value;
    if (isLiked.value) {
        isLoved.value = false;
        isDisliked.value = false;
        isLaughedAt.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
        audioStore.currentFile.funny = isLaughedAt.value;
    }

    // Send request to backend
    router.post(
        route('audio.like', { file: audioStore.currentFile.id }),
        {},
        {
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
            },
        },
    );
}

function handleDislike(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    const wasDisliked = isDisliked.value;
    isDisliked.value = !isDisliked.value;
    if (isDisliked.value) {
        isLoved.value = false;
        isLiked.value = false;
        isLaughedAt.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
        audioStore.currentFile.funny = isLaughedAt.value;
    }

    // Automatically go to next track when disliking
    if (isDisliked.value) {
        handleNext();
    }

    // Send request to backend
    router.post(
        route('audio.dislike', { file: audioStore.currentFile.id }),
        {},
        {
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
            },
        },
    );
}

function handleLaughedAt(): void {
    if (!audioStore.currentFile) return;

    // Optimistically update the UI first
    isLaughedAt.value = !isLaughedAt.value;
    if (isLaughedAt.value) {
        isLoved.value = false;
        isLiked.value = false;
        isDisliked.value = false;
    }

    // Update the current file in the store
    if (audioStore.currentFile) {
        audioStore.currentFile.loved = isLoved.value;
        audioStore.currentFile.liked = isLiked.value;
        audioStore.currentFile.disliked = isDisliked.value;
        audioStore.currentFile.funny = isLaughedAt.value;
    }

    // Send request to backend
    router.post(
        route('audio.laughed-at', { file: audioStore.currentFile.id }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            only: [],
            onError: (errors) => {
                // Revert on error
                isLaughedAt.value = !isLaughedAt.value;
                if (audioStore.currentFile) {
                    audioStore.currentFile.funny = isLaughedAt.value;
                }
                console.error('Failed to toggle laughed at status:', errors);
            },
        },
    );
}

// Handle shuffle
function handleShuffle(): void {
    audioActions.shufflePlaylist();
}

// Handle repeat
function handleRepeat(): void {
    audioActions.toggleRepeat();
}

// Handle track title click to navigate to FileShow
function handleTitleClick(): void {
    if (audioStore.currentFile?.id) {
        router.visit(route('files.show', { file: audioStore.currentFile.id }));
    }
}

// Handle album cover click to scroll to current track
function handleAlbumCoverClick(): void {
    audioActions.scrollToCurrentTrack();
}

// Handle queue panel toggle
function toggleQueuePanel(): void {
    isQueuePanelOpen.value = !isQueuePanelOpen.value;
}

// Handle queue panel close
function closeQueuePanel(): void {
    isQueuePanelOpen.value = false;
}

// Handle minimize/restore toggle
function toggleMinimized(): void {
    audioActions.toggleMinimized();
}

// Function to handle play/pause based on isPlaying prop
function updatePlayState(newIsPlaying: boolean): void {
    const audioElement = getAudioElement();
    if (!audioElement) {
        return;
    }

    if (newIsPlaying) {
        // Use a promise to ensure play() is handled properly
        const playPromise = audioElement.play();

        // Handle play promise to catch any errors
        if (playPromise !== undefined) {
            playPromise.catch((error) => {
                console.error('Error playing audio:', error);
                // If autoplay is blocked, update the store state
                audioActions.setPlaying(false);
            });
        }
    } else {
        audioElement.pause();
    }
    audioActions.setPlaying(newIsPlaying);
}

// Function to update the current file
function updateCurrentFile(newFile: any): void {
    const audioElement = getAudioElement();
    if (!audioElement) {
        return;
    }

    if (newFile) {
        // Always reset currentTime to 0 when changing tracks
        audioElement.currentTime = 0;

        audioElement.src = `/audio/stream/${newFile.id}`;
        // Explicitly load the audio before attempting to play
        audioElement.load();

        if (audioStore.isPlaying) {
            // Use a promise to ensure play() is called after the audio is loaded
            const playPromise = audioElement.play();

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

// Watch for changes to isPlaying and currentFile
watch(() => audioStore.isPlaying, updatePlayState);
watch(() => audioStore.currentFile, updateCurrentFile);

// Initialize the audio player when the component is mounted
onMounted(() => {
    const audioEl = getAudioElement();
    if (!audioEl) {
        return;
    }

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

// Drag and drop state
const isDragging = ref(false);

// Drag and drop functions
const handleDragEnter = (event: DragEvent): void => {
    event.preventDefault();
    isDragging.value = true;
};

const handleDragOver = (event: DragEvent): void => {
    event.preventDefault();
};

const handleDragLeave = (event: DragEvent): void => {
    event.preventDefault();
    // Only set isDragging to false if we're actually leaving the drop zone
    // Check if the related target is outside the current target
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = event.clientX;
    const y = event.clientY;

    if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
        isDragging.value = false;
    }
};

const handleDrop = async (event: DragEvent): Promise<void> => {
    event.preventDefault();
    isDragging.value = false;

    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;

    const file = files[0];
    if (!file.type.startsWith('image/')) {
        alert('Please drop an image file');
        return;
    }

    if (!audioStore.currentFile) return;

    // Get the cover ID - prioritize album covers first, then file covers
    let coverId: number | null = null;

    // First check for album covers
    if (audioStore.currentFile.albums && audioStore.currentFile.albums.length > 0) {
        for (const album of audioStore.currentFile.albums) {
            if (album.covers && album.covers.length > 0) {
                coverId = album.covers[0].id;
                break;
            }
        }
    }

    // Fall back to file covers
    if (!coverId && audioStore.currentFile.covers && audioStore.currentFile.covers.length > 0) {
        coverId = audioStore.currentFile.covers[0].id;
    }

    try {
        if (coverId) {
            // Update existing cover
            router.post(
                route('covers.update', { coverId: coverId }),
                {
                    file: file,
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        // The page will be refreshed with updated covers
                    },
                    onError: (errors) => {
                        console.error('Error uploading cover:', errors);
                        alert('Failed to upload cover image');
                    },
                },
            );
        } else {
            // Create new cover for the file
            router.post(
                route('covers.create', { fileId: audioStore.currentFile.id }),
                {
                    file: file,
                },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: () => {
                        // The page will be refreshed with new cover
                    },
                    onError: (errors) => {
                        console.error('Error creating cover:', errors);
                        alert('Failed to create cover image');
                    },
                },
            );
        }
    } catch (error) {
        console.error('Error handling cover:', error);
        alert('Failed to handle cover image');
    }
};
</script>

<template>
    <div
        v-if="audioStore.isPlayerLoading || audioStore.currentFile"
        class="sticky bottom-0 left-0 w-full border-t border-border bg-card px-4 py-2 md:p-4"
    >
        <!-- We're using a Vue-managed persistent audio element from the store -->
        <!-- Start of player desktop -->
        <div class="hidden items-center gap-4 md:flex">
            <!-- Minimized View -->
            <template v-if="audioStore.isPlayerMinimized">
                <div class="flex flex-1 items-center gap-4">
                    <!-- Small cover -->
                    <div v-if="audioStore.isPlayerLoading" class="relative flex h-12 w-12 items-center justify-center">
                        <Skeleton class="h-full w-full" />
                    </div>
                    <div
                        v-else-if="audioStore.currentFile"
                        class="relative flex h-12 w-12 shrink-0 cursor-pointer items-center justify-center"
                        @click="handleAlbumCoverClick"
                    >
                        <img v-if="coverImage" :src="`/atlas/${coverImage}`" alt="Cover" class="h-full w-full rounded object-cover" />
                        <div v-else class="flex h-full w-full items-center justify-center rounded bg-muted text-muted-foreground">
                            <span class="text-xs">♪</span>
                        </div>
                    </div>

                    <!-- Title and basic controls -->
                    <div class="flex flex-1 items-center gap-4">
                        <div class="flex min-w-0 flex-col">
                            <div v-if="audioStore.isPlayerLoading" class="flex gap-2 font-medium text-white">
                                <Skeleton class="h-4 w-32" />
                            </div>
                            <div v-else-if="audioStore.currentFile" class="font-medium text-foreground">
                                <span
                                    class="cursor-pointer truncate text-sm font-semibold transition-colors hover:text-primary"
                                    @click="handleTitleClick"
                                    >{{ currentTitle }}</span
                                >
                                <span class="block truncate text-xs text-muted-foreground">{{ currentArtist || 'Unknown Artist' }}</span>
                            </div>
                        </div>

                        <!-- Basic playback controls -->
                        <div class="flex items-center gap-2">
                            <button class="button circular small empty" title="Previous" @click="handlePrevious">
                                <SkipBack :size="16" />
                            </button>

                            <button
                                :class="{
                                    active: audioStore.isPlaying,
                                    secondary: !audioStore.isPlaying,
                                }"
                                class="button circular empty"
                                title="Play/Pause"
                                @click="togglePlayPause"
                            >
                                <Play v-if="!audioStore.isPlaying" :size="20" />
                                <Pause v-else :size="20" />
                            </button>

                            <button class="button circular small empty" title="Next" @click="handleNext">
                                <SkipForward :size="16" />
                            </button>
                        </div>

                        <!-- Compact progress bar -->
                        <div v-if="audioStore.currentFile" class="mx-4 min-w-0 flex-1">
                            <div class="h-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)">
                                <div
                                    :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                                    class="h-full rounded-full bg-primary transition-all"
                                ></div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- Full View -->
            <template v-else>
                <div class="flex w-100 items-center gap-4">
                    <!-- Loading skeleton for player cover -->
                    <div v-if="audioStore.isPlayerLoading" class="relative flex h-18 w-18 items-center justify-center md:h-32 md:w-32">
                        <Skeleton class="h-full w-full" />
                    </div>
                    <!-- Actual player cover when loaded -->
                    <div
                        v-else-if="audioStore.currentFile"
                        :class="isDragging ? 'border-2 border-dashed border-blue-300 bg-blue-50' : ''"
                        class="relative flex h-18 w-18 shrink-0 cursor-pointer items-center justify-center transition-all duration-300 md:h-32 md:w-32"
                        @click="handleAlbumCoverClick"
                        @dragenter="handleDragEnter"
                        @dragleave="handleDragLeave"
                        @dragover="handleDragOver"
                        @drop="handleDrop"
                    >
                        <img
                            v-if="coverImage"
                            :class="isDragging ? 'opacity-50' : ''"
                            :src="`/atlas/${coverImage}`"
                            alt="Cover"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                            <span class="text-xs">No Cover</span>
                        </div>

                        <!-- Drag Overlay -->
                        <div v-if="isDragging" class="absolute inset-0 flex items-center justify-center bg-blue-50/80">
                            <div class="text-center">
                                <span class="text-xs font-medium text-blue-700">Drop to replace</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 truncate">
                        <!-- Loading skeleton for player artist and title -->
                        <div v-if="audioStore.isPlayerLoading" class="mb-2 flex flex-col gap-2 font-medium text-white">
                            <Skeleton class="h-4 w-24" />
                            <Skeleton class="h-5 w-32" />
                        </div>
                        <!-- Actual player artist and title when loaded -->
                        <div v-else-if="audioStore.currentFile" class="mb-2 flex flex-col gap-1 font-medium text-foreground">
                            <span class="truncate text-xs font-semibold text-muted-foreground">{{ currentArtist || 'Unknown Artist' }}</span>
                            <span
                                class="cursor-pointer truncate font-semibold text-foreground transition-colors hover:text-primary"
                                @click="handleTitleClick"
                                >{{ currentTitle }}</span
                            >
                        </div>

                        <!-- Love/Like controls (right) -->
                        <div class="flex flex-1 items-center">
                            <AudioReactions
                                :file="audioStore.currentFile"
                                :icon-size="16"
                                :show-labels="true"
                                variant="player"
                                @dislike="handleDislike"
                                @favorite="handleLove"
                                @like="handleLike"
                                @laughed-at="handleLaughedAt"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex-1">
                    <!-- Progress bar skeleton -->
                    <div v-if="audioStore.isPlayerLoading" class="mb-2">
                        <div class="mb-2 flex items-center justify-between gap-4 text-xs text-white">
                            <Skeleton class="h-3 w-10" />
                            <Skeleton class="h-2 w-full" />
                            <Skeleton class="h-3 w-10" />
                        </div>
                    </div>
                    <!-- Actual progress bar -->
                    <div v-else-if="audioStore.currentFile" class="mb-2">
                        <div class="mb-2 flex items-center gap-4 text-xs text-muted-foreground">
                            <span>{{ formatTime(currentTime) }}</span>
                            <div class="h-2 flex-1 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)">
                                <div
                                    :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                                    class="h-full rounded-full bg-primary transition-all"
                                ></div>
                            </div>
                            <span>{{ formatTime(duration) }}</span>
                        </div>

                        <!-- Player controls -->
                        <div class="mt-2 flex items-center justify-center gap-4">
                            <!-- Media controls (center) -->
                            <div class="flex items-center gap-4">
                                <button class="button circular small empty" title="Shuffle" @click="handleShuffle">
                                    <Shuffle :size="16" />
                                </button>

                                <button class="button circular small empty" title="Previous" @click="handlePrevious">
                                    <SkipBack :size="20" />
                                </button>

                                <button
                                    :class="{
                                        active: audioStore.isPlaying,
                                        secondary: !audioStore.isPlaying,
                                    }"
                                    class="button circular empty"
                                    title="Play/Pause"
                                    @click="togglePlayPause"
                                >
                                    <Play v-if="!audioStore.isPlaying" :size="24" />
                                    <Pause v-else :size="24" />
                                </button>

                                <button class="button circular small empty" title="Next" @click="handleNext">
                                    <SkipForward :size="20" />
                                </button>

                                <button
                                    :class="{
                                        'bg-primary text-primary-foreground': audioStore.repeatMode === 'all',
                                        'bg-blue-500 text-white': audioStore.repeatMode === 'one',
                                    }"
                                    :title="
                                        audioStore.repeatMode === 'off' ? 'Repeat Off' : audioStore.repeatMode === 'all' ? 'Repeat All' : 'Repeat One'
                                    "
                                    class="button circular small empty"
                                    @click="handleRepeat"
                                >
                                    <Repeat1 v-if="audioStore.repeatMode === 'one'" :size="16" />
                                    <Repeat v-else :size="16" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <div class="flex w-100 justify-end gap-2">
                <!-- Minimize/Restore button -->
                <button
                    :title="audioStore.isPlayerMinimized ? 'Restore Player' : 'Minimize Player'"
                    class="button circular small empty"
                    @click="toggleMinimized"
                >
                    <ChevronUp v-if="audioStore.isPlayerMinimized" :size="16" />
                    <ChevronDown v-else :size="16" />
                </button>

                <!-- Queue panel toggle button -->
                <button
                    :class="{ 'bg-primary text-primary-foreground': isQueuePanelOpen }"
                    class="button circular small empty"
                    title="Show Queue"
                    @click="toggleQueuePanel"
                >
                    <Menu :size="16" />
                </button>
            </div>
        </div>
        <!-- End of player desktop -->

        <!-- Queue Panel -->
        <AudioQueuePanel :is-open="isQueuePanelOpen" @close="closeQueuePanel" />

        <!-- Start of mobile player -->
        <div class="md:hidden">
            <div class="mb-4 flex items-center gap-2">
                <!-- Loading skeleton for player cover -->
                <div v-if="audioStore.isPlayerLoading" class="relative flex h-16 w-16 items-center justify-center">
                    <Skeleton class="h-full w-full" />
                </div>
                <!-- Actual player cover when loaded -->
                <div
                    v-else-if="audioStore.currentFile"
                    :class="isDragging ? 'border-2 border-dashed border-blue-300 bg-blue-50' : ''"
                    class="relative flex h-16 w-16 items-center justify-center transition-all duration-300"
                    @dragenter="handleDragEnter"
                    @dragleave="handleDragLeave"
                    @dragover="handleDragOver"
                    @drop="handleDrop"
                >
                    <img
                        v-if="coverImage"
                        :class="isDragging ? 'opacity-50' : ''"
                        :src="`/atlas/${coverImage}`"
                        alt="Cover"
                        class="h-full w-full object-cover"
                    />
                    <div v-else class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground">
                        <span class="text-xs">No Cover</span>
                    </div>

                    <!-- Drag Overlay -->
                    <div v-if="isDragging" class="absolute inset-0 flex items-center justify-center bg-blue-50/80">
                        <div class="text-center">
                            <span class="text-xs font-medium text-blue-700">Drop to replace</span>
                        </div>
                    </div>
                </div>

                <!-- Loading skeleton for player artist and title -->
                <div v-if="audioStore.isPlayerLoading" class="flex flex-1 flex-col gap-2 font-medium text-white">
                    <Skeleton class="h-4 w-24" />
                    <Skeleton class="h-5 w-32" />
                </div>
                <!-- Actual player artist and title when loaded -->
                <div v-else-if="audioStore.currentFile" class="flex flex-1 flex-col gap-1 truncate font-medium text-foreground">
                    <span class="truncate text-xs font-semibold text-muted-foreground">{{ currentArtist || 'Unknown Artist' }}</span>
                    <span
                        class="cursor-pointer truncate font-semibold text-foreground transition-colors hover:text-primary"
                        @click="handleTitleClick"
                        >{{ currentTitle }}</span
                    >
                </div>

                <!-- Minimize/Restore button for mobile -->
                <button
                    :title="audioStore.isPlayerMinimized ? 'Restore Player' : 'Minimize Player'"
                    class="button circular small empty"
                    @click="toggleMinimized"
                >
                    <ChevronUp v-if="audioStore.isPlayerMinimized" :size="16" />
                    <ChevronDown v-else :size="16" />
                </button>
            </div>

            <!-- Progress bar and controls - only show when not minimized -->
            <template v-if="!audioStore.isPlayerMinimized">
                <!-- Progress bar skeleton -->
                <div v-if="audioStore.isPlayerLoading" class="mb-2">
                    <Skeleton class="mb-2 h-2 w-full" />
                    <div class="mb-2 flex justify-between text-xs text-white">
                        <Skeleton class="h-3 w-10" />
                        <Skeleton class="h-3 w-10" />
                    </div>
                </div>
                <!-- Actual progress bar -->
                <div v-else-if="audioStore.currentFile" class="mb-2">
                    <div class="mb-2 h-2 cursor-pointer rounded-full bg-muted transition-colors hover:bg-muted/80" @click="seekTo($event)">
                        <div
                            :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                            class="h-full rounded-full bg-primary transition-all"
                        ></div>
                    </div>
                    <div class="mb-2 flex justify-between text-xs text-muted-foreground">
                        <span>{{ formatTime(currentTime) }}</span>
                        <span>{{ formatTime(duration) }}</span>
                    </div>
                </div>

                <!-- Mobile navigation controls -->
                <div class="flex items-center justify-center gap-4">
                    <button class="button circular small empty" title="Shuffle" @click="handleShuffle">
                        <Shuffle :size="12" />
                    </button>

                    <button class="button circular small empty" title="Previous" @click="handlePrevious">
                        <SkipBack :size="16" />
                    </button>

                    <button
                        :class="{
                            active: audioStore.isPlaying,
                            secondary: !audioStore.isPlaying,
                        }"
                        class="button circular empty"
                        title="Play/Pause"
                        @click="togglePlayPause"
                    >
                        <Play v-if="!audioStore.isPlaying" :size="24" />
                        <Pause v-else :size="18" />
                    </button>

                    <button class="button circular small empty" title="Next" @click="handleNext">
                        <SkipForward :size="16" />
                    </button>

                    <button
                        :class="{
                            'bg-primary text-primary-foreground': audioStore.repeatMode === 'all',
                            'bg-blue-500 text-white': audioStore.repeatMode === 'one',
                        }"
                        :title="audioStore.repeatMode === 'off' ? 'Repeat Off' : audioStore.repeatMode === 'all' ? 'Repeat All' : 'Repeat One'"
                        class="button circular small empty"
                        @click="handleRepeat"
                    >
                        <Repeat1 v-if="audioStore.repeatMode === 'one'" :size="16" />
                        <Repeat v-else :size="12" />
                    </button>
                </div>
            </template>
        </div>
        <!-- End of mobile player -->
    </div>
</template>
