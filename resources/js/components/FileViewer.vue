<script setup lang="ts">
import { computed, ref, nextTick, onUnmounted, watch, type Ref } from 'vue';
import { X, Loader2, Menu, Pause, Play, Maximize2, Minimize2 } from 'lucide-vue-next';
import FileViewerSheet from './FileViewerSheet.vue';
import FileReactions from './FileReactions.vue';
import type { FeedItem } from '@/composables/useTabs';
import { createReactionCallback } from '@/utils/reactions';
import { incrementSeen, show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { ReactionType } from '@/types/reaction';
import type { Masonry } from '@wyxos/vibe';
import { useBrowseForm } from '@/composables/useBrowseForm';

interface Props {
    containerRef: HTMLElement | null;
    masonryContainerRef: HTMLElement | null;
    items: FeedItem[];
    hasMore?: boolean;
    isLoading?: boolean;
    onLoadMore?: () => Promise<void>;
    onReaction?: (fileId: number, type: ReactionType) => void;
    masonry?: Ref<InstanceType<typeof Masonry> | null>;
    tabId?: number;
}

const props = defineProps<Props>();
const { isLocal } = useBrowseForm();

const emit = defineEmits<{
    close: [];
    open: [];
}>();

// Make items reactive for carousel removal
const items = ref<FeedItem[]>(props.items);

// Watch props.items and sync to reactive items (only when props change externally)
// Use a flag to prevent syncing when we're removing items internally
const isRemovingItem = ref(false);

// Overlay state
const overlayRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
const overlayImage = ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>(null);
const overlayMediaType = ref<'image' | 'video'>('image');
const overlayVideoSrc = ref<string | null>(null);
const overlayVideoRef = ref<HTMLVideoElement | null>(null);
const videoCurrentTime = ref(0);
const videoDuration = ref(0);
const isVideoPlaying = ref(false);
const isVideoSeeking = ref(false);
const isVideoFullscreen = ref(false);
const videoVolume = ref(1);
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
const swipeStart = ref<{ x: number; y: number } | null>(null);
const lastWheelAt = ref(0);
const lastSwipeAt = ref(0);
const SWIPE_THRESHOLD = 60;
const WHEEL_THRESHOLD = 40;
const NAV_THROTTLE_MS = 400;

async function ensureMoreItems(): Promise<boolean> {
    if (!props.hasMore || !props.onLoadMore || isLoadingMore.value || props.isLoading) {
        return false;
    }
    isLoadingMore.value = true;
    try {
        await props.onLoadMore();
        await nextTick();
    } finally {
        isLoadingMore.value = false;
    }
    return true;
}

// Watch props.items and sync to reactive items (only when props change externally)
// Use a flag to prevent syncing when we're removing items internally
watch(() => props.items, (newItems) => {
    if (!isRemovingItem.value) {
        items.value = newItems;
    }
}, { deep: true });

watch(
    () => [overlayMediaType.value, overlayIsLoading.value, overlayVideoSrc.value],
    async ([mediaType, isLoading, src]) => {
        if (mediaType !== 'video' || isLoading || !src) {
            return;
        }
        // Intentionally do not autoplay when the source is set.
        // We only start playback after the overlay transition completes (overlayFillComplete).
    }
);

watch(
    () => [overlayMediaType.value, overlayFillComplete.value, overlayIsClosing.value, overlayVideoSrc.value],
    async ([mediaType, fillComplete, isClosing, src]) => {
        if (mediaType !== 'video' || !fillComplete || isClosing || !src) {
            return;
        }

        await nextTick();
        playOverlayVideo();
    }
);

watch(() => overlayMediaType.value, (mediaType) => {
    if (mediaType !== 'video') {
        videoCurrentTime.value = 0;
        videoDuration.value = 0;
        isVideoPlaying.value = false;
        isVideoSeeking.value = false;
        isVideoFullscreen.value = false;
        videoVolume.value = 1;
    }
});

function preloadImage(url: string): Promise<{ width: number; height: number }> {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

function playOverlayVideo(): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    video.muted = false;
    video.volume = 1;
    videoVolume.value = video.volume;
    void video.play().catch(() => {});
}

const videoProgressPercent = computed(() => {
    if (!videoDuration.value) {
        return 0;
    }
    return Math.min(100, (videoCurrentTime.value / videoDuration.value) * 100);
});

const videoVolumePercent = computed(() => Math.round(videoVolume.value * 100));
const overlayVideoPoster = computed(() => {
    const src = overlayImage.value?.src;
    if (!src) {
        return undefined;
    }
    if (/\.(mp4|webm)(\?|#|$)/i.test(src)) {
        return undefined;
    }
    return src;
});

function handleVideoLoadedMetadata(): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    videoDuration.value = Number.isFinite(video.duration) ? video.duration : 0;
    videoCurrentTime.value = Number.isFinite(video.currentTime) ? video.currentTime : 0;
    isVideoPlaying.value = !video.paused && !video.ended;
    videoVolume.value = video.volume;
}

function handleVideoTimeUpdate(): void {
    if (isVideoSeeking.value) {
        return;
    }
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    videoCurrentTime.value = video.currentTime;
}

function handleVideoPlay(): void {
    isVideoPlaying.value = true;
}

function handleVideoPause(): void {
    isVideoPlaying.value = false;
}

function handleVideoEnded(): void {
    isVideoPlaying.value = false;
}

function handleVideoVolumeChange(): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    videoVolume.value = video.volume;
}

