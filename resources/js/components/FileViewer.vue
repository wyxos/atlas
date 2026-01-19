<script setup lang="ts">
import { computed, ref, nextTick, onUnmounted, watch } from 'vue';
import { X, Loader2, Menu, Pause, Play, Maximize2, Minimize2 } from 'lucide-vue-next';
import FileViewerSheet from './FileViewerSheet.vue';
import type { FeedItem } from '@/composables/useTabs';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { Masonry } from '@wyxos/vibe';
import { useOverlayVideoControls } from '@/composables/useOverlayVideoControls';
import { useFileViewerNavigation } from '@/composables/useFileViewerNavigation';
import { useFileViewerSizing } from '@/composables/useFileViewerSizing';
import { useFileViewerOverlayState } from '@/composables/useFileViewerOverlayState';

interface Props {
    containerRef: HTMLElement | null;
    masonryContainerRef: HTMLElement | null;
    items: FeedItem[];
    masonry?: InstanceType<typeof Masonry> | null;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    close: [];
    open: [];
}>();

const items = computed(() => props.items);
const hasMore = computed(() => !props.masonry?.hasReachedEnd);
const isLoading = computed(() => props.masonry?.isLoading ?? false);

// Overlay state
const overlayRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
const overlayImage = ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>(null);
const overlayMediaType = ref<'image' | 'video'>('image');
const overlayVideoSrc = ref<string | null>(null);
const overlayVideoRef = ref<HTMLVideoElement | null>(null);
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
const imageTranslateY = ref(0); // Translate Y for slide animation
const navigationDirection = ref<'up' | 'down' | null>(null); // Track navigation direction
const currentNavigationTarget = ref<number | null>(null); // Track current navigation target to cancel stale preloads
const isSheetOpen = ref(false); // Track if the sheet is open
const fileData = ref<import('@/types/file').File | null>(null); // Store file data from API
const isLoadingFileData = ref(false); // Track if file data is loading
const isLoadingMore = ref(false);
const containerOverflow = ref<string | null>(null);
const containerOverscroll = ref<string | null>(null);

const {
    videoCurrentTime,
    videoDuration,
    isVideoPlaying,
    isVideoSeeking,
    isVideoFullscreen,
    videoVolume,
    videoProgressPercent,
    videoVolumePercent,
    overlayVideoPoster,
    handleVideoLoadedMetadata,
    handleVideoTimeUpdate,
    handleVideoPlay,
    handleVideoPause,
    handleVideoEnded,
    handleVideoVolumeChange,
    toggleVideoPlayback,
    handleVideoSeek,
    handleVideoSeekStart,
    handleVideoSeekEnd,
    handleVideoVolumeInput,
    toggleVideoFullscreen,
    handleFullscreenChange,
} = useOverlayVideoControls({
    overlayVideoRef,
    overlayMediaType,
    overlayFillComplete,
    overlayIsClosing,
    overlayVideoSrc,
    overlayImageSrc: computed(() => overlayImage.value?.src ?? null),
});

const { getAvailableWidth, calculateBestFitSize, getCenteredPosition } = useFileViewerSizing({
    overlayIsFilled,
    overlayFillComplete,
    overlayIsClosing,
    isSheetOpen,
});

const { closeOverlay } = useFileViewerOverlayState({
    containerRef: computed(() => props.containerRef),
    containerOverflow,
    containerOverscroll,
    overlayRect,
    overlayKey,
    overlayIsAnimating,
    overlayIsClosing,
    overlayIsFilled,
    overlayFillComplete,
    overlayScale,
    overlayImageSize,
    imageCenterPosition,
    overlayImage,
    overlayMediaType,
    overlayVideoSrc,
    overlayBorderRadius,
    overlayIsLoading,
    overlayFullSizeImage,
    originalImageDimensions,
    currentItemIndex,
    imageScale,
    imageTranslateY,
    navigationDirection,
    isNavigating,
    isSheetOpen,
    emitClose: () => emit('close'),
});

const { handleTouchStart, handleTouchEnd } = useFileViewerNavigation({
    overlayRect,
    overlayFillComplete,
    overlayIsClosing,
    onClose: closeOverlay,
    onNext: navigateToNext,
    onPrevious: navigateToPrevious,
    onFullscreenChange: handleFullscreenChange,
});

