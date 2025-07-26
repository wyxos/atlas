import type { BrowseItem } from '@/types/browse';
import axios from 'axios';

export function useItemReactions() {
    const startDownload = async (item: BrowseItem) => {
        try {
            await axios.post(route('browse.download', { file: item.id }));
            console.log('Download started for item:', item.id);
        } catch (error) {
            console.error('Failed to start download:', error);
        }
    };

    const handleFavorite = async (file: any, event: Event, onRemove?: (item: BrowseItem) => void) => {
        console.log('Love reaction - starting download:', file.id);

        // Update local state optimistically
        const originalLoved = file.loved;
        file.loved = !file.loved;
        if (file.loved) {
            file.liked = false;
            file.disliked = false;
            file.funny = false;
        }

        try {
            // Persist to backend
            const response = await axios.post(route('files.love', { file: file.id }));

            // Update with server response
            Object.assign(file, response.data);

            // Start download
            startDownload(file);

            // Remove from view if favorited
            if (file.loved && onRemove) {
                onRemove(file);
            }
        } catch (error) {
            // Revert on error
            file.loved = originalLoved;
            console.error('Failed to toggle love status:', error);
        }
    };

    const handleLike = async (file: any, event: Event, onRemove?: (item: BrowseItem) => void) => {
        console.log('Like reaction - starting download:', file.id);

        // Update local state optimistically
        const originalLiked = file.liked;
        file.liked = !file.liked;
        if (file.liked) {
            file.loved = false;
            file.disliked = false;
            file.funny = false;
        }

        try {
            // Persist to backend
            const response = await axios.post(route('files.like', { file: file.id }));

            // Update with server response
            Object.assign(file, response.data);

            // Start download
            startDownload(file);

            // Remove from view if liked
            if (file.liked && onRemove) {
                onRemove(file);
            }
        } catch (error) {
            // Revert on error
            file.liked = originalLiked;
            console.error('Failed to toggle like status:', error);
        }
    };

    const handleDislike = async (file: any, event: Event, onBlacklist: (item: BrowseItem) => void) => {
        console.log('Dislike reaction - blacklisting:', file.id);

        // Update local state optimistically
        const originalDisliked = file.disliked;
        file.disliked = !file.disliked;
        if (file.disliked) {
            file.loved = false;
            file.liked = false;
            file.funny = false;
        }

        try {
            // Persist to backend
            const response = await axios.post(route('files.dislike', { file: file.id }));

            // Update with server response
            Object.assign(file, response.data);
        } catch (error) {
            // Revert on error
            file.disliked = originalDisliked;
            console.error('Failed to toggle dislike status:', error);
        }

        // Blacklist the image
        onBlacklist(file);
    };

    const handleLaughedAt = async (file: any, event: Event, onRemove?: (item: BrowseItem) => void) => {
        console.log('Funny reaction - starting download:', file.id);

        // Update local state optimistically
        const originalFunny = file.funny;
        file.funny = !file.funny;
        if (file.funny) {
            file.loved = false;
            file.liked = false;
            file.disliked = false;
        }

        try {
            // Persist to backend
            const response = await axios.post(route('files.laughed-at', { file: file.id }));

            // Update with server response
            Object.assign(file, response.data);

            // Start download
            startDownload(file);

            // Remove from view if laughed at
            if (file.funny && onRemove) {
                onRemove(file);
            }
        } catch (error) {
            // Revert on error
            file.funny = originalFunny;
            console.error('Failed to toggle funny status:', error);
        }
    };

    const blacklistImage = async (item: BrowseItem, masonry: any) => {
        console.log('Blacklisting image:', item.id);

        try {
            // Call backend to blacklist the item using axios
            await axios.post(route('browse.blacklist', { file: item.id }), {
                reason: 'Blacklisted via browse interface',
            });

            // Remove from UI immediately for better user experience
            if (masonry && typeof masonry.onRemove === 'function') {
                masonry.onRemove(item);
            }
            console.log('Item blacklisted successfully:', item.id);
        } catch (error) {
            console.error('Failed to blacklist item:', error);
            // Could optionally show a toast notification here
            // but we don't re-add the item since the user intent was to remove it
        }
    };

    return {
        startDownload,
        handleFavorite,
        handleLike,
        handleDislike,
        handleLaughedAt,
        blacklistImage,
    };
}
