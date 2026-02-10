<script setup lang="ts">
import {computed, ref, reactive, nextTick, onUnmounted, watch, toRef} from 'vue';
import {X, Loader2, Menu, Pause, Play, Maximize2, Minimize2} from 'lucide-vue-next';
import FileViewerSheet from './FileViewerSheet.vue';
import FileReactions from './FileReactions.vue';
import type {FeedItem} from '@/composables/useTabs';
import type {ReactionType} from '@/types/reaction';
import type {Masonry} from '@wyxos/vibe';
import {useOverlayVideoControls} from '@/composables/useOverlayVideoControls';
import {useOverlayAudioControls} from '@/composables/useOverlayAudioControls';
import {useFileViewerNavigation} from '@/composables/useFileViewerNavigation';
import {useFileViewerSizing} from '@/composables/useFileViewerSizing';
import {useFileViewerOverlayState} from '@/composables/useFileViewerOverlayState';
import {useFileViewerOpen} from '@/composables/useFileViewerOpen';
import {useFileViewerPaging} from '@/composables/useFileViewerPaging';
import {useFileViewerData} from '@/composables/useFileViewerData';
import {useFileViewerSheetSizing} from '@/composables/useFileViewerSheetSizing';
import {useFileViewerOverlayStyles} from '@/composables/useFileViewerOverlayStyles';
import {useFileViewerPreload} from '@/composables/useFileViewerPreload';

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
const currentItem = computed(() => {
    const index = navigationState.currentItemIndex;
    if (index === null || index < 0 || index >= items.value.length) {
        return null;
    }
    return items.value[index] ?? null;
});
const currentItemId = ref<number | null>(null);

// Overlay state
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

const sheetState = reactive({
    isOpen: false,
});

const containerState = reactive({
    isLoadingMore: false,
    overflow: null as string | null,
    overscroll: null as string | null,
});

const currentItemIndex = toRef(navigationState, 'currentItemIndex');

const {
    videoCurrentTime,
    videoDuration,
    isVideoPlaying,
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
    overlayMediaType: toRef(overlayState, 'mediaType'),
    overlayFillComplete: toRef(overlayState, 'fillComplete'),
    overlayIsClosing: toRef(overlayState, 'isClosing'),
    overlayVideoSrc: toRef(overlayState, 'videoSrc'),
    overlayImageSrc: computed(() => overlayState.image?.src ?? null),
});

const {
    audioCurrentTime,
    audioDuration,
    isAudioPlaying,
    audioVolume,
    audioProgressPercent,
    audioVolumePercent,
    handleAudioLoadedMetadata,
    handleAudioTimeUpdate,
    handleAudioPlay,
    handleAudioPause,
    handleAudioEnded,
    handleAudioVolumeChange,
    toggleAudioPlayback,
    handleAudioSeek,
    handleAudioSeekStart,
    handleAudioSeekEnd,
    handleAudioVolumeInput,
} = useOverlayAudioControls({
    overlayAudioRef,
    overlayMediaType: toRef(overlayState, 'mediaType'),
    overlayFillComplete: toRef(overlayState, 'fillComplete'),
    overlayIsClosing: toRef(overlayState, 'isClosing'),
    overlayAudioSrc: toRef(overlayState, 'audioSrc'),
});

const {getAvailableWidth, calculateBestFitSize, getCenteredPosition} = useFileViewerSizing({
    overlay: overlayState,
    sheet: sheetState,
});

const {closeOverlay} = useFileViewerOverlayState({
    containerRef: computed(() => props.containerRef),
    container: containerState,
    overlay: overlayState,
    navigation: navigationState,
    sheet: sheetState,
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
        img.onload = () => resolve({width: img.naturalWidth, height: img.naturalHeight});
        img.onerror = () => reject(new Error(`Failed to load image: ${url}`));
        img.src = url;
    });
}

