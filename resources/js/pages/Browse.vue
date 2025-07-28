<script lang="ts" setup>
import BrowseFilters from '@/components/browse/BrowseFilters.vue';
import BrowseItem from '@/components/browse/BrowseItem.vue';
import FileReactions from '@/components/audio/FileReactions.vue';
import { useDownloadProgress } from '@/composables/useDownloadProgress';
import { useItemReactions } from '@/composables/useItemReactions';
import { useImageZoom } from '@/composables/useImageZoom';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { AUTOCYCLE_DELAY, MAX_AUTOCYCLE_ATTEMPTS } from '@/constants/browse';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { BrowseProps, BrowseFilters as IBrowseFilters, BrowseItem as IBrowseItem, PaginationState } from '@/types/browse';
import { Head, router } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { ref } from 'vue';

const props = defineProps<BrowseProps>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Browse',
        href: '/browse',
    },
];

const masonryItems = ref<IBrowseItem[]>([]);
const masonry = ref(null);
const isAutocycling = ref(false);
const autocycleAttempts = ref(0);

// Filter state management
const currentFilters = ref<IBrowseFilters>({
    page: props.filters.page,
    nextPage: props.filters.nextPage,
    sort: props.filters.sort,
    period: props.filters.period,
    nsfw: props.filters.nsfw,
    autoNext: props.filters.autoNext,
});

// Unified pagination state - works with both cursor and page-based pagination
const paginationState = ref<PaginationState>({
    page: props.filters.page,
    nextPage: props.filters.nextPage,
});

// Use composables
const { downloadProgress, downloadedItems } = useDownloadProgress();
const { startDownload, handleFavorite, handleLike, handleDislike, handleLaughedAt, blacklistImage, undoLastBlacklist } = useItemReactions();
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
    isDragging,
    goToNext,
    goToPrevious,
    removeCurrentAndGoNext
} = useImageZoom();

// Remove item from masonry view
const removeItemFromView = async (item: IBrowseItem) => {
    if (masonry.value && typeof masonry.value.onRemove === 'function') {
        masonry.value.onRemove(item);

        // Check if we need to load more items after removal
        // Use a small delay to allow the masonry to update its internal state
        setTimeout(async () => {
            // Check if there are no visible items left and more pages are available
            if (masonryItems.value.length === 0 && paginationState.value.nextPage) {
                // If auto next is enabled, trigger autocycle, otherwise just load next page
                if (currentFilters.value.autoNext) {
                    await autocycleUntilItems();
                } else {
                    await loadNext();
                }
            }
        }, 500); // Small delay to allow masonry state to update
    }
};

// Handle Alt+click for download and like
const handleAltClick = (item: IBrowseItem) => {
    // Start download
    startDownload(item);
    // Also trigger like reaction with removal callback
    handleLike(item, new Event('click'), removeItemFromView);
};

// Handle Alt+right-click for blacklist
const handleAltRightClick = (item: IBrowseItem) => {
    blacklistImage(item, removeItemFromView);
};

// Handle left click for single image view
const handleLeftClick = (item: IBrowseItem) => {
    console.log('Left clicked item:', item.id);
    // Open image viewer with full list for navigation
    openImageViewer(item, masonryItems.value);
};

// Reaction handlers for full screen mode
const handleFullScreenFavorite = (item: IBrowseItem, event: Event) => {
    handleFavorite(item, event, () => {
        // Remove from both masonry and viewer array
        removeItemFromView(item);
        removeCurrentAndGoNext();
    });
};

const handleFullScreenLike = (item: IBrowseItem, event: Event) => {
    handleLike(item, event, () => {
        // Remove from both masonry and viewer array
        removeItemFromView(item);
        removeCurrentAndGoNext();
    });
};

const handleFullScreenDislike = (item: IBrowseItem, event: Event) => {
    handleDislike(item, event, (item) => {
        // Use blacklist for dislike in full screen
        blacklistImage(item, () => {
            removeItemFromView(item);
            removeCurrentAndGoNext();
        });
    });
};

const handleFullScreenLaughedAt = (item: IBrowseItem, event: Event) => {
    handleLaughedAt(item, event, () => {
        // Remove from both masonry and viewer array
        removeItemFromView(item);
        removeCurrentAndGoNext();
    });
};

