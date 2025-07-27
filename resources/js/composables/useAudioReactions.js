import { router } from '@inertiajs/vue3';
import { audioStore } from '@/stores/audioStore';

export function useAudioReactions() {
    // Helper function to clear other reactions when one is set
    function clearOtherReactions(activeReaction) {
        if (!audioStore.currentFile) return;
        
        if (activeReaction !== 'liked') audioStore.currentFile.liked = false;
        if (activeReaction !== 'loved') audioStore.currentFile.loved = false;
        if (activeReaction !== 'disliked') audioStore.currentFile.disliked = false;
        if (activeReaction !== 'funny') audioStore.currentFile.funny = false;
    }

    // Handle love reaction
    function handleLove(onNext) {
        if (!audioStore.currentFile) return;

        // Store original state for error recovery
        const wasLoved = audioStore.currentFile.loved;

        // Optimistically update the UI first
        audioStore.currentFile.loved = !wasLoved;
        if (audioStore.currentFile.loved) {
            clearOtherReactions('loved');
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
                    if (audioStore.currentFile) {
                        audioStore.currentFile.loved = wasLoved;
                    }
                    console.error('Failed to toggle love status:', errors);
                },
            },
        );
    }

    // Handle like reaction
    function handleLike() {
        if (!audioStore.currentFile) return;

        // Store original state for error recovery
        const wasLiked = audioStore.currentFile.liked;

        // Optimistically update the UI first
        audioStore.currentFile.liked = !wasLiked;
        if (audioStore.currentFile.liked) {
            clearOtherReactions('liked');
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
                    if (audioStore.currentFile) {
                        audioStore.currentFile.liked = wasLiked;
                    }
                    console.error('Failed to toggle like status:', errors);
                },
            },
        );
    }

    // Handle dislike reaction
    function handleDislike(onNext) {
        if (!audioStore.currentFile) return;

        // Store original state for error recovery
        const wasDisliked = audioStore.currentFile.disliked;

        // Optimistically update the UI first
        audioStore.currentFile.disliked = !wasDisliked;
        if (audioStore.currentFile.disliked) {
            clearOtherReactions('disliked');
        }

        // Automatically go to next track when disliking
        if (audioStore.currentFile.disliked && onNext) {
            onNext();
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
                    if (audioStore.currentFile) {
                        audioStore.currentFile.disliked = wasDisliked;
                    }
                    console.error('Failed to toggle dislike status:', errors);
                },
            },
        );
    }

    // Handle laughed-at reaction
    function handleLaughedAt() {
        if (!audioStore.currentFile) return;

        // Store original state for error recovery
        const wasLaughedAt = audioStore.currentFile.funny;

        // Optimistically update the UI first
        audioStore.currentFile.funny = !wasLaughedAt;
        if (audioStore.currentFile.funny) {
            clearOtherReactions('funny');
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
                    if (audioStore.currentFile) {
                        audioStore.currentFile.funny = wasLaughedAt;
                    }
                    console.error('Failed to toggle laughed at status:', errors);
                },
            },
        );
    }

    return {
        // Actions
        handleLove,
        handleLike,
        handleDislike,
        handleLaughedAt,
    };
}
