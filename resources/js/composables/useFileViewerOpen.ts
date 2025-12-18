import { nextTick, type Ref } from 'vue';
import { preloadImage, findMasonryItemByImageSrc, calculateBestFitSize, getAvailableWidth } from './useFileViewerUtils';

const BORDER_WIDTH = 4;

export function useFileViewerOpen(
    overlay: {
        overlayRect: Ref<{ top: number; left: number; width: number; height: number } | null>;
        overlayImage: Ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>;
        overlayBorderRadius: Ref<string | null>;
        overlayKey: Ref<number>;
        overlayIsFilled: Ref<boolean>;
        overlayFillComplete: Ref<boolean>;
        overlayIsClosing: Ref<boolean>;
        overlayScale: Ref<number>;
        overlayIsLoading: Ref<boolean>;
        overlayFullSizeImage: Ref<string | null>;
        originalImageDimensions: Ref<{ width: number; height: number } | null>;
        imageScale: Ref<number>;
        overlayIsAnimating: Ref<boolean>;
    },
    imageSize: {
        overlayImageSize: Ref<{ width: number; height: number } | null>;
        imageCenterPosition: Ref<{ top: number; left: number } | null>;
    },
    items: Ref<Array<{ id: number; key?: string; src?: string; thumbnail?: string; originalUrl?: string; width?: number; height?: number }>>,
    currentItemIndex: Ref<number | null>,
    containerRef: () => HTMLElement | null,
    masonryContainerRef: () => HTMLElement | null,
    isSheetOpen: Ref<boolean>,
    handleItemSeen: (fileId: number) => Promise<void>,
    closeOverlay: () => void
) {
    async function openFromClick(e: MouseEvent): Promise<void> {
        const container = masonryContainerRef();
        const tabContent = containerRef();
        if (!container || !tabContent) return;

        const target = e.target as HTMLElement | null;
        if (!target) return;

        // Find the nearest masonry item element
        const itemEl = target.closest('.masonry-item') as HTMLElement | null;

        if (!itemEl || !container.contains(itemEl)) {
            // Clicked outside an item → clear overlay
            closeOverlay();
            return;
        }

        // Compute position relative to the tab content container (not masonry container)
        const itemBox = itemEl.getBoundingClientRect();
        const tabContentBox = tabContent.getBoundingClientRect();

        // Account for border-4 (4px border on all sides = 8px total width/height added)
        // Adjust position and size so the image inside aligns perfectly with the clicked image
        const overlayBorderWidth = 4;
        const top = itemBox.top - tabContentBox.top - overlayBorderWidth;
        const left = itemBox.left - tabContentBox.left - overlayBorderWidth;
        const width = itemBox.width + (overlayBorderWidth * 2);
        const height = itemBox.height + (overlayBorderWidth * 2);

        // Try to find an <img> inside the clicked masonry item
        const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;
        if (!imgEl) {
            // No image found -> clear overlay (requirement is to show the same image)
            closeOverlay();
            return;
        }

        // Copy image attributes
        const src = imgEl.currentSrc || imgEl.getAttribute('src') || '';
        const srcset = imgEl.getAttribute('srcset') || undefined;
        const sizes = imgEl.getAttribute('sizes') || undefined;
        const alt = imgEl.getAttribute('alt') || '';

        // Compute the border radius from the masonry item so the overlay matches
        const computed = window.getComputedStyle(itemEl);
        const radius = computed.borderRadius || '';

        // Find the masonry item data to get the full-size URL
        const masonryItem = findMasonryItemByImageSrc(src, itemEl, items.value);
        const fullSizeUrl = masonryItem?.originalUrl || src; // Fallback to current src if no originalUrl

        // Find and set the current item index
        const itemIndex = masonryItem ? items.value.findIndex(item => item.id === masonryItem.id) : -1;
        currentItemIndex.value = itemIndex >= 0 ? itemIndex : null;

        // Increment key to force image element recreation (prevents showing previous image)
        overlay.overlayKey.value++;
        overlay.imageScale.value = 1; // Reset image scale

        // Store original image size to maintain it when container expands
        imageSize.overlayImageSize.value = { width, height };
        overlay.overlayIsFilled.value = false;
        overlay.overlayFillComplete.value = false;
        overlay.overlayIsClosing.value = false;
        overlay.overlayScale.value = 1; // Reset scale to normal
        overlay.overlayIsLoading.value = true; // Show spinner
        overlay.overlayFullSizeImage.value = null; // Reset full-size image

        // Set initial position at clicked item location and show overlay with spinner
        overlay.overlayRect.value = { top, left, width, height };
        overlay.overlayImage.value = { src, srcset, sizes, alt };
        overlay.overlayBorderRadius.value = radius || null;
        overlay.overlayIsAnimating.value = false;

        // Wait for DOM update
        await nextTick();

        // Preload the full-size image and get its dimensions
        try {
            const imageDimensions = await preloadImage(fullSizeUrl);
            // Store original dimensions for best-fit calculation
            overlay.originalImageDimensions.value = imageDimensions;
            // Once loaded, update to use full-size image
            overlay.overlayFullSizeImage.value = fullSizeUrl;
            overlay.overlayIsLoading.value = false;

            // Increment seen count when file is fully loaded
            if (masonryItem?.id) {
                await handleItemSeen(masonryItem.id);
            }

            // Wait for image to be displayed, then proceed with animations
            await nextTick();
            await new Promise(resolve => setTimeout(resolve, 50)); // Small delay to ensure image is rendered
        } catch (error) {
            // If preload fails, use the original image and continue
            console.warn('Failed to preload full-size image, using original:', error);
            overlay.overlayFullSizeImage.value = src;
            overlay.overlayIsLoading.value = false;
            // Use masonry item dimensions as fallback
            if (masonryItem) {
                overlay.originalImageDimensions.value = {
                    width: masonryItem.width || 0,
                    height: masonryItem.height || 0,
                };
            } else {
                overlay.originalImageDimensions.value = { width, height };
            }
            await nextTick();
        }

        // Precalculate flexbox center position for initial (small) container
        // Image container is inside border, so position relative to container (not border)
        const borderWidth = 4; // border-4 = 4px
        const initialContentWidth = width - (borderWidth * 2);
        const initialContentHeight = height - (borderWidth * 2);
        // Initially image size equals container size (overlayImageSize is set to { width, height })
        const initialImageLeft = Math.round((initialContentWidth - imageSize.overlayImageSize.value!.width) / 2);
        const initialImageTop = Math.round((initialContentHeight - imageSize.overlayImageSize.value!.height) / 2);

        imageSize.imageCenterPosition.value = {
            top: initialImageTop,
            left: initialImageLeft,
        };

        // Wait for image to be displayed, then proceed with animations
        await nextTick();
        await new Promise(resolve => setTimeout(resolve, 50)); // Small delay to ensure image is rendered

        // Use requestAnimationFrame to ensure initial render is complete before starting animations
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const tabContent = containerRef();
                if (!tabContent || !overlay.overlayRect.value || !imageSize.overlayImageSize.value) return;

                const tabContentBox = tabContent.getBoundingClientRect();
                const containerWidth = tabContentBox.width;
                const containerHeight = tabContentBox.height;

                // Calculate center position (centered both horizontally and vertically)
                // Round to avoid subpixel rendering issues
                const centerLeft = Math.round((containerWidth - width) / 2);
                const centerTop = Math.round((containerHeight - height) / 2);

                // Precalculate center position for centered (small) container
                // Image container is inside border, so position relative to container (not border)
                const borderWidth = 4; // border-4 = 4px
                const contentWidth = width - (borderWidth * 2);
                const contentHeight = height - (borderWidth * 2);
                const centeredImageLeft = Math.round((contentWidth - imageSize.overlayImageSize.value.width) / 2);
                const centeredImageTop = Math.round((contentHeight - imageSize.overlayImageSize.value.height) / 2);

                imageSize.imageCenterPosition.value = {
                    top: centeredImageTop,
                    left: centeredImageLeft,
                };

                // Mark as animating and update to center position
                overlay.overlayIsAnimating.value = true;
                overlay.overlayRect.value = {
                    top: centerTop,
                    left: centerLeft,
                    width,
                    height,
                };

                // After center animation completes (500ms), animate to fill container
                setTimeout(() => {
                    if (!tabContent || !overlay.overlayRect.value || !imageSize.overlayImageSize.value || !overlay.originalImageDimensions.value) return;

                    // Calculate best-fit size for the image within the expanded container
                    const borderWidth = 4; // border-4 = 4px
                    const availableWidth = getAvailableWidth(containerWidth, borderWidth, overlay.overlayIsFilled.value, overlay.overlayFillComplete.value, overlay.overlayIsClosing.value, isSheetOpen.value);
                    const availableHeight = containerHeight - (borderWidth * 2);
                    const bestFitSize = calculateBestFitSize(
                        overlay.originalImageDimensions.value.width,
                        overlay.originalImageDimensions.value.height,
                        availableWidth,
                        availableHeight
                    );

                    // Update image size to best-fit dimensions
                    imageSize.overlayImageSize.value = bestFitSize;

                    // Precalculate center position for full container with best-fit image
                    // Image container is inside border, so position relative to container (not border)
                    const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
                    const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

                    imageSize.imageCenterPosition.value = {
                        top: fullImageTop,
                        left: fullImageLeft,
                    };

                    // Mark as filled and update to fill entire tab content container
                    overlay.overlayIsFilled.value = true;
                    overlay.overlayRect.value = {
                        top: 0,
                        left: 0,
                        width: containerWidth,
                        height: containerHeight,
                    };

                    // After fill animation completes (another 500ms), show close button
                    setTimeout(() => {
                        overlay.overlayFillComplete.value = true;
                    }, 500); // Match the transition duration
                }, 500); // Match the transition duration
            });
        });
    }

    return {
        openFromClick,
    };
}