function toggleVideoPlayback(): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    if (video.paused || video.ended) {
        void video.play().catch(() => {});
    } else {
        video.pause();
    }
}

function handleVideoSeek(event: Event): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    const value = Number((event.target as HTMLInputElement).value);
    if (!Number.isFinite(value)) {
        return;
    }
    video.currentTime = value;
    videoCurrentTime.value = value;
}

function handleVideoVolumeInput(event: Event): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    const value = Number((event.target as HTMLInputElement).value);
    if (!Number.isFinite(value)) {
        return;
    }
    video.volume = value;
    video.muted = value === 0;
    videoVolume.value = value;
}

function handleFullscreenChange(): void {
    isVideoFullscreen.value = document.fullscreenElement === overlayVideoRef.value;
}

function shouldIgnoreGesture(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;
    if (!el) {
        return false;
    }
    return Boolean(el.closest('button, input, textarea, select, a, .file-viewer-video-slider'));
}

function handleWheel(e: WheelEvent): void {
    if (!overlayRect.value || !overlayFillComplete.value || overlayIsClosing.value) return;
    if (shouldIgnoreGesture(e.target)) return;

    const now = Date.now();
    if (now - lastWheelAt.value < NAV_THROTTLE_MS) return;

    const deltaY = e.deltaY;
    if (Math.abs(deltaY) < WHEEL_THRESHOLD) return;

    e.preventDefault();
    lastWheelAt.value = now;

    if (deltaY > 0) {
        navigateToNext();
    } else {
        navigateToPrevious();
    }
}

function handleTouchStart(e: TouchEvent): void {
    if (!overlayRect.value || !overlayFillComplete.value || overlayIsClosing.value) return;
    if (shouldIgnoreGesture(e.target)) return;

    const touch = e.touches[0];
    if (!touch) return;
    swipeStart.value = { x: touch.clientX, y: touch.clientY };
}