async function ensureMoreItems(): Promise<boolean> {
    if (!hasMore.value || isLoadingMore.value || isLoading.value) {
        return false;
    }
    isLoadingMore.value = true;
    try {
        await props.masonry?.loadNextPage?.();
        await nextTick();
    } finally {
        isLoadingMore.value = false;
    }
    return true;
}

function preloadImage(url: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

// Increment seen count when file is loaded in FileViewer
// This increments every time a file comes into view (including when navigating back to it)
async function handleItemSeen(fileId: number): Promise<void> {
    try {
        const { data } = await window.axios.post<{ seen_count: number }>(incrementSeen.url(fileId));

        // Update local item state
        const item = items.value.find((i) => i.id === fileId);
        if (item) {
            item.seen_count = data.seen_count;
        }
    } catch (error) {
        console.error('Failed to increment seen count:', error);
        // Don't throw - seen count is not critical
    }
}

function getClickedItemId(target: HTMLElement): number | null {
    const el = target.closest('[data-file-id]') as HTMLElement | null;
    if (!el) {
        return null;
    }

    const raw = el.getAttribute('data-file-id');
    if (!raw) {
        return null;
    }

    const id = Number(raw);
    return Number.isFinite(id) ? id : null;
}

// Handle ALT + Middle Click (mousedown event needed for middle button)
function handleOverlayImageMouseDown(e: MouseEvent): void {
    // Middle click without ALT - open original URL (prevent default to avoid browser scroll)
    if (!e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
        // Actual opening will be handled in auxclick
        return;
    }

}

// Handle middle click (auxclick) to open original URL
function handleOverlayImageAuxClick(e: MouseEvent): void {
    // Middle click without ALT - open original URL
    if (!e.altKey && e.button === 1 && currentItemIndex.value !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[currentItemIndex.value];
        if (currentItem) {
            const url = currentItem.original || currentItem.preview;
            if (url) {
                try {
                    window.open(url, '_blank', 'noopener,noreferrer');
                } catch {
                    // ignore
                }
            }
        }
    }
}


async function openFromClick(e: MouseEvent): Promise<void> {
    const container = props.masonryContainerRef;
    const tabContent = props.containerRef;
    if (!container || !tabContent) return;

    if (containerOverflow.value === null) {
        containerOverflow.value = tabContent.style.overflow || '';
        containerOverscroll.value = tabContent.style.overscrollBehavior || '';
        tabContent.style.overflow = 'hidden';
        tabContent.style.overscrollBehavior = 'contain';
    }

    const target = e.target as HTMLElement | null;
    if (!target) return;

    // Vibe 2.x renders each masonry card as an <article data-testid="item-card">.
    const itemEl = target.closest('[data-testid="item-card"]') as HTMLElement | null;

    if (!itemEl || !container.contains(itemEl)) {
        // Clicked outside an item → clear overlay
        closeOverlay();
        return;
    }

    const clickedItemId = getClickedItemId(target);
    if (clickedItemId === null) {
        // The click did not originate from an item overlay we control.
        closeOverlay();
        return;
    }

    const masonryItem = items.value.find((i) => i.id === clickedItemId) ?? null;
    if (!masonryItem) {
        closeOverlay();
        return;
    }

    const mediaType: 'image' | 'video' = masonryItem.type === 'video' ? 'video' : 'image';
    overlayMediaType.value = mediaType;
    overlayVideoSrc.value = null;

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

    // Prefer using the rendered <img> so the overlay matches the clicked preview exactly.
    // For video cards there may be no <img>, so fall back to FeedItem preview URLs.
    const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;

    const src = (imgEl?.currentSrc
        || imgEl?.getAttribute('src')
        || masonryItem.preview
        || masonryItem.original) as string;

    const srcset = imgEl?.getAttribute('srcset') || undefined;
    const sizes = imgEl?.getAttribute('sizes') || undefined;
    const alt = imgEl?.getAttribute('alt') || String(masonryItem.id);

    // Compute the border radius from the masonry item so the overlay matches
    const computed = window.getComputedStyle(itemEl);
    const radius = computed.borderRadius || '';

    const fullSizeUrl = masonryItem.original || src;
    currentItemIndex.value = items.value.findIndex((it) => it.id === masonryItem.id);

    // Increment key to force image element recreation (prevents showing previous image)
    overlayKey.value++;
    imageScale.value = 1; // Reset image scale

    // Store original image size to maintain it when container expands
    overlayImageSize.value = { width, height };
    overlayIsFilled.value = false;
    overlayFillComplete.value = false;
    overlayIsClosing.value = false;
    overlayScale.value = 1; // Reset scale to normal
    overlayIsLoading.value = mediaType === 'image'; // Spinner only for image preload
    overlayFullSizeImage.value = null; // Reset full-size image

    // Set initial position at clicked item location and show overlay with spinner
    overlayRect.value = { top, left, width, height };
    overlayImage.value = { src, srcset, sizes, alt };
    overlayBorderRadius.value = radius || null;
    overlayIsAnimating.value = false;

    // Emit open event when FileViewer opens
    emit('open');

    // Wait for DOM update
    await nextTick();

    try {
        if (mediaType === 'video') {
            originalImageDimensions.value = {
                width: masonryItem.width,
                height: masonryItem.height,
            };
            overlayVideoSrc.value = fullSizeUrl;
            overlayIsLoading.value = false;

            // Count as seen when opening the viewer for the file.
            await handleItemSeen(masonryItem.id);
            await nextTick();
        } else {
            const imageDimensions = await preloadImage(fullSizeUrl);
            // Store original dimensions for best-fit calculation
            originalImageDimensions.value = imageDimensions;
            // Once loaded, update to use full-size image
            overlayFullSizeImage.value = fullSizeUrl;
            overlayIsLoading.value = false;

            // Increment seen count when file is fully loaded
            await handleItemSeen(masonryItem.id);

            // Wait for image to be displayed, then proceed with animations
            await nextTick();
            await new Promise(resolve => setTimeout(resolve, 50)); // Small delay to ensure image is rendered
        }
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
    imageCenterPosition.value = getCenteredPosition(
        initialContentWidth,
        initialContentHeight,
        overlayImageSize.value.width,
        overlayImageSize.value.height
    );

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
            imageCenterPosition.value = getCenteredPosition(
                contentWidth,
                contentHeight,
                overlayImageSize.value.width,
                overlayImageSize.value.height
            );

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
                const availableWidth = getAvailableWidth(containerWidth, borderWidth);
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
                imageCenterPosition.value = getCenteredPosition(
                    availableWidth,
                    availableHeight,
                    bestFitSize.width,
                    bestFitSize.height
                );

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
    if (currentItemIndex.value >= items.value.length - 1) {
        await ensureMoreItems();
        if (currentItemIndex.value < items.value.length - 1) {
            const nextIndex = currentItemIndex.value + 1;
            currentItemIndex.value = nextIndex;
            navigateToIndex(nextIndex, 'down');
        }
        return;
    } // Already at last item

    const nextIndex = currentItemIndex.value + 1;
    // Update index immediately to keep viewer navigation responsive
    currentItemIndex.value = nextIndex;
    // Don't await - allow rapid navigation
    navigateToIndex(nextIndex, 'down');
}

// Navigate to previous image
async function navigateToPrevious(): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value || currentItemIndex.value === null) return;
    if (currentItemIndex.value <= 0) return; // Already at first item

    const prevIndex = currentItemIndex.value - 1;
    // Update index immediately to keep viewer navigation responsive
    currentItemIndex.value = prevIndex;
    // Don't await - allow rapid navigation
    navigateToIndex(prevIndex, 'up');
}

