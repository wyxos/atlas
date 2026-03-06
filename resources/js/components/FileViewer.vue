<script setup lang="ts">
import { computed, nextTick, onUnmounted, reactive, ref, toRef } from 'vue';
import { PanelRightOpen } from 'lucide-vue-next';
import FileViewerSheet from './FileViewerSheet.vue';
import FileViewerMediaStage from './FileViewerMediaStage.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import type { Masonry } from '@wyxos/vibe';
import { useOverlayVideoControls } from '@/composables/useOverlayVideoControls';
import { useOverlayAudioControls } from '@/composables/useOverlayAudioControls';
import { useFileViewerNavigation } from '@/composables/useFileViewerNavigation';
import { useFileViewerSizing } from '@/composables/useFileViewerSizing';
import { useFileViewerOverlayState } from '@/composables/useFileViewerOverlayState';
import { useFileViewerOpen } from '@/composables/useFileViewerOpen';
import { useFileViewerPaging } from '@/composables/useFileViewerPaging';
import { useFileViewerData } from '@/composables/useFileViewerData';
import { useFileViewerSheetSizing } from '@/composables/useFileViewerSheetSizing';
import { useFileViewerOverlayStyles } from '@/composables/useFileViewerOverlayStyles';
import { useFileViewerPreload } from '@/composables/useFileViewerPreload';
import { useFileViewerSheetState } from '@/composables/useFileViewerSheetState';
import { useFileViewerReactionFlow } from '@/composables/useFileViewerReactionFlow';

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
    reaction: [fileId: number, type: ReactionType];
}>();

const items = toRef(props, 'items');
const hasMore = computed(() => !props.masonry?.hasReachedEnd);
const isLoading = computed(() => props.masonry?.isLoading ?? false);
const overlayVideoRef = ref<HTMLVideoElement | null>(null);
const overlayAudioRef = ref<HTMLAudioElement | null>(null);

