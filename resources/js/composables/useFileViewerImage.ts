import type { MasonryItem } from './useBrowseTabs';
import { incrementSeen } from '@/actions/App/Http/Controllers/FilesController';

/**
 * Composable for handling image preloading and seen count tracking in FileViewer.
 */
export function useFileViewerImage(items: import('vue').Ref<MasonryItem[]>) {
    /**
     * Preload an image and return its dimensions.
     */
    function preloadImage(url: string): Promise<{ width: number; height: number }> {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
            img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
            img.src = url;
        });
    }

    /**
     * Increment seen count when file is loaded in FileViewer.
     * This increments every time a file comes into view (including when navigating back to it).
     */
    async function handleItemSeen(fileId: number): Promise<void> {
        try {
            const response = await window.axios.post<{ seen_count: number }>(incrementSeen.url(fileId));

            // Update local item state
            const item = items.value.find((i) => i.id === fileId);
            if (item) {
                item.seen_count = response.data.seen_count;
            }
        } catch (error) {
            console.error('Failed to increment seen count:', error);
            // Don't throw - seen count is not critical
        }
    }

    /**
     * Calculate the best-fit size for an image within a container while maintaining aspect ratio.
     */
    function calculateBestFitSize(
        originalWidth: number,
        originalHeight: number,
        containerWidth: number,
        containerHeight: number
    ): { width: number; height: number } {
        // If image is smaller than container in both dimensions, use original size (will be centered)
        if (originalWidth <= containerWidth && originalHeight <= containerHeight) {
            return {
                width: originalWidth,
                height: originalHeight,
            };
        }

        // Image is larger than container - scale down to fit while maintaining aspect ratio
        const aspectRatio = originalWidth / originalHeight;
        const containerAspectRatio = containerWidth / containerHeight;

        let fitWidth: number;
        let fitHeight: number;

        if (aspectRatio > containerAspectRatio) {
            // Image is wider - fit to width
            fitWidth = containerWidth;
            fitHeight = containerWidth / aspectRatio;
        } else {
            // Image is taller - fit to height
            fitHeight = containerHeight;
            fitWidth = containerHeight * aspectRatio;
        }

        // Ensure dimensions don't exceed container bounds (account for rounding errors)
        fitWidth = Math.min(fitWidth, containerWidth);
        fitHeight = Math.min(fitHeight, containerHeight);

        return {
            width: Math.floor(fitWidth), // Use floor to ensure we don't exceed bounds
            height: Math.floor(fitHeight),
        };
    }

    return {
        preloadImage,
        handleItemSeen,
        calculateBestFitSize,
    };
}