// Navigate to a specific index
async function navigateToIndex(index: number, direction?: 'up' | 'down'): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value) return;
    if (index < 0 || index >= items.value.length) return;

    const tabContent = props.containerRef;
    if (!tabContent) return;

    // Determine direction if not provided
    if (!direction && currentItemIndex.value !== null) {
        direction = index > currentItemIndex.value ? 'down' : 'up';
    }
    navigationDirection.value = direction || 'down';

    // Set current navigation target - this will cancel any stale preloads
    currentNavigationTarget.value = index;
    isNavigating.value = true;

    // Note: currentItemIndex is already updated in navigateToNext/navigateToPrevious

    // Step 1: Slide current image out
    const slideOutDistance = tabContent.getBoundingClientRect().height;
    imageTranslateY.value = direction === 'down' ? -slideOutDistance : slideOutDistance;
    overlayIsAnimating.value = true;

    // Wait for slide out animation to complete
    await new Promise(resolve => setTimeout(resolve, 500));

    // Step 2: Get next item and show spinner
    const nextItem = items.value[index];
    if (!nextItem) {
        isNavigating.value = false;
        return;
    }

    const nextIsVideo = nextItem.type === 'video';
    const nextImageSrc = (nextItem.preview || nextItem.original) as string;
    const nextFullSizeUrl = nextItem.original || nextImageSrc;

    // Update overlay image to show spinner with preview
    overlayImage.value = {
        src: nextImageSrc,
        srcset: undefined,
        sizes: undefined,
        alt: nextItem.id.toString(),
    };
    overlayMediaType.value = nextIsVideo ? 'video' : 'image';
    overlayVideoSrc.value = nextIsVideo ? nextFullSizeUrl : null;
    overlayIsLoading.value = !nextIsVideo;
    overlayFullSizeImage.value = null;
    overlayKey.value++; // Force image element recreation

    // Calculate preview image size and position (use current container size)
    const tabContentBox = tabContent.getBoundingClientRect();
    const containerWidth = tabContentBox.width;
    const containerHeight = tabContentBox.height;
    const borderWidth = 4;
    const availableWidth = getAvailableWidth(containerWidth, borderWidth);
    const availableHeight = containerHeight - (borderWidth * 2);

    // For preview, use container size (object-cover will handle aspect ratio)
    overlayImageSize.value = {
        width: availableWidth,
        height: availableHeight,
    };

    imageCenterPosition.value = getCenteredPosition(
        availableWidth,
        availableHeight,
        availableWidth,
        availableHeight
    );

    // Start new image off-screen in the opposite direction
    const slideInDistance = tabContent.getBoundingClientRect().height;
    imageTranslateY.value = direction === 'down' ? slideInDistance : -slideInDistance;
    imageScale.value = 1; // Keep scale at 1 for slide animation
    await nextTick(); // Ensure DOM updates before continuing

    // Step 3: Preload the full-size image (or swap to video)
    // Check if navigation target has changed (user spammed navigation)
    const preloadTarget = index;
    try {
        if (nextIsVideo) {
            if (currentNavigationTarget.value !== preloadTarget) {
                isNavigating.value = false;
                overlayIsAnimating.value = false;
                return;
            }

            originalImageDimensions.value = {
                width: nextItem.width,
                height: nextItem.height,
            };
            overlayVideoSrc.value = nextFullSizeUrl;

            // Increment seen count when navigating to a new file
            if (nextItem?.id) {
                await handleItemSeen(nextItem.id);
            }

            // Calculate best-fit size for the video within the expanded container
            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;
            const borderWidth = 4;
            const availableWidth = getAvailableWidth(containerWidth, borderWidth);
            const availableHeight = containerHeight - (borderWidth * 2);

            const bestFitSize = calculateBestFitSize(
                nextItem.width,
                nextItem.height,
                availableWidth,
                availableHeight
            );

            overlayImageSize.value = bestFitSize;

            imageCenterPosition.value = getCenteredPosition(
                availableWidth,
                availableHeight,
                bestFitSize.width,
                bestFitSize.height
            );

            // Switch out of loading state so the <video> renders
            overlayIsLoading.value = false;

            await nextTick();

            // Ensure element is rendered before animating
            await new Promise(resolve => {
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => resolve(void 0));
                });
            });

            if (currentNavigationTarget.value !== preloadTarget) {
                isNavigating.value = false;
                overlayIsAnimating.value = false;
                return;
            }

            imageTranslateY.value = 0;
            await new Promise(resolve => setTimeout(resolve, 500));
            if (currentNavigationTarget.value !== preloadTarget) {
                isNavigating.value = false;
                overlayIsAnimating.value = false;
                return;
            }

            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        const imageDimensions = await preloadImage(nextFullSizeUrl);

        // Cancel if navigation target changed during preload
        if (currentNavigationTarget.value !== preloadTarget) {
            isNavigating.value = false;
            overlayIsAnimating.value = false;
            return;
        }

        originalImageDimensions.value = imageDimensions;
        overlayFullSizeImage.value = nextFullSizeUrl;

        // Increment seen count when navigating to a new file
        if (nextItem?.id) {
            await handleItemSeen(nextItem.id);
        }

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
        const availableWidth = getAvailableWidth(containerWidth, borderWidth);
        const availableHeight = containerHeight - (borderWidth * 2);

        const bestFitSize = calculateBestFitSize(
            imageDimensions.width,
            imageDimensions.height,
            availableWidth,
            availableHeight
        );

        overlayImageSize.value = bestFitSize;

        imageCenterPosition.value = getCenteredPosition(
            availableWidth,
            availableHeight,
            bestFitSize.width,
            bestFitSize.height
        );

        // Ensure imageTranslateY is set for slide-in animation
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
        imageTranslateY.value = 0;

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
        imageTranslateY.value = 0;
        await nextTick();
    }

    isNavigating.value = false;
    overlayIsAnimating.value = false;
}

