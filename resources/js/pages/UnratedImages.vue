<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import { createMasonryPageLoader } from '@/composables/useMasonryData';
import { useItemReactions } from '@/composables/useItemReactions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { BrowseItem as IBrowseItem } from '@/types/browse';
import { Head } from '@inertiajs/vue3';
import { useImageZoom } from '@/composables/useImageZoom';
import { Masonry } from '@wyxos/vibe';
import { onBeforeUnmount, onMounted, ref } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Images', href: route('images.index') },
    { title: 'Unrated', href: route('images.unrated') },
];

const items = ref<IBrowseItem[]>([] as any);
const masonry = ref<any>(null);
const getPage = createMasonryPageLoader({ routeName: 'images.unrated.data', defaultLimit: 40 });

// Full-screen viewer state/actions (same composable used by Browse.vue)
const {
    isImageViewerOpen,
    imageViewerZoom,
    imageViewerPosition,
    currentImage,
    allImages,
    currentIndex,
    imageUrl,
    isCurrentVideo,
    isCurrentImage,
    canGoNext,
    canGoPrevious,
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
    removeCurrentAndGoNext,
    isDragging,
} = useImageZoom();

// Reactions
const { handleFavorite, handleLike, handleDislike, handleLaughedAt, blacklistImage, startDownload } = useItemReactions();

const removeItemFromView = (item: IBrowseItem) => {
    if (masonry.value && typeof masonry.value.onRemove === 'function') {
        masonry.value.onRemove(item);
    }
};

const onFavorite = (item: IBrowseItem, event: Event) => handleFavorite(item, event, removeItemFromView);
const onLike = (item: IBrowseItem, event: Event) => handleLike(item, event, removeItemFromView);
const onDislike = (item: IBrowseItem, event: Event) => handleDislike(item, event, () => blacklistImage(item, removeItemFromView));
const onLaughedAt = (item: IBrowseItem, event: Event) => handleLaughedAt(item, event, removeItemFromView);

// Alt shortcuts (match Browse.vue behavior)
const onAltClick = (item: IBrowseItem) => {
    startDownload(item);
    handleLike(item, new Event('click'), removeItemFromView);
};
const onAltMiddleClick = (item: IBrowseItem) => {
    startDownload(item);
    handleFavorite(item, new Event('click'), removeItemFromView);
};
const onAltRightClick = (item: IBrowseItem) => {
    blacklistImage(item, removeItemFromView);
};

// Fullscreen Alt shortcuts (mirror Browse.vue)
const handleFullScreenAltClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value as IBrowseItem;
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);
        startDownload(itemToProcess);
        handleLike(itemToProcess, new Event('click'));
    }
};
const handleFullScreenAltMiddleClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value as IBrowseItem;
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);
        startDownload(itemToProcess);
        handleFavorite(itemToProcess, new Event('click'));
    }
};
const handleFullScreenAltRightClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value as IBrowseItem;
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);
        blacklistImage(itemToProcess);
    }
};

// Prevent browser back/forward:
// - Always block Alt + X1/X2 globally to reserve for our shortcuts
// - Also block all aux buttons when fullscreen viewer is open
const globalAuxMouseBlocker = (event: MouseEvent) => {
    const btn = (event as any).button;

    if (event.altKey && (btn === 3 || btn === 4)) {
        event.preventDefault?.();
        // Let mousedown propagate so item-level logic can run; block on mouseup/auxclick
        if (event.type !== 'mousedown') {
            event.stopImmediatePropagation?.();
        }
        return;
    }

    if (isImageViewerOpen.value && (btn === 3 || btn === 4)) {
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
    }
};

onMounted(() => {
    window.addEventListener('mousedown', globalAuxMouseBlocker, { capture: true });
    window.addEventListener('mouseup', globalAuxMouseBlocker, { capture: true });
    window.addEventListener('auxclick', globalAuxMouseBlocker, { capture: true } as any);
});

onBeforeUnmount(() => {
    window.removeEventListener('mousedown', globalAuxMouseBlocker, { capture: true } as any);
    window.removeEventListener('mouseup', globalAuxMouseBlocker, { capture: true } as any);
    window.removeEventListener('auxclick', globalAuxMouseBlocker, { capture: true } as any);
});

// Mouse navigation handler in fullscreen (like Browse.vue)
const handleMouseNavigation = (event: MouseEvent) => {
    // Only act on initial press; release/auxclick can target a different element
    if (event.type !== 'mousedown') return;

    const isBackButton = (event as any).button === 3;
    const isForwardButton = (event as any).button === 4;

    if (isBackButton || isForwardButton) {
        event.preventDefault?.();
        event.stopPropagation?.();
    }

    // Alt + back => block post of current image
    if (isBackButton && event.altKey && (currentImage.value as any)?.listingMetadata?.postId) {
        const postId = (currentImage.value as any).listingMetadata.postId;
        window?.dispatchEvent?.(new CustomEvent('browse:block-post', { detail: { postId } }));
        return;
    }

    // Previous image
    if (isBackButton && canGoPrevious.value) {
        goToPrevious();
        return;
    }

    // Alt + forward => like post of current image
    if (isForwardButton && event.altKey && (currentImage.value as any)?.listingMetadata?.postId) {
        const postId = (currentImage.value as any).listingMetadata.postId;
        window?.dispatchEvent?.(new CustomEvent('browse:like-post', { detail: { postId } }));
        return;
    }

    // Next image
    if (isForwardButton && canGoNext.value) {
        goToNext();
        return;
    }
};
</script>

