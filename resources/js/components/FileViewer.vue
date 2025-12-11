<script setup lang="ts">
import { ref, nextTick, onMounted, onUnmounted, watch, computed } from 'vue';
import { X, Loader2 } from 'lucide-vue-next';
import ImageCarousel from './ImageCarousel.vue';
import FileReactions from './FileReactions.vue';
import type { MasonryItem } from '../composables/useBrowseTabs';
import { useReactionHandler } from '../composables/useReactionHandler';

interface Props {
    containerRef: HTMLElement | null;
    masonryContainerRef: HTMLElement | null;
    items: MasonryItem[];
    hasMore?: boolean;
    isLoading?: boolean;
    onLoadMore?: () => Promise<void>;
    onReaction?: (fileId: number, type: 'love' | 'like' | 'dislike' | 'funny') => void;
    removeFromMasonry?: (item: MasonryItem) => void;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
}>();

// Make items reactive for carousel removal
const items = ref<MasonryItem[]>(props.items);

// Watch props.items and sync to reactive items (only when props change externally)
// Use a flag to prevent syncing when we're removing items internally
const isRemovingItem = ref(false);
watch(() => props.items, (newItems) => {
    if (!isRemovingItem.value) {
        items.value = newItems;
    }
}, { deep: true });

// Overlay state
const overlayRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
const overlayImage = ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>(null);
const overlayBorderRadius = ref<string | null>(null);
const overlayKey = ref(0); // Key to force image element recreation on each click
const overlayIsAnimating = ref(false); // Track if overlay is animating to center
const overlayImageSize = ref<{ width: number; height: number } | null>(null); // Store original image size
const overlayIsFilled = ref(false); // Track if overlay has expanded to fill container
const overlayFillComplete = ref(false); // Track if fill animation has completed (for close button visibility)
const overlayIsClosing = ref(false); // Track if overlay is closing (shrinking animation)
const overlayScale = ref(1); // Scale factor for closing animation (1 = normal, 0 = fully shrunk)
const imageCenterPosition = ref<{ top: number; left: number } | null>(null); // Exact center position when filled
const overlayIsLoading = ref(false); // Track if full-size image is loading
const overlayFullSizeImage = ref<string | null>(null); // Full-size image URL once loaded
const originalImageDimensions = ref<{ width: number; height: number } | null>(null); // Original full-size image dimensions
const currentItemIndex = ref<number | null>(null); // Track current item index in items array
const imageScale = ref(1); // Scale factor for individual image (for scale-from-zero animation)
const isNavigating = ref(false); // Track if we're navigating between images
const isBottomPanelOpen = ref(false); // Track if bottom panel is open
const imageTranslateX = ref(0); // Translate X for slide animation
const navigationDirection = ref<'left' | 'right' | null>(null); // Track navigation direction
const currentNavigationTarget = ref<number | null>(null); // Track current navigation target to cancel stale preloads