// Fetch file data when sheet opens or current item changes
async function fetchFileData(fileId: number): Promise<void> {
    if (!fileId) return;

    isLoadingFileData.value = true;
    try {
        const { data } = await window.axios.get(getFile.url(fileId));
        fileData.value = data.file;
    } catch (error) {
        console.error('Failed to fetch file data:', error);
        fileData.value = null;
    } finally {
        isLoadingFileData.value = false;
    }
}

// Watch current item index to fetch file data when it changes
watch(() => currentItemIndex.value, async (newIndex) => {
    if (newIndex !== null && isSheetOpen.value && overlayFillComplete.value) {
        const currentItem = items.value[newIndex];
        if (currentItem?.id) {
            await fetchFileData(currentItem.id);
        }
    }
});

watch(() => [currentItemIndex.value, overlayFillComplete.value], ([newIndex, isFilled]) => {
    if (newIndex === null || !isFilled) return;
    if (items.value.length - 1 - newIndex <= 1) {
        void ensureMoreItems();
    }
});

// Watch sheet open to fetch file data
watch(() => isSheetOpen.value, async (isOpen) => {
    if (isOpen && currentItemIndex.value !== null && overlayFillComplete.value) {
        const currentItem = items.value[currentItemIndex.value];
        if (currentItem?.id) {
            await fetchFileData(currentItem.id);
        }
    } else if (!isOpen) {
        fileData.value = null; // Clear data when sheet closes
    }
});