// Autocycle function - uses masonry's loadNext method repeatedly until items are found
const autocycleUntilItems = async (): Promise<void> => {
    isAutocycling.value = true;
    autocycleAttempts.value = 0;

    let attempts = 0;
    const initialItemCount = masonryItems.value.length;

    try {
        while (attempts < MAX_AUTOCYCLE_ATTEMPTS && paginationState.value.nextPage) {
            attempts++;
            autocycleAttempts.value = attempts;
            console.log(`Autocycle attempt ${attempts}/${MAX_AUTOCYCLE_ATTEMPTS}`);

            if (masonry.value && typeof masonry.value.loadNext === 'function') {
                await masonry.value.loadNext();

                // Check if new items were added after loadNext
                if (masonryItems.value.length > initialItemCount) {
                    console.log(`Autocycle successful after ${attempts} attempts - found ${masonryItems.value.length - initialItemCount} new items`);
                    break;
                }

                // Small delay to prevent overwhelming the API
                await new Promise((resolve) => setTimeout(resolve, AUTOCYCLE_DELAY));
            } else {
                console.warn('Masonry component not ready or loadNext function not available');
                break;
            }
        }

        if (attempts >= MAX_AUTOCYCLE_ATTEMPTS) {
            console.warn('Autocycle stopped after maximum attempts');
        }

        if (masonryItems.value.length === initialItemCount) {
            console.log('Autocycle complete - no more pages available or no new items found');
        }
    } catch (error) {
        console.error('Error during autocycle:', error);
    } finally {
        isAutocycling.value = false;
    }
};

// Unified pagination handler - works with both cursor and page-based pagination
const getPage = async (pageParam: number | string) => {
    try {
        const queryParams = {
            page: pageParam,
            sort: currentFilters.value.sort,
            period: currentFilters.value.period,
            nsfw: currentFilters.value.nsfw,
            autoNext: currentFilters.value.autoNext,
            search: 1,
        };

        // Use Inertia to fetch data
        return new Promise((resolve) => {
            router.get(
                route('browse.data', queryParams),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'filters'],
                    onSuccess: (response) => {
                        try {
                            const newItems = response.props.items as IBrowseItem[];
                            const filters = response.props.filters as IBrowseFilters;
                            const nextPage = filters.nextPage;
                            const currentPage = filters.page;

                            paginationState.value = {
                                page: currentPage,
                                nextPage: nextPage ? nextPage : null,
                            };

                            // Check if we should auto cycle after getting empty results
                            if (!isAutocycling.value && newItems.length === 0 && nextPage && currentFilters.value.autoNext) {
                                // Automatically trigger next page if auto next is enabled
                                setTimeout(() => autocycleUntilItems(), 100);
                            }

                            resolve({
                                items: newItems,
                                nextPage: paginationState.value.nextPage,
                            });
                        } catch (error) {
                            console.error('Error processing response:', error);
                            resolve({ items: [], nextPage: null });
                        }
                    },
                    onError: (errors) => {
                        console.error('Failed to fetch more images:', errors);
                        resolve({ items: [], nextPage: null });
                    },
                },
            );
        });
    } catch (error) {
        console.error('Failed to fetch more images:', error);
        return { items: [], nextPage: null };
    }
};

// Filter change handlers - navigate back to page 1 when filters change
const handleSortChange = (newSort: string) => {
    currentFilters.value.sort = newSort;
    applyFilters();
};

const handlePeriodChange = (newPeriod: string) => {
    currentFilters.value.period = newPeriod;
    applyFilters();
};

const handleNsfwChange = (checked: boolean) => {
    currentFilters.value.nsfw = checked;
    applyFilters();
};

const handleAutoNextChange = (checked: boolean) => {
    currentFilters.value.autoNext = checked;
    applyFilters();
};

const handleBackToFirst = () => {
    applyFilters();
};

// Apply filters by navigating to the browse page with new parameters (no page parameter to go to page 1)
const applyFilters = () => {
    paginationState.value.page = null;
    paginationState.value.nextPage = null;

    masonryItems.value = [];

    masonry.value?.loadPage(null);
};