function handleTouchEnd(e: TouchEvent): void {
    if (!overlayRect.value || !overlayFillComplete.value || overlayIsClosing.value) return;
    if (shouldIgnoreGesture(e.target)) return;

    const start = swipeStart.value;
    swipeStart.value = null;
    if (!start) return;

    const touch = e.changedTouches[0];
    if (!touch) return;

    const deltaX = touch.clientX - start.x;
    const deltaY = touch.clientY - start.y;
    if (Math.abs(deltaY) < SWIPE_THRESHOLD) return;
    if (Math.abs(deltaY) < Math.abs(deltaX)) return;

    const now = Date.now();
    if (now - lastSwipeAt.value < NAV_THROTTLE_MS) return;
    lastSwipeAt.value = now;

    if (deltaY < 0) {
        navigateToNext();
    } else {
        navigateToPrevious();
    }
}

function toggleVideoFullscreen(): void {
    const video = overlayVideoRef.value;
    if (!video) {
        return;
    }
    if (document.fullscreenElement) {
        void document.exitFullscreen().catch(() => {});
        return;
    }
    void video.requestFullscreen().catch(() => {});
}
function handleVideoSeekStart(): void {
    isVideoSeeking.value = true;
}

function handleVideoSeekEnd(): void {
    isVideoSeeking.value = false;
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

// Calculate available width accounting for taskbar and sheet
function getAvailableWidth(containerWidth: number, borderWidth: number): number {
    const taskbarWidth = overlayIsFilled.value && overlayFillComplete.value && !overlayIsClosing.value && !isSheetOpen.value ? 64 : 0; // w-16 = 64px
    const sheetWidth = overlayIsFilled.value && overlayFillComplete.value && !overlayIsClosing.value && isSheetOpen.value ? 320 : 0; // w-80 = 320px
    return containerWidth - (borderWidth * 2) - taskbarWidth - sheetWidth;
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

function closeOverlay(): void {
    if (!overlayRect.value) return;

    const tabContent = props.containerRef;
    if (tabContent) {
        if (containerOverflow.value !== null) {
            tabContent.style.overflow = containerOverflow.value;
        } else {
            tabContent.style.removeProperty('overflow');
        }
        if (containerOverscroll.value !== null) {
            tabContent.style.overscrollBehavior = containerOverscroll.value;
        } else {
            tabContent.style.removeProperty('overscroll-behavior');
        }
        containerOverflow.value = null;
        containerOverscroll.value = null;
    }

    // Start closing animation - shrink towards center using CSS scale
    overlayIsClosing.value = true;
    overlayIsAnimating.value = true;

    // Calculate center position of the container
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
            overlayMediaType.value = 'image';
            overlayVideoSrc.value = null;
            overlayBorderRadius.value = null;
            overlayIsLoading.value = false;
            overlayFullSizeImage.value = null;
            originalImageDimensions.value = null;
            currentItemIndex.value = null;
            imageScale.value = 1;
            imageTranslateY.value = 0;
            navigationDirection.value = null;
            isNavigating.value = false;
            isSheetOpen.value = false;
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
        overlayMediaType.value = 'image';
        overlayVideoSrc.value = null;
        overlayBorderRadius.value = null;
        overlayIsLoading.value = false;
        overlayFullSizeImage.value = null;
        currentItemIndex.value = null;
        imageScale.value = 1;
        imageTranslateY.value = 0;
        navigationDirection.value = null;
        isNavigating.value = false;
        isSheetOpen.value = false;
        emit('close');
    }
}

// Handle reaction in FileViewer - removes from carousel and auto-navigates
async function handleReaction(type: ReactionType): Promise<void> {
    if (currentItemIndex.value === null) return;

    const currentItem = items.value[currentItemIndex.value];
    if (!currentItem) return;

    const fileId = currentItem.id;
    const itemIndex = currentItemIndex.value;

    // Determine next item to navigate to before removing
    let nextIndex: number | null = null;
    let nextDirection: 'up' | 'down' | null = null;

    if (items.value.length > 1) {
        // If we're not at the last item, navigate to next
        if (itemIndex < items.value.length - 1) {
            nextIndex = itemIndex; // After removal, this will be the next item
            nextDirection = 'down';
        } else if (itemIndex > 0) {
            // If we're at the end, go to previous
            nextIndex = itemIndex - 1;
            nextDirection = 'up';
        }
    }

    // IMPORTANT: Remove from masonry FIRST (before removing from carousel)
    // This ensures masonry can find and properly remove the item
    // Only remove in online mode (not in local mode)
    // Pass the item directly to ensure correct reference
    if (!isLocal.value) {
        props.masonry?.value?.remove(currentItem);
    }

    // Call reaction callback directly
    await createReactionCallback()(fileId, type);

    // Emit to parent
    if (props.onReaction) {
        props.onReaction(fileId, type);
    }

    // Remove from carousel (items array) AFTER masonry removal
    // Note: masonry removal updates TabContent's items, which should sync to FileViewer's props.items
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
                await navigateToIndex(currentItemIndex.value, 'up');
            }
        }
    }
}

