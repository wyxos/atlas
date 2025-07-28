import { ref, computed } from 'vue';

export interface ImageData {
    id: number;
    name?: string;
    url?: string;
    src?: string; // For BrowseItem compatibility
    original?: string; // For BrowseItem original URL
    image_url?: string;
    downloaded?: boolean;
    not_found?: boolean;
    path?: string;
    loved?: boolean;
    liked?: boolean;
    disliked?: boolean;
    funny?: boolean;
}

export function useImageZoom() {
    // Image viewer state
    const isImageViewerOpen = ref(false);
    const imageViewerZoom = ref(1);
    const imageViewerPosition = ref({ x: 0, y: 0 });
    const currentImage = ref<ImageData | null>(null);
    const allImages = ref<ImageData[]>([]);
    const currentIndex = ref(0);

    // Image viewer functions
    const openImageViewer = (image: ImageData, imageList: ImageData[] = []) => {
        currentImage.value = image;
        allImages.value = imageList;
        currentIndex.value = imageList.findIndex(img => img.id === image.id) || 0;
        isImageViewerOpen.value = true;
        imageViewerZoom.value = 1;
        imageViewerPosition.value = { x: 0, y: 0 };
    };

    const closeImageViewer = () => {
        isImageViewerOpen.value = false;
        currentImage.value = null;
        allImages.value = [];
        currentIndex.value = 0;
    };

    const zoomIn = () => {
        imageViewerZoom.value = Math.min(imageViewerZoom.value * 1.2, 5);
    };

    const zoomOut = () => {
        imageViewerZoom.value = Math.max(imageViewerZoom.value / 1.2, 0.1);
    };

    const resetZoom = () => {
        imageViewerZoom.value = 1;
        imageViewerPosition.value = { x: 0, y: 0 };
    };

    // Handle image dragging in viewer
    const isDragging = ref(false);
    const dragStart = ref({ x: 0, y: 0 });

    const startDrag = (event: MouseEvent) => {
        if (imageViewerZoom.value > 1) {
            isDragging.value = true;
            dragStart.value = {
                x: event.clientX - imageViewerPosition.value.x,
                y: event.clientY - imageViewerPosition.value.y
            };
        }
    };

    const onDrag = (event: MouseEvent) => {
        if (isDragging.value && imageViewerZoom.value > 1) {
            imageViewerPosition.value = {
                x: event.clientX - dragStart.value.x,
                y: event.clientY - dragStart.value.y
            };
        }
    };

    const stopDrag = () => {
        isDragging.value = false;
    };

    // Navigation functions
    const goToNext = () => {
        if (allImages.value.length > 0 && currentIndex.value < allImages.value.length - 1) {
            currentIndex.value++;
            currentImage.value = allImages.value[currentIndex.value];
            resetZoom();
        }
    };

    const goToPrevious = () => {
        if (allImages.value.length > 0 && currentIndex.value > 0) {
            currentIndex.value--;
            currentImage.value = allImages.value[currentIndex.value];
            resetZoom();
        }
    };

    const canGoNext = computed(() => {
        return allImages.value.length > 0 && currentIndex.value < allImages.value.length - 1;
    });

    const canGoPrevious = computed(() => {
        return allImages.value.length > 0 && currentIndex.value > 0;
    });

    // Remove current item and navigate to next
    const removeCurrentAndGoNext = () => {
        if (allImages.value.length > 0) {
            // Remove current item from the array
            allImages.value.splice(currentIndex.value, 1);
            
            // If we removed the last item, go to previous
            if (currentIndex.value >= allImages.value.length && currentIndex.value > 0) {
                currentIndex.value--;
            }
            
            // Update current image or close if no items left
            if (allImages.value.length > 0) {
                currentImage.value = allImages.value[currentIndex.value];
                resetZoom();
            } else {
                closeImageViewer();
            }
        }
    };

    // Get the image URL for display
    const imageUrl = computed(() => {
        if (!currentImage.value) return '';
        
        // For BrowseItem objects, use original field for full resolution
        if (currentImage.value.original) {
            return currentImage.value.original;
        }
        
        // Fallback to src for compatibility
        if (currentImage.value.src) {
            return currentImage.value.src;
        }
        
        // If the file is downloaded and we have a local path, use the atlas storage path
        if (currentImage.value.downloaded && currentImage.value.path && !currentImage.value.not_found) {
            return `/atlas/${currentImage.value.path}`;
        }
        
        // If the file is not downloaded but we have a URL, use the original URL
        if (!currentImage.value.downloaded && currentImage.value.url) {
            return currentImage.value.url;
        }
        
        // Fallback to image_url attribute or constructed atlas path
        return currentImage.value.image_url || `/atlas/${currentImage.value.path}`;
    });

    // Utility function to detect if the current file is a video
    const isCurrentVideo = computed(() => {
        if (!currentImage.value) return false;
        const url = imageUrl.value.toLowerCase();
        return (
            url.includes('.mp4') ||
            url.includes('.webm') ||
            url.includes('.mov') ||
            url.includes('.avi') ||
            url.includes('.mkv') ||
            url.includes('.wmv') ||
            url.includes('.m4v')
        );
    });

    // Utility function to detect if the current file is an image
    const isCurrentImage = computed(() => {
        if (!currentImage.value) return false;
        const url = imageUrl.value.toLowerCase();
        return (
            url.includes('.jpg') ||
            url.includes('.jpeg') ||
            url.includes('.png') ||
            url.includes('.gif') ||
            url.includes('.webp') ||
            url.includes('.svg') ||
            url.includes('.bmp') ||
            !isCurrentVideo.value
        );
    });

    return {
        // State
        isImageViewerOpen,
        imageViewerZoom,
        imageViewerPosition,
        currentImage,
        allImages,
        currentIndex,
        isDragging,
        dragStart,
        
        // Computed
        imageUrl,
        isCurrentVideo,
        isCurrentImage,
        canGoNext,
        canGoPrevious,
        
        // Methods
        openImageViewer,
        closeImageViewer,
        zoomIn,
        zoomOut,
        resetZoom,
        startDrag,
        onDrag,
        stopDrag,
        goToNext,
        goToPrevious,
        removeCurrentAndGoNext
    };
}