const {fileData, isLoadingFileData, handleItemSeen} = useFileViewerData({
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

const {openFromClick} = useFileViewerOpen({
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

const {handleTouchStart, handleTouchEnd} = useFileViewerNavigation({
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

const { clearPreloadCache } = useFileViewerPreload({
    items,
    currentItemIndex: toRef(navigationState, 'currentItemIndex'),
    fillComplete: toRef(overlayState, 'fillComplete'),
    preloadCount: 2,
});

// Handle ALT + Middle Click (mousedown event needed for middle button)
function handleOverlayImageMouseDown(e: MouseEvent): void {
    if (e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
        void handleViewerReaction('love');
        return;
    }
    // Middle click without ALT - open original URL (prevent default to avoid browser scroll)
    if (!e.altKey && e.button === 1) {
        e.preventDefault();
        e.stopPropagation();
        // Actual opening will be handled in auxclick
        return;
    }

}

function handleOverlayMediaClick(e: MouseEvent): void {
    if (!e.altKey) {
        return;
    }
    if (e.button !== 0 && e.type !== 'click') {
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    void handleViewerReaction('like');
}

function handleOverlayMediaContextMenu(e: MouseEvent): void {
    if (!e.altKey) {
        return;
    }
    e.preventDefault();
    e.stopPropagation();
    void handleViewerReaction('dislike');
}

// Handle middle click (auxclick) to open original URL
function handleOverlayImageAuxClick(e: MouseEvent): void {
    // Middle click without ALT - open original URL
    if (!e.altKey && e.button === 1 && navigationState.currentItemIndex !== null) {
        e.preventDefault();
        e.stopPropagation();

        const currentItem = items.value[navigationState.currentItemIndex];
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

async function handleViewerReaction(type: ReactionType): Promise<void> {
    const item = currentItem.value;
    if (!item) {
        return;
    }
    emit('reaction', item.id, type);
    await nextTick();

    if (items.value.length === 0) {
        await ensureMoreItems();
        if (items.value.length === 0) {
            closeOverlay();
            return;
        }
    }

    const previousIndex = navigationState.currentItemIndex;
    const currentIndexInList = items.value.findIndex((candidate) => candidate.id === item.id);
    let targetIndex: number | null = null;

    if (currentIndexInList === -1) {
        if (previousIndex !== null) {
            targetIndex = Math.min(previousIndex, items.value.length - 1);
        }
    } else {
        const nextIndex = currentIndexInList + 1;
        if (nextIndex < items.value.length) {
            targetIndex = nextIndex;
        }
    }

    if (targetIndex === null) {
        await ensureMoreItems();
        if (items.value.length === 0) {
            closeOverlay();
            return;
        }
        targetIndex = Math.min(previousIndex ?? 0, items.value.length - 1);
    }

    navigationState.currentItemIndex = targetIndex;
    currentItemId.value = items.value[targetIndex]?.id ?? null;
    void navigateToIndex(targetIndex, 'down');
}

watch(() => [navigationState.currentItemIndex, overlayState.fillComplete], ([newIndex, isFilled]) => {
    if (newIndex === null || !isFilled) return;
    if (items.value.length - 1 - newIndex <= 1) {
        void ensureMoreItems();
    }
});

watch(() => navigationState.currentItemIndex, (index) => {
    if (index === null || index < 0 || index >= items.value.length) {
        currentItemId.value = null;
        return;
    }
    currentItemId.value = items.value[index]?.id ?? null;
});

watch(() => items.value.map((item) => item.id), () => {
    if (currentItemId.value === null) {
        const index = navigationState.currentItemIndex;
        if (index !== null && index >= 0 && index < items.value.length) {
            currentItemId.value = items.value[index]?.id ?? null;
        }
        return;
    }
    const nextIndex = items.value.findIndex((item) => item.id === currentItemId.value);
    if (nextIndex !== -1 && nextIndex !== navigationState.currentItemIndex) {
        navigationState.currentItemIndex = nextIndex;
    }
});

watch(() => [overlayState.mediaType, overlayState.fillComplete, overlayState.isClosing], ([mediaType, filled, isClosing]) => {
    if (mediaType === 'file' && filled && !isClosing) {
        sheetState.isOpen = true;
    }
});

// Cleanup on unmount
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
    clearPreloadCache();
});

// Expose methods for parent component
defineExpose({
    openFromClick,
    close: closeOverlay,
    navigateForward: navigateToNext,
    navigateBackward: navigateToPrevious,
    currentItemIndex,
    overlayState,
    navigationState,
    sheetState,
    items,
});
</script>

<template>
    <!-- Click overlay -->
    <div v-if="overlayState.rect && overlayState.image" :class="overlayContainerClass" :style="overlayContainerStyle"
         @touchstart.passive="handleTouchStart" @touchend.passive="handleTouchEnd">
        <!-- Main content area -->
        <div :class="overlayContentClass" :style="overlayMediaWrapperStyle">
            <!-- Image container -->
            <div class="relative flex-1 min-h-0 overflow-hidden" :style="overlayMediaWrapperStyle">
                <!-- Preview image (shown immediately, behind spinner) -->
                <img v-if="overlayState.isLoading" :key="overlayState.key + '-preview'" :src="overlayState.image.src"
                     :srcset="overlayState.image.srcset" :sizes="overlayState.image.sizes" :alt="overlayState.image.alt" :class="[
                        'absolute select-none pointer-events-none object-cover',
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" draggable="false"/>

                <!-- Spinner while loading full-size image -->
                <div v-if="overlayState.isLoading" class="absolute inset-0 flex items-center justify-center z-10">
                    <Loader2 :size="32" class="animate-spin text-smart-blue-500"/>
                </div>

                <!-- Full-size media (image/icon or video) -->
                <img v-if="!overlayState.isLoading && overlayState.mediaType !== 'video'" :key="overlayState.key"
                     :src="overlayState.fullSizeImage || overlayState.image.src" :alt="overlayState.image.alt"
                     :class="[
                        'absolute select-none',
                        overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing ? 'cursor-pointer pointer-events-auto' : 'pointer-events-none',
                        overlayState.isFilled ? '' : 'object-cover',
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" draggable="false"
                     @click="handleOverlayMediaClick"
                     @contextmenu="handleOverlayMediaContextMenu"
                     @mousedown="handleOverlayImageMouseDown"
                     @auxclick="handleOverlayImageAuxClick"/>

                <video v-else-if="!overlayState.isLoading && overlayState.mediaType === 'video'" :key="overlayState.key"
                       :poster="overlayVideoPoster" ref="overlayVideoRef"
                       :class="[
                        'absolute',
                        overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing ? 'pointer-events-auto' : 'pointer-events-none',
                        overlayState.isFilled ? 'object-contain' : 'object-cover',
                        overlayMediaTransitionClass
                    ]" :style="overlayMediaStyle" playsinline disablepictureinpicture preload="metadata"
                       @click="handleOverlayMediaClick"
                       @contextmenu="handleOverlayMediaContextMenu"
                       @loadedmetadata="handleVideoLoadedMetadata" @timeupdate="handleVideoTimeUpdate"
                       @play="handleVideoPlay" @pause="handleVideoPause" @ended="handleVideoEnded"
                       @volumechange="handleVideoVolumeChange" @mousedown="handleOverlayImageMouseDown"
                       @auxclick="handleOverlayImageAuxClick">
                    <source v-if="overlayState.videoSrc" :src="overlayState.videoSrc" type="video/mp4"/>
                </video>

                <div
                    v-if="!overlayState.isLoading && overlayState.mediaType === 'video' && overlayState.fillComplete && !overlayState.isClosing"
                    class="absolute bottom-4 left-0 right-0 z-50 pointer-events-auto px-4">
                    <div
                        class="flex w-full items-center gap-3 rounded border border-twilight-indigo-500/80 bg-prussian-blue-800/80 px-3 py-2 backdrop-blur">
                        <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                                :aria-label="isVideoPlaying ? 'Pause video' : 'Play video'"
                                @click.stop="toggleVideoPlayback">
                            <Pause v-if="isVideoPlaying" :size="16"/>
                            <Play v-else :size="16"/>
                        </button>
                        <div class="flex-1 min-w-0">
                            <input class="file-viewer-video-slider w-full" type="range" min="0"
                                   :max="videoDuration || 0" step="0.1" :value="videoCurrentTime"
                                   :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${videoProgressPercent}%, var(--color-twilight-indigo-600) ${videoProgressPercent}%)`
                                }" @input="handleVideoSeek" @pointerdown="handleVideoSeekStart"
                                   @pointerup="handleVideoSeekEnd"/>
                        </div>
                        <div class="w-24 shrink-0">
                            <input class="file-viewer-video-slider w-full" type="range" min="0" max="1" step="0.01"
                                   :value="videoVolume" :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${videoVolumePercent}%, var(--color-twilight-indigo-600) ${videoVolumePercent}%)`
                                }" @input="handleVideoVolumeInput"/>
                        </div>
                        <button type="button" class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                                :aria-label="isVideoFullscreen ? 'Exit fullscreen' : 'Enter fullscreen'"
                                @click.stop="toggleVideoFullscreen">
                            <Minimize2 v-if="isVideoFullscreen" :size="16"/>
                            <Maximize2 v-else :size="16"/>
                        </button>
                    </div>
                </div>

                <audio
                    v-if="!overlayState.isLoading && overlayState.mediaType === 'audio'"
                    ref="overlayAudioRef"
                    class="hidden"
                    preload="metadata"
                    @loadedmetadata="handleAudioLoadedMetadata"
                    @timeupdate="handleAudioTimeUpdate"
                    @play="handleAudioPlay"
                    @pause="handleAudioPause"
                    @ended="handleAudioEnded"
                    @volumechange="handleAudioVolumeChange"
                />

                <div
                    v-if="!overlayState.isLoading && overlayState.mediaType === 'audio' && overlayState.fillComplete && !overlayState.isClosing"
                    class="absolute bottom-4 left-0 right-0 z-50 pointer-events-auto px-4"
                >
                    <div
                        class="flex w-full items-center gap-3 rounded border border-twilight-indigo-500/80 bg-prussian-blue-800/80 px-3 py-2 backdrop-blur"
                    >
                        <button
                            type="button"
                            class="rounded-full p-2 text-smart-blue-100 hover:text-white"
                            :aria-label="isAudioPlaying ? 'Pause audio' : 'Play audio'"
                            @click.stop="toggleAudioPlayback"
                        >
                            <Pause v-if="isAudioPlaying" :size="16" />
                            <Play v-else :size="16" />
                        </button>
                        <div class="flex-1 min-w-0">
                            <input
                                class="file-viewer-video-slider w-full"
                                type="range"
                                min="0"
                                :max="audioDuration || 0"
                                step="0.1"
                                :value="audioCurrentTime"
                                :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${audioProgressPercent}%, var(--color-twilight-indigo-600) ${audioProgressPercent}%)`
                                }"
                                @input="handleAudioSeek"
                                @pointerdown="handleAudioSeekStart"
                                @pointerup="handleAudioSeekEnd"
                            />
                        </div>
                        <div class="w-24 shrink-0">
                            <input
                                class="file-viewer-video-slider w-full"
                                type="range"
                                min="0"
                                max="1"
                                step="0.01"
                                :value="audioVolume"
                                :style="{
                                    backgroundImage: `linear-gradient(to right, var(--color-smart-blue-400) ${audioVolumePercent}%, var(--color-twilight-indigo-600) ${audioVolumePercent}%)`
                                }"
                                @input="handleAudioVolumeInput"
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-if="overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing && currentItem"
                    class="absolute bottom-4 left-1/2 z-50 -translate-x-1/2 pointer-events-auto"
                >
                    <FileReactions
                        :file-id="currentItem.id"
                        :reaction="currentItem.reaction as ({ type: string } | null | undefined)"
                        :previewed-count="currentItem.previewed_count ?? 0"
                        :viewed-count="currentItem.seen_count ?? 0"
                        :current-index="navigationState.currentItemIndex ?? 0"
                        :total-items="items.length"
                        variant="default"
                        @reaction="handleViewerReaction"
                    />
                </div>

                <!-- Close button -->
                <button v-if="overlayState.fillComplete && !overlayState.isClosing" @click="closeOverlay"
                        class="absolute top-4 right-4 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto"
                        aria-label="Close overlay" data-test="close-overlay-button">
                    <X :size="20"/>
                </button>

                <div
                    v-if="overlayState.fillComplete && !overlayState.isClosing && containerState.isLoadingMore"
                    class="absolute top-4 left-1/2 z-50 -translate-x-1/2 rounded-full border border-smart-blue-500/80 bg-prussian-blue-800/90 px-4 py-2 text-xs font-medium text-smart-blue-100 backdrop-blur"
                >
                <span class="inline-flex items-center gap-2">
                    <Loader2 :size="14" class="animate-spin"/>
                    Loading more items…
                </span>
                </div>
            </div>
        </div>

        <!-- Vertical Taskbar (only shown when filled) -->
        <div v-if="overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing && !sheetState.isOpen"
             class="flex flex-col items-center justify-center gap-4 p-4 bg-prussian-blue-800 border-l-2 border-twilight-indigo-500 shrink-0 transition-all duration-300 ease-in-out w-16">
            <!-- CTA Button to open sheet -->
            <button @click="sheetState.isOpen = true"
                    class="p-3 rounded-lg bg-smart-blue-500 hover:bg-smart-blue-600 text-white transition-colors"
                    aria-label="Open sheet">
                <Menu :size="20"/>
            </button>
        </div>

        <FileViewerSheet
            v-if="overlayState.isFilled && overlayState.fillComplete && !overlayState.isClosing"
            :is-open="sheetState.isOpen"
            :file-id="navigationState.currentItemIndex !== null && items[navigationState.currentItemIndex] ? items[navigationState.currentItemIndex].id : null"
            :file-data="fileData ?? null"
            :is-loading="isLoadingFileData"
            @close="sheetState.isOpen = false"
        />
    </div>
</template>