function preloadImage(url: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

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

function findMasonryItemByImageSrc(imageSrc: string, itemElement: HTMLElement): MasonryItem | null {
    // Try to find item by checking data attributes on the masonry item element
    const itemId = itemElement.getAttribute('data-item-id');
    if (itemId) {
        const item = items.value.find(i => i.id === Number(itemId));
        if (item) return item;
    }

    // Fallback: try to match by URL (compare src with item.src or thumbnail)
    // Extract base URL without query params for comparison
    const baseSrc = imageSrc.split('?')[0].split('#')[0];
    return items.value.find(item => {
        const itemSrc = (item.src || item.thumbnail || '').split('?')[0].split('#')[0];
        return baseSrc === itemSrc || baseSrc.includes(itemSrc) || itemSrc.includes(baseSrc);
    }) || null;
}

function closeOverlay(): void {
    if (!overlayRect.value) return;

    // Start closing animation - shrink towards center using CSS scale
    overlayIsClosing.value = true;
    overlayIsAnimating.value = true;

    // Calculate center position of the container
    const tabContent = props.containerRef;
    if (tabContent && overlayRect.value) {
        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;

        // Calculate center position - move container to center
        const centerLeft = Math.round((containerWidth - overlayRect.value.width) / 2);
        const centerTop = Math.round((containerHeight - overlayRect.value.height) / 2);

        // Move container to center
        overlayRect.value = {
            ...overlayRect.value,
            top: centerTop,
            left: centerLeft,
        };

        // Scale down to 0 - CSS will shrink everything inside proportionally
        overlayScale.value = 0;

        // After animation completes, clear everything
        setTimeout(() => {
            overlayKey.value++;
            overlayIsAnimating.value = false;
            overlayIsClosing.value = false;
            overlayIsFilled.value = false;
            overlayFillComplete.value = false;
            overlayScale.value = 1; // Reset scale
            overlayImageSize.value = null;
            imageCenterPosition.value = null;
            overlayRect.value = null;
            overlayImage.value = null;
            overlayBorderRadius.value = null;
            overlayIsLoading.value = false;
            overlayFullSizeImage.value = null;
            originalImageDimensions.value = null;
            currentItemIndex.value = null;
            imageScale.value = 1;
            imageTranslateX.value = 0;
            navigationDirection.value = null;
            isNavigating.value = false;
            isBottomPanelOpen.value = false;
            emit('close');
        }, 500); // Match transition duration
    } else {
        // Fallback: immediate close if container not available
        overlayKey.value++;
        overlayIsAnimating.value = false;
        overlayIsClosing.value = false;
        overlayIsFilled.value = false;
        overlayFillComplete.value = false;
        overlayScale.value = 1;
        overlayImageSize.value = null;
        imageCenterPosition.value = null;
        overlayRect.value = null;
        overlayImage.value = null;
        overlayBorderRadius.value = null;
        overlayIsLoading.value = false;
        overlayFullSizeImage.value = null;
        isBottomPanelOpen.value = false;
        currentItemIndex.value = null;
        imageScale.value = 1;
        imageTranslateX.value = 0;
        navigationDirection.value = null;
        isNavigating.value = false;
        isBottomPanelOpen.value = false;
        emit('close');
    }
}

// Reaction handler - we'll handle masonry removal separately to ensure correct item reference
const { handleReaction: handleReactionBase } = useReactionHandler({
    items,
    onReaction: props.onReaction,
    // Don't pass removeFromMasonry here - we'll call it directly with the correct item reference
});

// Handle reaction in FileViewer - removes from carousel and auto-navigates
async function handleReaction(type: 'love' | 'like' | 'dislike' | 'funny'): Promise<void> {
    if (currentItemIndex.value === null) return;

    const currentItem = items.value[currentItemIndex.value];
    if (!currentItem) return;

    const fileId = currentItem.id;
    const itemIndex = currentItemIndex.value;

    // Determine next item to navigate to before removing
    let nextIndex: number | null = null;
    let nextDirection: 'left' | 'right' | null = null;

    if (items.value.length > 1) {
        // If we're not at the last item, navigate to next
        if (itemIndex < items.value.length - 1) {
            nextIndex = itemIndex; // After removal, this will be the next item
            nextDirection = 'right';
        } else if (itemIndex > 0) {
            // If we're at the end, go to previous
            nextIndex = itemIndex - 1;
            nextDirection = 'left';
        }
    }

    // IMPORTANT: Remove from masonry FIRST (before removing from carousel)
    // This ensures masonry can find and properly remove the item
    // Pass the item directly to removeFromMasonry to ensure correct reference
    if (props.removeFromMasonry) {
        props.removeFromMasonry(currentItem);
    }

    // Queue the reaction (this will also emit to parent)
    await handleReactionBase(fileId, type);

    // Remove from carousel (items array) AFTER masonry removal
    // Note: removeFromMasonry removes from BrowseTabContent's items, which should sync to FileViewer's props.items
    // via the watch. However, we need to remove from FileViewer's items directly for immediate carousel update.
    // The watch is blocked by isRemovingItem flag to prevent double removal.
    isRemovingItem.value = true;
    // Check if item still exists before removing (it might have been removed by watch sync)
    const itemStillExists = items.value.findIndex((i) => i.id === fileId) !== -1;
    if (itemStillExists) {
        items.value.splice(itemIndex, 1);
    }
    await nextTick();
    isRemovingItem.value = false;

    // Update currentItemIndex
    if (items.value.length === 0) {
        // No items left, close overlay
        closeOverlay();
        return;
    }

    // Auto-navigate to next if available
    if (nextIndex !== null && nextDirection !== null) {
        currentItemIndex.value = nextIndex;
        await navigateToIndex(nextIndex, nextDirection);
    } else {
        // Adjust index if we removed the last item
        if (itemIndex >= items.value.length) {
            currentItemIndex.value = items.value.length - 1;
            if (currentItemIndex.value >= 0) {
                await navigateToIndex(currentItemIndex.value, 'left');
            }
        }
    }
}

// Handle carousel item click
function handleCarouselItemClick(item: MasonryItem): void {
    const itemIndex = items.value.findIndex(i => i.id === item.id);
    if (itemIndex >= 0 && currentItemIndex.value !== null) {
        // Determine direction based on index comparison BEFORE updating
        const direction = itemIndex > currentItemIndex.value ? 'right' : 'left';

        // Update currentItemIndex immediately so carousel stays in sync
        currentItemIndex.value = itemIndex;

        navigateToIndex(itemIndex, direction);
    }
}

function toggleBottomPanel(): void {
    if (!overlayFillComplete.value || overlayIsClosing.value) return;
    isBottomPanelOpen.value = !isBottomPanelOpen.value;

    // Recalculate image size and position when panel opens/closes
    if (overlayRect.value && overlayImageSize.value && originalImageDimensions.value && props.containerRef) {
        const tabContent = props.containerRef;
        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const borderWidth = 4;

        // Reduce available height by 200px when panel is open
        const panelHeight = isBottomPanelOpen.value ? 200 : 0;
        const availableWidth = containerWidth - (borderWidth * 2);
        const availableHeight = containerHeight - (borderWidth * 2) - panelHeight;

        // Recalculate best-fit size for the image
        const bestFitSize = calculateBestFitSize(
            originalImageDimensions.value.width,
            originalImageDimensions.value.height,
            availableWidth,
            availableHeight
        );

        // Update image size
        overlayImageSize.value = bestFitSize;

        // Recalculate center position
        const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
        const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

        imageCenterPosition.value = {
            top: fullImageTop,
            left: fullImageLeft,
        };
    }
}

// Handle ALT + mouse button combinations on overlay image
function handleOverlayImageClick(e: MouseEvent): void {
    if (e.altKey && currentItemIndex.value !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[currentItemIndex.value];
        if (!currentItem) return;

        let reactionType: 'love' | 'like' | 'dislike' | 'funny' | null = null;

        // ALT + Left Click = Like
        if (e.button === 0 || (e.type === 'click' && e.button === 0)) {
            reactionType = 'like';
        }
        // ALT + Right Click = Dislike (handled via contextmenu event)
        else if (e.button === 2 || e.type === 'contextmenu') {
            reactionType = 'dislike';
        }

        if (reactionType) {
            handleReaction(reactionType);
        }
        return;
    }

    // Normal click behavior - toggle bottom panel (only for left click without ALT)
    if (!e.altKey && (e.button === 0 || (e.type === 'click' && e.button === 0))) {
        toggleBottomPanel();
    }
}

// Handle ALT + Middle Click (mousedown event needed for middle button)
function handleOverlayImageMouseDown(e: MouseEvent): void {
    if (e.altKey && e.button === 1 && currentItemIndex.value !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[currentItemIndex.value];
        if (currentItem) {
            handleReaction('love');
        }
    }
}

async function openFromClick(e: MouseEvent): Promise<void> {
    const container = props.masonryContainerRef;
    const tabContent = props.containerRef;
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
    const masonryItem = findMasonryItemByImageSrc(src, itemEl);
    const fullSizeUrl = masonryItem?.originalUrl || src; // Fallback to current src if no originalUrl

    // Find and set the current item index
    const itemIndex = masonryItem ? items.value.findIndex(item => item.id === masonryItem.id) : -1;
    currentItemIndex.value = itemIndex >= 0 ? itemIndex : null;

    // Increment key to force image element recreation (prevents showing previous image)
    overlayKey.value++;
    imageScale.value = 1; // Reset image scale

    // Store original image size to maintain it when container expands
    overlayImageSize.value = { width, height };
    overlayIsFilled.value = false;
    overlayFillComplete.value = false;
    overlayIsClosing.value = false;
    overlayScale.value = 1; // Reset scale to normal
    overlayIsLoading.value = true; // Show spinner
    overlayFullSizeImage.value = null; // Reset full-size image

    // Set initial position at clicked item location and show overlay with spinner
    overlayRect.value = { top, left, width, height };
    overlayImage.value = { src, srcset, sizes, alt };
    overlayBorderRadius.value = radius || null;
    overlayIsAnimating.value = false;

    // Wait for DOM update
    await nextTick();

    // Preload the full-size image and get its dimensions
    try {
        const imageDimensions = await preloadImage(fullSizeUrl);
        // Store original dimensions for best-fit calculation
        originalImageDimensions.value = imageDimensions;
        // Once loaded, update to use full-size image
        overlayFullSizeImage.value = fullSizeUrl;
        overlayIsLoading.value = false;

        // Wait for image to be displayed, then proceed with animations
        await nextTick();
        await new Promise(resolve => setTimeout(resolve, 50)); // Small delay to ensure image is rendered
    } catch (error) {
        // If preload fails, use the original image and continue
        console.warn('Failed to preload full-size image, using original:', error);
        overlayFullSizeImage.value = src;
        overlayIsLoading.value = false;
        // Use masonry item dimensions as fallback
        if (masonryItem) {
            originalImageDimensions.value = {
                width: masonryItem.width,
                height: masonryItem.height,
            };
        } else {
            originalImageDimensions.value = { width, height };
        }
        await nextTick();
    }

    // Precalculate flexbox center position for initial (small) container
    // Image container is inside border, so position relative to container (not border)
    const borderWidth = 4; // border-4 = 4px
    const initialContentWidth = width - (borderWidth * 2);
    const initialContentHeight = height - (borderWidth * 2);
    // Initially image size equals container size (overlayImageSize is set to { width, height })
    const initialImageLeft = Math.round((initialContentWidth - overlayImageSize.value.width) / 2);
    const initialImageTop = Math.round((initialContentHeight - overlayImageSize.value.height) / 2);

    imageCenterPosition.value = {
        top: initialImageTop,
        left: initialImageLeft,
    };

    // Wait for image to be displayed, then proceed with animations
    await nextTick();
    await new Promise(resolve => setTimeout(resolve, 50)); // Small delay to ensure image is rendered

    // Use requestAnimationFrame to ensure initial render is complete before starting animations
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            const tabContent = props.containerRef;
            if (!tabContent || !overlayRect.value || !overlayImageSize.value) return;

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
            const centeredImageLeft = Math.round((contentWidth - overlayImageSize.value.width) / 2);
            const centeredImageTop = Math.round((contentHeight - overlayImageSize.value.height) / 2);

            imageCenterPosition.value = {
                top: centeredImageTop,
                left: centeredImageLeft,
            };

            // Mark as animating and update to center position
            overlayIsAnimating.value = true;
            overlayRect.value = {
                top: centerTop,
                left: centerLeft,
                width,
                height,
            };

            // After center animation completes (500ms), animate to fill container
            setTimeout(() => {
                if (!tabContent || !overlayRect.value || !overlayImageSize.value || !originalImageDimensions.value) return;

                // Calculate best-fit size for the image within the expanded container
                const borderWidth = 4; // border-4 = 4px
                const availableWidth = containerWidth - (borderWidth * 2);
                const availableHeight = containerHeight - (borderWidth * 2);
                const bestFitSize = calculateBestFitSize(
                    originalImageDimensions.value.width,
                    originalImageDimensions.value.height,
                    availableWidth,
                    availableHeight
                );

                // Update image size to best-fit dimensions
                overlayImageSize.value = bestFitSize;

                // Precalculate center position for full container with best-fit image
                // Image container is inside border, so position relative to container (not border)
                const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
                const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

                imageCenterPosition.value = {
                    top: fullImageTop,
                    left: fullImageLeft,
                };

                // Mark as filled and update to fill entire tab content container
                overlayIsFilled.value = true;
                overlayRect.value = {
                    top: 0,
                    left: 0,
                    width: containerWidth,
                    height: containerHeight,
                };

                // After fill animation completes (another 500ms), show close button
                setTimeout(() => {
                    overlayFillComplete.value = true;
                }, 500); // Match the transition duration
            }, 500); // Match the transition duration
        });
    });
}