// Watch sheet open/close to recalculate image size immediately
watch(() => isSheetOpen.value, () => {
    if (overlayRect.value && overlayImageSize.value && originalImageDimensions.value && props.containerRef && overlayFillComplete.value) {
        const tabContent = props.containerRef;
        const tabContentBox = tabContent.getBoundingClientRect();
        const containerWidth = tabContentBox.width;
        const containerHeight = tabContentBox.height;
        const borderWidth = 4;
        const availableWidth = getAvailableWidth(containerWidth, borderWidth);
        const availableHeight = containerHeight - (borderWidth * 2);

        const bestFitSize = calculateBestFitSize(
            originalImageDimensions.value.width,
            originalImageDimensions.value.height,
            availableWidth,
            availableHeight
        );

        overlayImageSize.value = bestFitSize;

        imageCenterPosition.value = getCenteredPosition(
            availableWidth,
            availableHeight,
            bestFitSize.width,
            bestFitSize.height
        );
    }
});

// Cleanup on unmount
onUnmounted(() => {
    const tabContent = props.containerRef;
    if (tabContent && containerOverflow.value !== null) {
        tabContent.style.overflow = containerOverflow.value;
        if (containerOverscroll.value !== null) {
            tabContent.style.overscrollBehavior = containerOverscroll.value;
        } else {
            tabContent.style.removeProperty('overscroll-behavior');
        }
        containerOverflow.value = null;
        containerOverscroll.value = null;
    }
});