// Handle ALT + mouse button combinations on overlay image
function handleOverlayImageClick(e: MouseEvent): void {
    if (e.altKey && currentItemIndex.value !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[currentItemIndex.value];
        if (!currentItem) return;

        let reactionType: ReactionType | null = null;

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

    // Normal click behavior - no-op for now (carousel disabled).
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

    // ALT + Middle Click = Favorite
    if (e.altKey && e.button === 1 && currentItemIndex.value !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[currentItemIndex.value];
        if (currentItem) {
            handleReaction('love');
        }
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
    // Update index immediately - both carousel and fileviewer animate together
    currentItemIndex.value = nextIndex;
    // Don't await - allow rapid navigation
    navigateToIndex(nextIndex, 'down');
}

// Navigate to previous image
async function navigateToPrevious(): Promise<void> {
    if (!overlayRect.value || !overlayFillComplete.value || currentItemIndex.value === null) return;
    if (currentItemIndex.value <= 0) return; // Already at first item

    const prevIndex = currentItemIndex.value - 1;
    // Update index immediately - both carousel and fileviewer animate together
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
    // before this function is called, so carousel reacts immediately

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

    const previewImageLeft = Math.floor((availableWidth - availableWidth) / 2) + borderWidth;
    const previewImageTop = Math.floor((availableHeight - availableHeight) / 2) + borderWidth;

    imageCenterPosition.value = {
        top: previewImageTop,
        left: previewImageLeft,
    };

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

            const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
            const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

            imageCenterPosition.value = {
                top: fullImageTop,
                left: fullImageLeft,
            };

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

        const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
        const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

        imageCenterPosition.value = {
            top: fullImageTop,
            left: fullImageLeft,
        };

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

// Keyboard event handler for Escape key and arrow keys
function handleKeyDown(e: KeyboardEvent): void {
    if (!overlayRect.value || overlayIsClosing.value) return;

    if (e.key === 'Escape') {
        closeOverlay();
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowRight') {
        e.preventDefault();
        navigateToNext();
    } else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') {
        e.preventDefault();
        navigateToPrevious();
    }
}

// Track if we're handling mouse navigation to prevent browser navigation
let isHandlingMouseNavigation = false;
let overlayStatePushed = false;

// Mouse button handler for MX Master 3s navigation buttons (button 4 = back, button 5 = forward)
// Handle mousedown, mouseup, and auxclick events to prevent browser navigation
function handleMouseButton(e: MouseEvent): void {
    // Only handle when overlay is open and filled
    if (!overlayRect.value || !overlayFillComplete.value || overlayIsClosing.value) return;

    // Button 4 = browser back (navigate to previous image)
    if (e.button === 3) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        isHandlingMouseNavigation = true;
        navigateToPrevious();
        // Reset flag after a short delay
        setTimeout(() => {
            isHandlingMouseNavigation = false;
        }, 100);
    }
    // Button 5 = browser forward (navigate to next image)
    else if (e.button === 4) {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();
        isHandlingMouseNavigation = true;
        navigateToNext();
        // Reset flag after a short delay
        setTimeout(() => {
            isHandlingMouseNavigation = false;
        }, 100);
    }
}

// Handle popstate event to prevent browser navigation when we're handling mouse navigation
function handlePopState(): void {
    // If we're handling mouse navigation or overlay is open, prevent browser navigation
    if (isHandlingMouseNavigation || (overlayRect.value && overlayFillComplete.value && !overlayIsClosing.value)) {
        // Push current state back to prevent navigation
        history.pushState({ preventBack: true }, '', window.location.href);
    }
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

        const fullImageLeft = Math.round((availableWidth - bestFitSize.width) / 2);
        const fullImageTop = Math.round((availableHeight - bestFitSize.height) / 2);

        imageCenterPosition.value = {
            top: fullImageTop,
            left: fullImageLeft,
        };
    }
});