// Navigate to next image
async function navigateToNext(): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value || currentItemIndex.value === null) return;
    if (currentItemIndex.value >= items.value.length - 1) return; // Already at last item

    const nextIndex = currentItemIndex.value + 1;
    // Update index immediately - both carousel and fileviewer animate together
    currentItemIndex.value = nextIndex;
    // Don't await - allow rapid navigation
    navigateToIndex(nextIndex, 'right');
}

// Navigate to previous image
async function navigateToPrevious(): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value || currentItemIndex.value === null) return;
    if (currentItemIndex.value <= 0) return; // Already at first item

    const prevIndex = currentItemIndex.value - 1;
    // Update index immediately - both carousel and fileviewer animate together
    currentItemIndex.value = prevIndex;
    // Don't await - allow rapid navigation
    navigateToIndex(prevIndex, 'left');
}

// Navigate to a specific index
async function navigateToIndex(index: number, direction?: 'left' | 'right'): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value) return;
    if (index < 0 || index >= items.value.length) return;

    const tabContent = props.containerRef;
    if (!tabContent) return;

    // Determine direction if not provided
    if (!direction && currentItemIndex.value !== null) {
        direction = index > currentItemIndex.value ? 'right' : 'left';
    }
    navigationDirection.value = direction || 'right';

    // Set current navigation target - this will cancel any stale preloads
    currentNavigationTarget.value = index;
    isNavigating.value = true;

    // Note: currentItemIndex is already updated in navigateToNext/navigateToPrevious
    // before this function is called, so carousel reacts immediately

    // Step 1: Slide current image out
    const slideOutDistance = tabContent.getBoundingClientRect().width;
    imageTranslateX.value = direction === 'right' ? -slideOutDistance : slideOutDistance;
    overlayIsAnimating.value = true;

    // Wait for slide out animation to complete
    await new Promise(resolve => setTimeout(resolve, 500));

    // Step 2: Get next item and show spinner
    const nextItem = items.value[index];
    if (!nextItem) {
        isNavigating.value = false;
        return;
    }

    const nextImageSrc = nextItem.src || nextItem.thumbnail || '';
    const nextFullSizeUrl = nextItem.originalUrl || nextImageSrc;

    // Update overlay image to show spinner with preview
    overlayImage.value = {
        src: nextImageSrc,
        srcset: undefined,
        sizes: undefined,
        alt: nextItem.id.toString(),
    };
    overlayIsLoading.value = true;
    overlayFullSizeImage.value = null;
    overlayKey.value++; // Force image element recreation

    // Calculate preview image size and position (use current container size)
    const tabContentBox = tabContent.getBoundingClientRect();
    const containerWidth = tabContentBox.width;
    const containerHeight = tabContentBox.height;
    const borderWidth = 4;
    const availableWidth = containerWidth - (borderWidth * 2);
    const availableHeight = containerHeight - (borderWidth * 2);

    // For preview, use container size (object-cover will handle aspect ratio)
    overlayImageSize.value = {
        width: availableWidth,
        height: availableHeight,
    };

    const previewImageLeft = Math.floor((availableWidth - availableWidth) / 2) + borderWidth;
    const previewImageTop = Math.floor((availableHeight - availableHeight) / 2) + borderWidth;

    imageCenterPosition.value = {
        top: previewImageTop,
        left: previewImageLeft,
    };

    // Start new image off-screen in the opposite direction
    const slideInDistance = tabContent.getBoundingClientRect().width;
    imageTranslateX.value = direction === 'right' ? slideInDistance : -slideInDistance;
    imageScale.value = 1; // Keep scale at 1 for slide animation
    await nextTick(); // Ensure DOM updates before continuing

    // Step 3: Preload the full-size image
    // Check if navigation target has changed (user spammed navigation)
    const preloadTarget = index;
    try {
        const imageDimensions = await preloadImage(nextFullSizeUrl);

        // Cancel if navigation target changed during preload
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        originalImageDimensions.value = imageDimensions;
        overlayFullSizeImage.value = nextFullSizeUrl;

        // Step 4: Calculate best-fit size BEFORE switching to full-size image
        // Check again if navigation target changed
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const borderWidth = 4;

        // Reduce available height by 200px when drawer is open
        const panelHeight = isBottomPanelOpen.value ? 200 : 0;
        const availableWidth = containerWidth - (borderWidth * 2);
        const availableHeight = containerHeight - (borderWidth * 2) - panelHeight;

        const bestFitSize = calculateBestFitSize(
            imageDimensions.width,
            imageDimensions.height,
            availableWidth,
            availableHeight
        );

        overlayImageSize.value = bestFitSize;

        const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
        const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

        imageCenterPosition.value = {
            top: fullImageTop,
            left: fullImageLeft,
        };

        // Ensure imageTranslateX is set for slide-in animation
        // (already set above, but keep scale at 1)
        imageScale.value = 1;

        // Wait for DOM to update with new image size/position
        await nextTick();

        // Check again before continuing with animation
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        // Use requestAnimationFrame to ensure the new image element is rendered at scale 0
        // before we start the scale-up animation
        await new Promise(resolve => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    resolve(void 0);
                });
            });
        });

        // Check again before switching to full-size image
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        // Now switch to full-size image (still at scale 0)
        overlayIsLoading.value = false;

        // Wait for DOM to update with full-size image element
        await nextTick();

        // Use requestAnimationFrame again to ensure full-size image is rendered at scale 0
        // and that Vue has applied the transition class
        await new Promise(resolve => {
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    // Small delay to ensure transition class is applied
                    setTimeout(() => {
                        resolve(void 0);
                    }, 10);
                });
            });
        });

        // Final check before animating
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        // Now slide image in from the side
        imageTranslateX.value = 0;

        // Wait for scale animation to complete
        await new Promise(resolve => setTimeout(resolve, 500));

        // Final check before marking navigation complete
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }
    } catch (error) {
        console.warn('Failed to preload next image:', error);
        overlayFullSizeImage.value = nextImageSrc;
        overlayIsLoading.value = false;
        if (nextItem) {
            originalImageDimensions.value = {
                width: nextItem.width,
                height: nextItem.height,
            };
        }
        imageScale.value = 1;
        imageTranslateX.value = 0;
        await nextTick();
    }

    isNavigating.value = false;
    overlayIsAnimating.value = false;
}