// Expose methods for parent component
defineExpose({
    openFromClick,
    close: closeOverlay,
    navigateForward: navigateToNext,
    navigateBackward: navigateToPrevious,
    currentItemIndex,
    items,
});
</script>

<template>
    <!-- Click overlay -->
    <div v-if="overlayRect && overlayImage" :class="[
        'absolute z-50 border-4 border-smart-blue-500 bg-prussian-blue-900 overflow-hidden',
        overlayIsFilled ? 'flex' : 'flex flex-col',
        overlayIsFilled && !overlayIsClosing ? '' : 'pointer-events-none',
        overlayIsAnimating || overlayIsClosing ? 'transition-all duration-500 ease-in-out' : ''
    ]" :style="{
        top: overlayRect.top + 'px',
        left: overlayRect.left + 'px',
        width: overlayRect.width + 'px',
        height: overlayRect.height + 'px',
        borderRadius: overlayIsFilled ? undefined : (overlayBorderRadius || undefined),
        transform: `scale(${overlayScale})`,
        transformOrigin: 'center center',
    }" @touchstart.passive="handleTouchStart" @touchend.passive="handleTouchEnd">
        <!-- Main content area -->
        <div :class="[
            'relative overflow-hidden transition-all duration-500 ease-in-out',
            overlayIsFilled ? 'flex-1 min-h-0 min-w-0 flex flex-col' : 'flex-1 min-h-0'
        ]" :style="{
            height: overlayIsFilled ? undefined : '100%',
        }">
            <!-- Image container -->
            <div class="relative flex-1 min-h-0 overflow-hidden" :style="{
                height: overlayIsFilled ? undefined : '100%',
            }">
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
                            transform: `scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : overlayImageSize ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : {
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        }),
                        transformOrigin: 'center center',
                    }" draggable="false" />

                <!-- Spinner while loading full-size image -->
                <div v-if="overlayIsLoading" class="absolute inset-0 flex items-center justify-center z-10">
                    <Loader2 :size="32" class="animate-spin text-smart-blue-500" />
                </div>

                <!-- Full-size media (image or video) -->
                <img v-if="!overlayIsLoading && overlayMediaType === 'image'" :key="overlayKey" :src="overlayFullSizeImage || overlayImage.src" :alt="overlayImage.alt"
                    :class="[
                        'absolute select-none',
                        overlayIsFilled && overlayFillComplete && !overlayIsClosing ? 'cursor-pointer pointer-events-auto' : 'pointer-events-none',
                        overlayIsFilled ? '' : 'object-cover',
                        (overlayIsAnimating || overlayIsClosing || overlayIsFilled || isNavigating) && imageCenterPosition ? 'transition-all duration-500 ease-in-out' : ''
                    ]" :style="{
                        ...(overlayImageSize && imageCenterPosition ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                            top: imageCenterPosition.top + 'px',
                            left: imageCenterPosition.left + 'px',
                            transform: `scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : overlayImageSize ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : {
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        }),
                        transformOrigin: 'center center',
                    }" draggable="false" @mousedown="handleOverlayImageMouseDown"
                    @auxclick="handleOverlayImageAuxClick" />

                <video v-else-if="!overlayIsLoading && overlayMediaType === 'video'" :key="overlayKey" :poster="overlayVideoPoster" ref="overlayVideoRef"
                    :class="[
                        'absolute',
                        overlayIsFilled && overlayFillComplete && !overlayIsClosing ? 'pointer-events-auto' : 'pointer-events-none',
                        overlayIsFilled ? 'object-contain' : 'object-cover',
                        (overlayIsAnimating || overlayIsClosing || overlayIsFilled || isNavigating) && imageCenterPosition ? 'transition-all duration-500 ease-in-out' : ''
                    ]" :style="{
                        ...(overlayImageSize && imageCenterPosition ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                            top: imageCenterPosition.top + 'px',
                            left: imageCenterPosition.left + 'px',
                            transform: `scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : overlayImageSize ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        } : {
                            top: '50%',
                            left: '50%',
                            transform: `translate(-50%, -50%) scale(${imageScale}) translateY(${imageTranslateY}px)`,
                        }),
                        transformOrigin: 'center center',
                    }" playsinline disablepictureinpicture preload="metadata"
                    @loadedmetadata="handleVideoLoadedMetadata" @timeupdate="handleVideoTimeUpdate"
                    @play="handleVideoPlay" @pause="handleVideoPause" @ended="handleVideoEnded"
                    @volumechange="handleVideoVolumeChange" @mousedown="handleOverlayImageMouseDown"
                    @auxclick="handleOverlayImageAuxClick">
                    <source v-if="overlayVideoSrc" :src="overlayVideoSrc" type="video/mp4" />
                </video>

                <div v-if="!overlayIsLoading && overlayMediaType === 'video' && overlayFillComplete && !overlayIsClosing"
                    class="absolute bottom-4 left-0 right-0 z-50 pointer-events-auto px-4">
                    <div class="flex w-full items-center gap-3 rounded border border-twilight-indigo-500/80 bg-prussian-blue-800/80 px-3 py-2 backdrop-blur">
                        <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                            :aria-label="isVideoPlaying ? 'Pause video' : 'Play video'" @click.stop="toggleVideoPlayback">
                            <Pause v-if="isVideoPlaying" :size="16" />
                            <Play v-else :size="16" />
                        </button>
                        <div class="flex-1 min-w-0">
                            <input class="file-viewer-video-slider w-full" type="range" min="0"
                                :max="videoDuration || 0" step="0.1" :value="videoCurrentTime"
                                :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${videoProgressPercent}%, var(--color-twilight-indigo-600) ${videoProgressPercent}%)`
                                }" @input="handleVideoSeek" @pointerdown="handleVideoSeekStart"
                                @pointerup="handleVideoSeekEnd" />
                        </div>
                        <div class="w-24 shrink-0">
                            <input class="file-viewer-video-slider w-full" type="range" min="0" max="1" step="0.01"
                                :value="videoVolume" :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${videoVolumePercent}%, var(--color-twilight-indigo-600) ${videoVolumePercent}%)`
                                }" @input="handleVideoVolumeInput" />
                        </div>
                        <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                            :aria-label="isVideoFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'"
                            @click.stop="toggleVideoFullscreen">
                            <Minimize2 v-if="isVideoFullscreen" :size="16" />
                            <Maximize2 v-else :size="16" />
                        </button>
                    </div>
                </div>

            <!-- Close button -->
            <button v-if="overlayFillComplete && !overlayIsClosing" @click="closeOverlay"
                class="absolute top-4 right-4 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto"
                aria-label="Close overlay" data-test="close-overlay-button">
                <X :size="20" />
            </button>

            <div
                v-if="overlayFillComplete && !overlayIsClosing && isLoadingMore"
                class="absolute top-4 left-1/2 z-50 -translate-x-1/2 rounded-full border border-smart-blue-500/80 bg-prussian-blue-800/90 px-4 py-2 text-xs font-medium text-smart-blue-100 backdrop-blur"
            >
                <span class="inline-flex items-center gap-2">
                    <Loader2 :size="14" class="animate-spin" />
                    Loading more items…
                </span>
            </div>

                <!-- Reactions disabled -->
            </div>

            <!-- Carousel disabled (vertical navigation only for now). -->
        </div>

        <!-- Vertical Taskbar (only shown when filled) -->
        <div v-if="overlayIsFilled && overlayFillComplete && !overlayIsClosing && !isSheetOpen"
            class="flex flex-col items-center justify-center gap-4 p-4 bg-prussian-blue-800 border-l-2 border-twilight-indigo-500 shrink-0 transition-all duration-300 ease-in-out w-16">
            <!-- CTA Button to open sheet -->
            <button @click="isSheetOpen = true"
                class="p-3 rounded-lg bg-smart-blue-500 hover:bg-smart-blue-600 text-white transition-colors"
                aria-label="Open sheet">
                <Menu :size="20" />
            </button>
        </div>

        <FileViewerSheet
            v-if="overlayIsFilled && overlayFillComplete && !overlayIsClosing"
            :is-open="isSheetOpen"
            :file-id="currentItemIndex !== null && items[currentItemIndex] ? items[currentItemIndex].id : null"
            :file-data="fileData"
            :is-loading="isLoadingFileData"
            @close="isSheetOpen = false"
        />
    </div>
</template>
