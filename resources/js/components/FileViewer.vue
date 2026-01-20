<script setup lang="ts">
import { computed, ref, reactive, nextTick, onUnmounted, watch, toRef, toRefs } from 'vue';
import { X, Loader2, Menu, Pause, Play, Maximize2, Minimize2 } from 'lucide-vue-next';
import FileViewerSheet from './FileViewerSheet.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { Masonry } from '@wyxos/vibe';
import { useOverlayVideoControls } from '@/composables/useOverlayVideoControls';
import { useFileViewerNavigation } from '@/composables/useFileViewerNavigation';
import { useFileViewerSizing } from '@/composables/useFileViewerSizing';
import { useFileViewerOverlayState } from '@/composables/useFileViewerOverlayState';
import { useFileViewerOpen } from '@/composables/useFileViewerOpen';
import { useFileViewerPaging } from '@/composables/useFileViewerPaging';
import { useFileViewerData } from '@/composables/useFileViewerData';
import { useFileViewerSheetSizing } from '@/composables/useFileViewerSheetSizing';
import { useFileViewerOverlayStyles } from '@/composables/useFileViewerOverlayStyles';

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

const items = toRef(props, 'items');
const hasMore = computed(() => !props.masonry?.hasReachedEnd);
const isLoading = computed(() => props.masonry?.isLoading ?? false);

// Overlay state
const overlayVideoRef = ref<HTMLVideoElement | null>(null);

const overlayState = reactive({
    rect: null as { top: number; left: number; width: number; height: number } | null,
    image: null as { src: string; srcset?: string; sizes?: string; alt?: string } | null,
    mediaType: 'image' as 'image' | 'video',
    videoSrc: null as string | null,
    borderRadius: null as string | null,
    key: 0,
    isAnimating: false,
    imageSize: null as { width: number; height: number } | null,
    isFilled: false,
    fillComplete: false,
    isClosing: false,
    scale: 1,
    centerPosition: null as { top: number; left: number } | null,
    isLoading: false,
    fullSizeImage: null as string | null,
    originalDimensions: null as { width: number; height: number } | null,
});

const navigationState = reactive({
    currentItemIndex: null as number | null,
    imageScale: 1,
    isNavigating: false,
    imageTranslateY: 0,
    direction: null as 'up' | 'down' | null,
    currentTarget: null as number | null,
});

const sheetState = reactive({
    isOpen: false,
});

const containerState = reactive({
    isLoadingMore: false,
    overflow: null as string | null,
    overscroll: null as string | null,
});

const {
    rect: overlayRect,
    image: overlayImage,
    mediaType: overlayMediaType,
    videoSrc: overlayVideoSrc,
    key: overlayKey,
    isAnimating: overlayIsAnimating,
    imageSize: overlayImageSize,
    isFilled: overlayIsFilled,
    fillComplete: overlayFillComplete,
    isClosing: overlayIsClosing,
    scale: overlayScale,
    centerPosition: imageCenterPosition,
    isLoading: overlayIsLoading,
    fullSizeImage: overlayFullSizeImage,
    originalDimensions: originalImageDimensions,
} = toRefs(overlayState);

const {
    currentItemIndex,
    imageScale,
    isNavigating,
    imageTranslateY,
} = toRefs(navigationState);

const { isOpen: isSheetOpen } = toRefs(sheetState);
const { isLoadingMore, overflow: containerOverflow, overscroll: containerOverscroll } = toRefs(containerState);

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
    overlay: overlayState,
    sheet: sheetState,
});

const { closeOverlay } = useFileViewerOverlayState({
    containerRef: computed(() => props.containerRef),
    container: containerState,
    overlay: overlayState,
    navigation: navigationState,
    sheet: sheetState,
    emitClose: () => emit('close'),
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

const { fileData, isLoadingFileData, handleItemSeen } = useFileViewerData({
    items,
    navigation: navigationState,
    overlay: overlayState,
    sheet: sheetState,
});

useFileViewerSheetSizing({
    sheet: sheetState,
    overlay: overlayState,
    containerRef: computed(() => props.containerRef),
    getAvailableWidth,
    calculateBestFitSize,
    getCenteredPosition,
});

const { openFromClick } = useFileViewerOpen({
    containerRef: computed(() => props.containerRef),
    masonryContainerRef: computed(() => props.masonryContainerRef),
    items,
    container: containerState,
    overlay: overlayState,
    navigation: navigationState,
    getAvailableWidth,
    calculateBestFitSize,
    getCenteredPosition,
    preloadImage,
    handleItemSeen,
    closeOverlay,
    emitOpen: () => emit('open'),
});

const { navigateToNext, navigateToPrevious, navigateToIndex } = useFileViewerPaging({
    containerRef: computed(() => props.containerRef),
    items,
    overlay: overlayState,
    navigation: navigationState,
    getAvailableWidth,
    calculateBestFitSize,
    getCenteredPosition,
    preloadImage,
    handleItemSeen,
    ensureMoreItems,
});

const { handleTouchStart, handleTouchEnd } = useFileViewerNavigation({
    overlay: overlayState,
    onClose: closeOverlay,
    onNext: navigateToNext,
    onPrevious: navigateToPrevious,
    onFullscreenChange: handleFullscreenChange,
});

const {
    overlayContainerClass,
    overlayContainerStyle,
    overlayContentClass,
    overlayMediaWrapperStyle,
    overlayMediaTransitionClass,
    overlayMediaStyle,
} = useFileViewerOverlayStyles({
    overlay: overlayState,
    navigation: navigationState,
});
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

watch(() => [currentItemIndex.value, overlayFillComplete.value], ([newIndex, isFilled]) => {
    if (newIndex === null || !isFilled) return;
    if (items.value.length - 1 - newIndex <= 1) {
        void ensureMoreItems();
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
    <div v-if="overlayRect && overlayImage" :class="overlayContainerClass" :style="overlayContainerStyle"
        @touchstart.passive="handleTouchStart" @touchend.passive="handleTouchEnd">
        <!-- Main content area -->
        <div :class="overlayContentClass" :style="overlayMediaWrapperStyle">
            <!-- Image container -->
            <div class="relative flex-1 min-h-0 overflow-hidden" :style="overlayMediaWrapperStyle">
                <!-- Preview image (shown immediately, behind spinner) -->
                <img v-if="overlayIsLoading" :key="overlayKey + '-preview'" :src="overlayImage.src"
                    :srcset="overlayImage.srcset" :sizes="overlayImage.sizes" :alt="overlayImage.alt" :class="[
                        'absolute select-none pointer-events-none object-cover',
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" draggable="false" />

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
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" draggable="false" @mousedown="handleOverlayImageMouseDown"
                    @auxclick="handleOverlayImageAuxClick" />

                <video v-else-if="!overlayIsLoading && overlayMediaType === 'video'" :key="overlayKey" :poster="overlayVideoPoster" ref="overlayVideoRef"
                    :class="[
                        'absolute',
                        overlayIsFilled && overlayFillComplete && !overlayIsClosing ? 'pointer-events-auto' : 'pointer-events-none',
                        overlayIsFilled ? 'object-contain' : 'object-cover',
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" playsinline disablepictureinpicture preload="metadata"
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
            :file-data="fileData ?? null"
            :is-loading="isLoadingFileData"
            @close="isSheetOpen = false"
        />
    </div>
</template>