// Keyboard event handler for Escape key and arrow keys
function handleKeyDown(e: KeyboardEvent): void {
    if (!overlayRect.value || overlayIsClosing.value) return;

    if (e.key === 'Escape') {
        closeOverlay();
    } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        navigateToNext();
    } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        navigateToPrevious();
    }
}

// Watch overlay visibility and add/remove keyboard listener
watch(() => overlayRect.value !== null, (isVisible) => {
    if (isVisible) {
        window.addEventListener('keydown', handleKeyDown);
    } else {
        window.removeEventListener('keydown', handleKeyDown);
    }
}, { immediate: true });

// Cleanup on unmount
onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
});

// Expose methods for parent component
defineExpose({
    openFromClick,
    close: closeOverlay,
});
</script>

<template>
    <!-- Click overlay -->
    <div v-if="overlayRect && overlayImage" :class="[
        'absolute z-50 border-4 border-smart-blue-500 bg-prussian-blue-900 overflow-hidden flex flex-col',
        overlayIsFilled && !overlayIsClosing ? '' : 'pointer-events-none',
        overlayIsAnimating || overlayIsClosing ? 'transition-all duration-500 ease-in-out' : ''
    ]" :style="{
        top: overlayRect.top + 'px',
        left: overlayRect.left + 'px',
        width: overlayRect.width + 'px',
        height: overlayRect.height + 'px',
        borderRadius: overlayBorderRadius || undefined,
        transform: `scale(${overlayScale})`,
        transformOrigin: 'center center',
    }">
        <!-- Image container (flex-1 to take remaining space when panel is open) -->
        <div :class="[
            'relative overflow-hidden transition-all duration-500 ease-in-out',
            isBottomPanelOpen ? 'flex-1 min-h-0' : 'flex-1 min-h-0'
        ]">
            <!-- Preview image (shown immediately, behind spinner) -->
            <img v-if="overlayIsLoading" :key="overlayKey + '-preview'" :src="overlayImage.src"
                :srcset="overlayImage.srcset" :sizes="overlayImage.sizes" :alt="overlayImage.alt" :class="[
                    'absolute select-none pointer-events-none object-cover',
                    (overlayIsAnimating || overlayIsClosing || overlayIsFilled || isNavigating) && imageCenterPosition ? 'transition-all duration-500 ease-in-out' : ''
                ]" :style="{
                    ...(overlayImageSize && imageCenterPosition ? {
                        width: overlayImageSize.width + 'px',
                        height: overlayImageSize.height + 'px',
                        top: imageCenterPosition.top + 'px',
                        left: imageCenterPosition.left + 'px',
                    } : overlayImageSize ? {
                        width: overlayImageSize.width + 'px',
                        height: overlayImageSize.height + 'px',
                    } : {}),
                    transform: `scale(${imageScale}) translateX(${imageTranslateX}px)`,
                    transformOrigin: 'center center',
                }" draggable="false" />

            <!-- Spinner while loading full-size image -->
            <div v-if="overlayIsLoading" class="absolute inset-0 flex items-center justify-center z-10">
                <Loader2 :size="32" class="animate-spin text-smart-blue-500" />
            </div>

            <!-- Full-size image (shown after preload) -->
            <img v-else :key="overlayKey" :src="overlayFullSizeImage || overlayImage.src" :alt="overlayImage.alt"
                :class="[
                    'absolute select-none',
                    overlayIsFilled && overlayFillComplete && !overlayIsClosing ? 'cursor-pointer pointer-events-auto' : 'pointer-events-none',
                    overlayIsFilled ? '' : 'object-cover',
                    (overlayIsAnimating || overlayIsClosing || overlayIsFilled || isNavigating || isBottomPanelOpen !== null) && imageCenterPosition ? 'transition-all duration-500 ease-in-out' : ''
                ]" :style="{
                    ...(overlayImageSize && imageCenterPosition ? {
                        width: overlayImageSize.width + 'px',
                        height: overlayImageSize.height + 'px',
                        top: imageCenterPosition.top + 'px',
                        left: imageCenterPosition.left + 'px',
                    } : overlayImageSize ? {
                        width: overlayImageSize.width + 'px',
                        height: overlayImageSize.height + 'px',
                    } : {}),
                    transform: `scale(${imageScale}) translateX(${imageTranslateX}px)`,
                    transformOrigin: 'center center',
                }" draggable="false" @click="handleOverlayImageClick" @contextmenu.prevent="handleOverlayImageClick" @mousedown="handleOverlayImageMouseDown" />

            <!-- Close button -->
            <button v-if="overlayFillComplete && !overlayIsClosing" @click="closeOverlay"
                class="absolute top-4 right-4 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto"
                aria-label="Close overlay" data-test="close-overlay-button">
                <X :size="20" />
            </button>

            <!-- File Reactions (centered under image) -->
            <div v-if="overlayFillComplete && !overlayIsClosing"
                class="absolute bottom-4 left-1/2 -translate-x-1/2 z-50 pointer-events-auto">
                <FileReactions v-if="currentItemIndex !== null" :file-id="items[currentItemIndex]?.id"
                    :previewed-count="0" :viewed-count="0" :current-index="currentItemIndex ?? undefined"
                    :total-items="items.length" @reaction="handleReaction" />
            </div>
        </div>

        <!-- Image Carousel -->
        <ImageCarousel v-if="overlayFillComplete && !overlayIsClosing" :items="items"
            :current-item-index="currentItemIndex" :visible="isBottomPanelOpen" :has-more="hasMore"
            :is-loading="isLoading" :on-load-more="onLoadMore" @next="navigateToNext" @previous="navigateToPrevious"
            @item-click="handleCarouselItemClick" />
    </div>
</template>