const overlayState = reactive({
    rect: null as { top: number; left: number; width: number; height: number } | null,
    image: null as { src: string; srcset?: string; sizes?: string; alt?: string } | null,
    mediaType: 'image' as 'image' | 'video' | 'audio' | 'file',
    videoSrc: null as string | null,
    audioSrc: null as string | null,
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

const containerState = reactive({
    isLoadingMore: false,
    overflow: null as string | null,
    overscroll: null as string | null,
});

const sheet = useFileViewerSheetState({
    overlay: overlayState,
});
const sheetState = sheet.sheetState;
const currentItemIndex = toRef(navigationState, 'currentItemIndex');

const video = useOverlayVideoControls({
    overlayVideoRef,
    overlayMediaType: toRef(overlayState, 'mediaType'),
    overlayFillComplete: toRef(overlayState, 'fillComplete'),
    overlayIsClosing: toRef(overlayState, 'isClosing'),
    overlayVideoSrc: toRef(overlayState, 'videoSrc'),
    overlayImageSrc: computed(() => overlayState.image?.src ?? null),
});

const audio = useOverlayAudioControls({
    overlayAudioRef,
    overlayMediaType: toRef(overlayState, 'mediaType'),
    overlayFillComplete: toRef(overlayState, 'fillComplete'),
    overlayIsClosing: toRef(overlayState, 'isClosing'),
    overlayAudioSrc: toRef(overlayState, 'audioSrc'),
});

const sizing = useFileViewerSizing({
    overlay: overlayState,
    sheet: sheetState,
});

const overlayLifecycle = useFileViewerOverlayState({
    containerRef: computed(() => props.containerRef),
    container: containerState,
    overlay: overlayState,
    navigation: navigationState,
    emitClose: () => emit('close'),
});

async function ensureMoreItems(): Promise<boolean> {
    if (!hasMore.value || containerState.isLoadingMore || isLoading.value) {
        return false;
    }

    containerState.isLoadingMore = true;

    try {
        await props.masonry?.loadNextPage?.();
        await nextTick();
    } finally {
        containerState.isLoadingMore = false;
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

const viewerData = useFileViewerData({
    items,
    navigation: navigationState,
    overlay: overlayState,
    sheet: sheetState,
});

useFileViewerSheetSizing({
    sheet: sheetState,
    overlay: overlayState,
    containerRef: computed(() => props.containerRef),
    getAvailableWidth: sizing.getAvailableWidth,
    calculateBestFitSize: sizing.calculateBestFitSize,
    getCenteredPosition: sizing.getCenteredPosition,
});

const opener = useFileViewerOpen({
    containerRef: computed(() => props.containerRef),
    masonryContainerRef: computed(() => props.masonryContainerRef),
    items,
    container: containerState,
    overlay: overlayState,
    navigation: navigationState,
    getAvailableWidth: sizing.getAvailableWidth,
    calculateBestFitSize: sizing.calculateBestFitSize,
    getCenteredPosition: sizing.getCenteredPosition,
    preloadImage,
    handleItemSeen: viewerData.handleItemSeen,
    closeOverlay: overlayLifecycle.closeOverlay,
    emitOpen: () => emit('open'),
});

const paging = useFileViewerPaging({
    containerRef: computed(() => props.containerRef),
    items,
    overlay: overlayState,
    navigation: navigationState,
    getAvailableWidth: sizing.getAvailableWidth,
    calculateBestFitSize: sizing.calculateBestFitSize,
    getCenteredPosition: sizing.getCenteredPosition,
    preloadImage,
    handleItemSeen: viewerData.handleItemSeen,
    ensureMoreItems,
});

const reactionFlow = useFileViewerReactionFlow({
    items,
    navigation: navigationState,
    overlay: overlayState,
    ensureMoreItems,
    closeOverlay: overlayLifecycle.closeOverlay,
    navigateToIndex: paging.navigateToIndex,
    emitReaction: (fileId, type) => emit('reaction', fileId, type),
});

const gestures = useFileViewerNavigation({
    overlay: overlayState,
    onClose: overlayLifecycle.closeOverlay,
    onNext: paging.navigateToNext,
    onPrevious: paging.navigateToPrevious,
    onFullscreenChange: video.handleFullscreenChange,
});

const overlayUi = useFileViewerOverlayStyles({
    overlay: overlayState,
    navigation: navigationState,
});

const preload = useFileViewerPreload({
    items,
    currentItemIndex,
    fillComplete: toRef(overlayState, 'fillComplete'),
    preloadCount: 2,
});

const currentItem = reactionFlow.currentItem;
const fileData = viewerData.fileData;
const isLoadingFileData = viewerData.isLoadingFileData;
const overlayContainerClass = overlayUi.overlayContainerClass;
const overlayContainerStyle = overlayUi.overlayContainerStyle;
const overlayContentClass = overlayUi.overlayContentClass;
const overlayMediaWrapperStyle = overlayUi.overlayMediaWrapperStyle;
const overlayMediaTransitionClass = overlayUi.overlayMediaTransitionClass;
const overlayMediaStyle = overlayUi.overlayMediaStyle;

const videoStage = computed(() => ({
    setRef: setOverlayVideoRef,
    poster: video.overlayVideoPoster.value,
    isPlaying: video.isVideoPlaying.value,
    isFullscreen: video.isVideoFullscreen.value,
    currentTime: video.videoCurrentTime.value,
    duration: video.videoDuration.value,
    volume: video.videoVolume.value,
    progressPercent: video.videoProgressPercent.value,
    volumePercent: video.videoVolumePercent.value,
    onLoadedMetadata: handleOverlayVideoLoadedMetadata,
    onTimeUpdate: video.handleVideoTimeUpdate,
    onPlay: video.handleVideoPlay,
    onPause: video.handleVideoPause,
    onEnded: video.handleVideoEnded,
    onVolumeChange: video.handleVideoVolumeChange,
    onTogglePlayback: video.toggleVideoPlayback,
    onSeek: video.handleVideoSeek,
    onSeekStart: video.handleVideoSeekStart,
    onSeekEnd: video.handleVideoSeekEnd,
    onVolumeInput: video.handleVideoVolumeInput,
    onToggleFullscreen: video.toggleVideoFullscreen,
}));

const audioStage = computed(() => ({
    setRef: setOverlayAudioRef,
    isPlaying: audio.isAudioPlaying.value,
    currentTime: audio.audioCurrentTime.value,
    duration: audio.audioDuration.value,
    volume: audio.audioVolume.value,
    progressPercent: audio.audioProgressPercent.value,
    volumePercent: audio.audioVolumePercent.value,
    onLoadedMetadata: audio.handleAudioLoadedMetadata,
    onTimeUpdate: audio.handleAudioTimeUpdate,
    onPlay: audio.handleAudioPlay,
    onPause: audio.handleAudioPause,
    onEnded: audio.handleAudioEnded,
    onVolumeChange: audio.handleAudioVolumeChange,
    onTogglePlayback: audio.toggleAudioPlayback,
    onSeek: audio.handleAudioSeek,
    onSeekStart: audio.handleAudioSeekStart,
    onSeekEnd: audio.handleAudioSeekEnd,
    onVolumeInput: audio.handleAudioVolumeInput,
}));

function setOverlayVideoRef(element: HTMLVideoElement | null): void {
    overlayVideoRef.value = element;
}

function setOverlayAudioRef(element: HTMLAudioElement | null): void {
    overlayAudioRef.value = element;
}

function recomputeOverlayMediaFit(width: number, height: number): void {
    if (width <= 0 || height <= 0 || !overlayState.rect) {
        return;
    }

    const borderWidth = 4;
    const tabContent = props.containerRef;

    if (!tabContent) {
        return;
    }

    overlayState.originalDimensions = { width, height };

    const tabContentBox = tabContent.getBoundingClientRect();
    const frameWidth = overlayState.isFilled ? tabContentBox.width : overlayState.rect.width;
    const frameHeight = overlayState.isFilled ? tabContentBox.height : overlayState.rect.height;
    const availableWidth = overlayState.isFilled
        ? sizing.getAvailableWidth(frameWidth, borderWidth)
        : Math.max(frameWidth - (borderWidth * 2), 0);
    const availableHeight = Math.max(frameHeight - (borderWidth * 2), 0);

    if (availableWidth <= 0 || availableHeight <= 0) {
        return;
    }

    const bestFitSize = sizing.calculateBestFitSize(width, height, availableWidth, availableHeight);

    overlayState.imageSize = bestFitSize;
    overlayState.centerPosition = sizing.getCenteredPosition(
        availableWidth,
        availableHeight,
        bestFitSize.width,
        bestFitSize.height,
    );
}

function handleOverlayVideoLoadedMetadata(): void {
    video.handleVideoLoadedMetadata();

    const currentVideo = overlayVideoRef.value;
    if (!currentVideo) {
        return;
    }

    recomputeOverlayMediaFit(currentVideo.videoWidth, currentVideo.videoHeight);
}

function openCurrentItemOriginal(): void {
    const item = currentItem.value;
    if (!item) {
        return;
    }

    const url = item.original || item.preview;
    if (!url) {
        return;
    }

    try {
        window.open(url, '_blank', 'noopener,noreferrer');
    } catch {
        // Ignore popup errors.
    }
}

onUnmounted(() => {
    const tabContent = props.containerRef;

    if (tabContent && containerState.overflow !== null) {
        tabContent.style.overflow = containerState.overflow;

        if (containerState.overscroll !== null) {
            tabContent.style.overscrollBehavior = containerState.overscroll;
        } else {
            tabContent.style.removeProperty('overscroll-behavior');
        }

        containerState.overflow = null;
        containerState.overscroll = null;
    }

    preload.clearPreloadCache();
});

defineExpose({
    openFromClick: opener.openFromClick,
    close: overlayLifecycle.closeOverlay,
    closeOverlay: overlayLifecycle.closeOverlay,
    navigateForward: paging.navigateToNext,
    navigateBackward: paging.navigateToPrevious,
    currentItemIndex,
    overlayState,
    navigationState,
    sheetState,
    items,
});
</script>

<template>
    <div v-if="overlayState.rect && overlayState.image" :class="overlayContainerClass" :style="overlayContainerStyle"
        @touchstart.passive="gestures.handleTouchStart" @touchend.passive="gestures.handleTouchEnd">
        <div :class="overlayContentClass" :style="overlayMediaWrapperStyle">
            <FileViewerMediaStage :overlay="overlayState" :current-item="currentItem"
                :current-index="navigationState.currentItemIndex ?? 0" :items-length="items.length"
                :is-loading-more="containerState.isLoadingMore" :overlay-media-transition-class="overlayMediaTransitionClass"
                :overlay-media-style="overlayMediaStyle" :video="videoStage" :audio="audioStage"
                @close="overlayLifecycle.closeOverlay" @reaction="reactionFlow.reactAndAdvance"
                @open-original="openCurrentItemOriginal" />
        </div>

        <div v-if="overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing && !sheetState.isOpen"
            class="flex flex-col items-center justify-center gap-4 p-4 bg-prussian-blue-800 border-l-2 border-twilight-indigo-500 shrink-0 transition-all duration-300 ease-in-out w-16">
            <button @click="sheet.setSheetOpen(true)"
                class="p-3 rounded-lg bg-smart-blue-500 hover:bg-smart-blue-600 text-white transition-colors"
                aria-label="Open sheet">
                <PanelRightOpen :size="20" />
            </button>
        </div>

        <FileViewerSheet v-if="overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing"
            :is-open="sheetState.isOpen" :file-id="currentItem?.id ?? null" :file-data="fileData ?? null"
            :is-loading="isLoadingFileData" @close="sheet.setSheetOpen(false)" />
    </div>
</template>