// Watch overlay visibility and add/remove keyboard and mouse button listeners
watch(() => overlayRect.value !== null && overlayFillComplete.value, (isVisible) => {
    if (isVisible) {
        // Push a state entry when overlay opens to intercept back button
        if (!overlayStatePushed) {
            history.pushState({ fileViewerOpen: true }, '', window.location.href);
            overlayStatePushed = true;
        }
        window.addEventListener('keydown', handleKeyDown);
        window.addEventListener('wheel', handleWheel, { passive: false });
        // Listen to mousedown, mouseup, and auxclick to catch mouse button 4/5 events
        // Use capture phase and handle on document for better interception
        document.addEventListener('mousedown', handleMouseButton, true);
        document.addEventListener('mouseup', handleMouseButton, true);
        document.addEventListener('auxclick', handleMouseButton, true);
        // Handle popstate to prevent browser navigation
        window.addEventListener('popstate', handlePopState);
        document.addEventListener('fullscreenchange', handleFullscreenChange);
    } else {
        overlayStatePushed = false;
        window.removeEventListener('keydown', handleKeyDown);
        window.removeEventListener('wheel', handleWheel);
        document.removeEventListener('mousedown', handleMouseButton, true);
        document.removeEventListener('mouseup', handleMouseButton, true);
        document.removeEventListener('auxclick', handleMouseButton, true);
        window.removeEventListener('popstate', handlePopState);
        document.removeEventListener('fullscreenchange', handleFullscreenChange);
    }
}, { immediate: true });

// Cleanup on unmount
onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    window.removeEventListener('wheel', handleWheel);
    document.removeEventListener('mousedown', handleMouseButton, true);
    document.removeEventListener('mouseup', handleMouseButton, true);
    document.removeEventListener('auxclick', handleMouseButton, true);
    window.removeEventListener('popstate', handlePopState);
    document.removeEventListener('fullscreenchange', handleFullscreenChange);

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
                    }" draggable="false" @click="handleOverlayImageClick"
                    @contextmenu.prevent="handleOverlayImageClick" @mousedown="handleOverlayImageMouseDown"
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
                    @volumechange="handleVideoVolumeChange" @click="handleOverlayImageClick"
                    @contextmenu.prevent="handleOverlayImageClick" @mousedown="handleOverlayImageMouseDown"
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

                <!-- File Reactions (centered under image) -->
                <div v-if="overlayFillComplete && !overlayIsClosing"
                    class="absolute bottom-20 left-1/2 -translate-x-1/2 z-50 pointer-events-auto">
                    <FileReactions v-if="currentItemIndex !== null" :file-id="items[currentItemIndex]?.id"
                        :reaction="items[currentItemIndex]?.reaction"
                        :previewed-count="(items[currentItemIndex]?.previewed_count as number) ?? 0"
                        :viewed-count="(items[currentItemIndex]?.seen_count as number) ?? 0"
                        :current-index="currentItemIndex ?? undefined" :total-items="items.length"
                        @reaction="handleReaction" />
                </div>
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
