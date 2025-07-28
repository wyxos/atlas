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
}

export function useImageZoom() {
    // Image viewer state
    const isImageViewerOpen = ref(false);
    const imageViewerZoom = ref(1);
    const imageViewerPosition = ref({ x: 0, y: 0 });
    const currentImage = ref<ImageData | null>(null);

    // Image viewer functions
    const openImageViewer = (image: ImageData) => {
        currentImage.value = image;
        isImageViewerOpen.value = true;
        imageViewerZoom.value = 1;
        imageViewerPosition.value = { x: 0, y: 0 };
    };

    const closeImageViewer = () => {
        isImageViewerOpen.value = false;
        currentImage.value = null;
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

    return {
        // State
        isImageViewerOpen,
        imageViewerZoom,
        imageViewerPosition,
        currentImage,
        isDragging,
        dragStart,
        
        // Computed
        imageUrl,
        
        // Methods
        openImageViewer,
        closeImageViewer,
        zoomIn,
        zoomOut,
        resetZoom,
        startDrag,
        onDrag,
        stopDrag
    };
}