<template>
    <Head title="Unrated Images" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-screen flex-col overflow-hidden">
            <!-- Masonry Container -->
            <div class="relative min-h-0 flex-1">
                <Masonry
                    ref="masonry"
                    v-model:items="items"
                    :get-next-page="getPage"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 3, xl: 5, '2xl': 8 },
                        footer: 32,
                    }"
                    :load-at-page="1"
                    :max-items="150"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <div class="group relative">
                            <!-- Media container with reserved footer space (32px) -->
                            <div style="padding-bottom: 32px">
                                <img
                                    :src="item.image_url || item.src"
                                    :width="item.width"
                                    :height="item.imageHeight || item.height"
                                    alt="Image"
class="w-full cursor-pointer object-cover block"
                                    @click.left.exact="openImageViewer(item, items)"
                                    @click.alt.exact.prevent="onAltClick(item)"
                                    @click.middle.alt.exact.prevent="onAltMiddleClick(item)"
                                    @contextmenu.alt.exact.prevent="onAltRightClick(item)"
                                />
                            </div>
                            <!-- Footer area for reactions (mirror BrowseItem) -->
                            <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
                                <div>
                                    <FileReactions
                                        :file="item"
                                        :icon-size="16"
                                        variant="list"
                                        @favorite="onFavorite(item, $event)"
                                        @like="onLike(item, $event)"
                                        @dislike="onDislike(item, $event)"
                                        @laughedAt="onLaughedAt(item, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>
                </Masonry>
            </div>
        </div>
        
        <!-- Full Screen Media Viewer Modal (mirrors Browse.vue basics) -->
                <div
            v-if="isImageViewerOpen"
            class="fixed inset-0 z-50 flex bg-black/90"
            tabindex="0"
@click="closeImageViewer"
            @mousedown.prevent.stop="handleMouseNavigation"
            @auxclick.prevent.stop="handleMouseNavigation"
            @keydown.escape="closeImageViewer"
            @keydown.left="canGoPrevious && ( $event.preventDefault(), goToPrevious() )"
            @keydown.right="canGoNext && ( $event.preventDefault(), goToNext() )"
        >
            <!-- Top bar -->
            	<div class="flex flex-1 flex-col">
                <div class="flex h-16 flex-shrink-0 items-center justify-between px-4">
                    <div class="flex gap-2" v-if="isCurrentImage">
                        <button class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="zoomOut">-</button>
                        <button class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="resetZoom">reset</button>
                        <button class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="zoomIn">+</button>
                        <div class="rounded bg-white/10 px-2 py-1 text-sm text-white">{{ Math.round(imageViewerZoom * 100) }}%</div>
                    </div>
                    <div class="flex gap-2">
                        <button v-if="canGoPrevious" class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="goToPrevious">Prev</button>
                        <div class="rounded bg-white/10 px-3 py-2 text-sm text-white">{{ currentIndex + 1 }} / {{ allImages.length }}</div>
                        <button v-if="canGoNext" class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="goToNext">Next</button>
                    </div>
                    <button class="bg-white/10 px-2 py-1 rounded text-white" @click.stop="closeImageViewer">Close</button>
                </div>

                <!-- Media area -->
                <div class="relative flex-1 overflow-hidden">
                    <div
                        :class="{ 'cursor-pointer': !isCurrentImage }"
                        class="flex h-full w-full items-center justify-center overflow-hidden"
                        @mousedown="isCurrentImage ? startDrag : null"
                        @mouseleave="isCurrentImage ? stopDrag : null"
                        @mousemove="isCurrentImage ? onDrag : null"
                        @mouseup="isCurrentImage ? stopDrag : null"
                        @click.alt.exact.prevent="handleFullScreenAltClick"
                        @click.middle.alt.exact.prevent="handleFullScreenAltMiddleClick"
                        @contextmenu.alt.exact.prevent="handleFullScreenAltRightClick"
                    >
                        <!-- Image -->
                        <img
                            v-if="currentImage && isCurrentImage"
                            :alt="currentImage.name || `Image ${currentImage.id}`"
                            :src="imageUrl"
                            :style="{
                                transform: `scale(${imageViewerZoom}) translate(${imageViewerPosition.x / imageViewerZoom}px, ${imageViewerPosition.y / imageViewerZoom}px)`,
                                cursor: imageViewerZoom > 1 ? (isDragging ? 'grabbing' : 'grab') : 'default',
                            }"
                            class="max-h-full max-w-full object-contain transition-transform"
                            @click.alt.exact.prevent="handleFullScreenAltClick"
                            @click.middle.alt.exact.prevent="handleFullScreenAltMiddleClick"
                            @click.stop
                            @dragstart.prevent
                        />

                        <!-- Video -->
                        <video
                            v-else-if="currentImage && isCurrentVideo"
                            :src="imageUrl"
                            autoplay
                            class="max-h-full max-w-full object-contain"
                            controls
                            loop
                        >
                            <source :src="imageUrl" type="video/mp4" />
                            Your browser does not support the video tag.
                        </video>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
