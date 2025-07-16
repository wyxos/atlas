import { ref, watch, onMounted, onBeforeUnmount, provide } from 'vue';
import { router } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { audioStore, audioActions } from '@/stores/audioStore';
import { useAudioFileLoader } from '@/components/audio/useAudioFileLoader';
import { useAudioSwipeHandler } from '@/components/audio/useAudioSwipeHandler';

interface AudioListProps {
    files: any[];
    search: any[];
}

export function useAudioList(props: AudioListProps) {
    // Use our composables
    const {
        loadedFiles,
        loadFileDetails,
        loadBatchFileDetails,
        getFileData
    } = useAudioFileLoader();

    const {
        swipedItemId,
        handleTouchStart,
        handleTouchMove,
        handleTouchEnd,
        handleGlobalClick
    } = useAudioSwipeHandler();

    // RecycleScroller ref for scrollToItem functionality
    const recycleScrollerRef = ref<InstanceType<typeof RecycleScroller> | null>(null);

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

    // Generic reaction handler
    function handleReaction(item: any, event: Event, reactionType: 'loved' | 'liked' | 'disliked' | 'funny', routeName: string): void {
        event.stopPropagation(); // Prevent triggering parent click events

        // Optimistically update the UI first
        if (loadedFiles[item.id]) {
            loadedFiles[item.id][reactionType] = !loadedFiles[item.id][reactionType];
            if (loadedFiles[item.id][reactionType]) {
                // Reset other reactions when one is selected
                const otherReactions = ['loved', 'liked', 'disliked', 'funny'].filter(r => r !== reactionType);
                otherReactions.forEach(reaction => {
                    loadedFiles[item.id][reaction] = false;
                });
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
        router.post(route(routeName, { file: item.id }), {}, {
            preserveState: true,
            preserveScroll: true,
            only: [],
            onError: (errors) => {
                // Revert on error
                if (loadedFiles[item.id]) {
                    loadedFiles[item.id][reactionType] = !loadedFiles[item.id][reactionType];
                }
                console.error(`Failed to toggle ${reactionType} status:`, errors);
            }
        });

        // Close the swipe actions after action
        swipedItemId.value = null;
    }

    // Action handlers
    function toggleFavorite(item: any, event: Event): void {
        handleReaction(item, event, 'loved', 'audio.love');
    }

    function likeItem(item: any, event: Event): void {
        handleReaction(item, event, 'liked', 'audio.like');
    }

    function dislikeItem(item: any, event: Event): void {
        handleReaction(item, event, 'disliked', 'audio.dislike');
    }

    function laughedAtItem(item: any, event: Event): void {
        handleReaction(item, event, 'funny', 'audio.laughed-at');
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

    // Handle scroll to current track functionality
    function handleScrollToCurrentTrack(event: CustomEvent) {
        const { currentFileId } = event.detail;

        if (!recycleScrollerRef.value || !currentFileId) return;

        // Get the current items list (search results or all files)
        const currentItems = props.search.length ? props.search : props.files;

        // Find the index of the current playing file in the displayed list
        const index = currentItems.findIndex(item => item.id === currentFileId);

        if (index !== -1) {
            // Scroll to the item using RecycleScroller's scrollToItem method
            recycleScrollerRef.value.scrollToItem(index);
        }
    }

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

            // Collect file IDs that need to be loaded
            const fileIdsToLoad: number[] = [];
            for (let i = visibleStartIndex; i <= visibleEndIndex; i++) {
                if (i >= 0 && i < currentItems.length) {
                    const item = currentItems[i];
                    if (item && !loadedFiles[item.id]) {
                        fileIdsToLoad.push(item.id);
                    }
                }
            }

            // Load all needed files in a single batch request
            if (fileIdsToLoad.length > 0) {
                loadBatchFileDetails(fileIdsToLoad);
            }
        }, 500); // 500ms debounce to detect scroll stop
    }

    // Initial query from URL
    const initialQuery = window.location.search
        ? new URLSearchParams(window.location.search).get('query') || ''
        : '';

    onMounted(() => {
        // Provide the loadFileDetails function for the AudioPlayer
        provide('loadFileDetails', loadFileDetails);

        // Listen for scroll to current track events
        window.addEventListener('scrollToCurrentTrack', handleScrollToCurrentTrack as EventListener);
    });

    onBeforeUnmount(() => {
        // Clean up event listener
        window.removeEventListener('scrollToCurrentTrack', handleScrollToCurrentTrack as EventListener);
    });

    return {
        // Composable state
        loadedFiles,
        swipedItemId,
        recycleScrollerRef,
        initialQuery,

        // Functions
        playAudio,
        getFileData,
        toggleFavorite,
        likeItem,
        dislikeItem,
        laughedAtItem,
        handleTouchStart,
        handleTouchMove,
        handleTouchEnd,
        handleGlobalClick,
        onScroll
    };
}