// Load next page of images
const loadNext = async () => {
    if (masonry.value && typeof masonry.value.loadNext === 'function') {
        await masonry.value.loadNext();
    } else {
        console.warn('Masonry component not ready or loadNext function not available');
    }
};

// Handle undo blacklist
const handleUndoBlacklist = async () => {
    try {
        const result = await undoLastBlacklist();
        if (result.success) {
            // You could show a toast notification here if you have a toast system
            alert(result.message); // Temporary alert - you might want to replace with a proper toast
        }
    } catch (error) {
        console.error('Failed to undo blacklist:', error);
        // Handle error case
        if (error.response?.status === 404) {
            alert('No blacklisted items found to undo.');
        } else {
            alert('Failed to undo blacklist. Please try again.');
        }
    }
};
</script>

<template>
    <Head title="Browse" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-screen flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex-shrink-0 border-b p-4">
                <div class="flex flex-col items-center gap-4">
                    <BrowseFilters
                        :filters="currentFilters"
                        @sort-change="handleSortChange"
                        @period-change="handlePeriodChange"
                        @nsfw-change="handleNsfwChange"
                        @auto-next-change="handleAutoNextChange"
                        @back-to-first="handleBackToFirst"
                        @load-next="loadNext"
                        @undo-blacklist="handleUndoBlacklist"
                    />
                </div>
            </div>

            <!-- Masonry Container -->
            <div class="relative min-h-0 flex-1">
                <Masonry
                    ref="masonry"
                    v-model:items="masonryItems"
                    :get-next-page="getPage"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 },
                        footer: 32,
                    }"
                    :load-at-page="filters.page"
                    :max-items="300"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <BrowseItem
                            :download-progress="downloadProgress[item.id]"
                            :is-downloaded="downloadedItems.has(item.id)"
                            :item="item"
                            @dislike="(file, event) => handleDislike(file, event, (item) => blacklistImage(item, removeItemFromView))"
                            @favorite="(file, event) => handleFavorite(file, event, removeItemFromView)"
                            @like="(file, event) => handleLike(file, event, removeItemFromView)"
                            @laughed-at="(file, event) => handleLaughedAt(file, event, removeItemFromView)"
                            @alt-click="handleAltClick"
                            @alt-right-click="handleAltRightClick"
                            @left-click="handleLeftClick"
                        />
                    </template>
                </Masonry>

                <!-- Loading Overlay -->
                <div
                    v-if="masonry?.isLoading || isAutocycling"
                    class="bg-opacity-30 absolute inset-0 z-50 flex items-center justify-center backdrop-blur-[2px]"
                >
                    <div class="flex flex-col items-center gap-3 rounded-lg bg-primary p-6 shadow-lg">
                        <div class="flex items-center gap-3">
                            <div class="h-6 w-6 animate-spin rounded-full border-b-2 border-blue-500"></div>
                            <span class="font-medium text-white">
                                {{ isAutocycling ? 'Finding available items...' : 'Loading more images...' }}
                            </span>
                        </div>
                        <div v-if="isAutocycling" class="text-sm text-gray-200">Attempt {{ autocycleAttempts }} of {{ MAX_AUTOCYCLE_ATTEMPTS }}</div>
                    </div>
                </div>
                <div v-if="!masonry?.isLoading && masonryItems.length === 0" class="absolute inset-0 flex items-center justify-center">
                    <div class="text-gray-500">No images found. Try changing filters or loading more.</div>
                </div>
            </div>
        </div>

        <!-- Full Screen Media Viewer Modal -->
        <div
            v-if="isImageViewerOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/90"
            @click="closeImageViewer"
            @keydown.escape="closeImageViewer"
            @keydown.left="goToPrevious"
            @keydown.right="goToNext"
            tabindex="0"
        >
            <div class="relative h-full w-full">
                <!-- Close Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="absolute top-4 right-4 z-10 bg-white/10 backdrop-blur-sm hover:bg-white/20"
                    @click.stop="closeImageViewer"
                >
                    <Icon name="x" class="h-4 w-4" />
                </Button>

                <!-- Navigation Controls -->
                <div class="absolute top-4 left-1/2 transform -translate-x-1/2 z-10 flex gap-2">
                    <Button
                        v-if="canGoPrevious"
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click.stop="goToPrevious"
                    >
                        <Icon name="chevronLeft" class="h-4 w-4" />
                        Previous
                    </Button>
                    <div class="bg-white/10 backdrop-blur-sm rounded px-3 py-2 text-sm text-white">
                        {{ currentIndex + 1 }} / {{ allImages.length }}
                    </div>
                    <Button
                        v-if="canGoNext"
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click.stop="goToNext"
                    >
                        Next
                        <Icon name="chevronRight" class="h-4 w-4" />
                    </Button>
                </div>

                <!-- Zoom Controls (only for images) -->
                <div v-if="isCurrentImage" class="absolute top-4 left-4 z-10 flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click.stop="zoomOut"
                    >
                        <Icon name="zoomOut" class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click.stop="resetZoom"
                    >
                        <Icon name="maximize" class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click.stop="zoomIn"
                    >
                        <Icon name="zoomIn" class="h-4 w-4" />
                    </Button>
                </div>

                <!-- Reactions Panel -->
                <div class="absolute bottom-4 right-4 z-10" @click.stop>
                    <div v-if="currentImage" class="bg-white/10 backdrop-blur-sm rounded-lg p-3">
                        <FileReactions
                            :file="currentImage"
                            :icon-size="20"
                            variant="player"
                            @favorite="(file, event) => handleFullScreenFavorite(file, event)"
                            @like="(file, event) => handleFullScreenLike(file, event)"
                            @dislike="(file, event) => handleFullScreenDislike(file, event)"
                            @laughedAt="(file, event) => handleFullScreenLaughedAt(file, event)"
                        />
                    </div>
                </div>

                <!-- Zoom Level Indicator (only for images) -->
                <div v-if="isCurrentImage" class="absolute bottom-4 left-4 z-10 rounded bg-white/10 px-2 py-1 text-sm text-white backdrop-blur-sm">
                    {{ Math.round(imageViewerZoom * 100) }}%
                </div>

                <!-- Media Container -->
                <div
                    class="flex h-full w-full items-center justify-center overflow-hidden"
                    :class="{ 'cursor-pointer': !isCurrentImage }"
                    @mousedown="isCurrentImage ? startDrag : null"
                    @mousemove="isCurrentImage ? onDrag : null"
                    @mouseup="isCurrentImage ? stopDrag : null"
                    @mouseleave="isCurrentImage ? stopDrag : null"
                >
                    <!-- Image Display -->
                    <img
                        v-if="currentImage && isCurrentImage"
                        :src="imageUrl"
                        :alt="currentImage.name || `Image ${currentImage.id}`"
                        :style="{
                            transform: `scale(${imageViewerZoom}) translate(${imageViewerPosition.x / imageViewerZoom}px, ${imageViewerPosition.y / imageViewerZoom}px)`,
                            cursor: imageViewerZoom > 1 ? (isDragging ? 'grabbing' : 'grab') : 'default'
                        }"
                        class="max-h-full max-w-full object-contain transition-transform"
                        @click.stop
                        @dragstart.prevent
                    />
                    
                    <!-- Video Display -->
                    <video
                        v-else-if="currentImage && isCurrentVideo"
                        :src="imageUrl"
                        :alt="currentImage.name || `Video ${currentImage.id}`"
                        class="max-h-full max-w-full object-contain"
                        controls
                        autoplay
                        loop
                        @click.stop
                    >
                        <source :src="imageUrl" type="video/mp4" />
                        Your browser does not support the video tag.
                    </video>
                </div>

                <!-- Left/Right Navigation Arrows -->
                <Button
                    v-if="canGoPrevious"
                    variant="outline"
                    size="lg"
                    class="absolute left-4 top-1/2 transform -translate-y-1/2 z-10 bg-white/10 backdrop-blur-sm hover:bg-white/20 w-12 h-12"
                    @click.stop="goToPrevious"
                >
                    <Icon name="chevronLeft" class="h-6 w-6" />
                </Button>
                
                <Button
                    v-if="canGoNext"
                    variant="outline"
                    size="lg"
                    class="absolute right-4 top-1/2 transform -translate-y-1/2 z-10 bg-white/10 backdrop-blur-sm hover:bg-white/20 w-12 h-12"
                    @click.stop="goToNext"
                >
                    <Icon name="chevronRight" class="h-6 w-6" />
                </Button>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
