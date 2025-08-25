<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import BrowseFilters from '@/components/browse/BrowseFilters.vue';
import BrowseItem from '@/components/browse/BrowseItem.vue';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast/use-toast';
import useContextMenu from '@/composables/useContextMenu';
import { useDownloadProgress } from '@/composables/useDownloadProgress';
import { useImageZoom } from '@/composables/useImageZoom';
import { useItemReactions } from '@/composables/useItemReactions';
import { useMetadataUpdates } from '@/composables/useMetadataUpdates';
import { useSeenStatus } from '@/composables/useSeenStatus';
import { AUTOCYCLE_DELAY, MAX_AUTOCYCLE_ATTEMPTS } from '@/constants/browse';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { BrowseProps, BrowseFilters as IBrowseFilters, BrowseItem as IBrowseItem, PaginationState } from '@/types/browse';
import { Head, router } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
    limit: props.filters.limit,
    nsfw: props.filters.nsfw,
    autoNext: props.filters.autoNext,
    container: props.filters.container,
});

// Unified pagination state - works with both cursor and page-based pagination
const paginationState = ref<PaginationState>({
    page: props.filters.page,
    nextPage: props.filters.nextPage,
});

// Use composables
const { downloadProgress, downloadedItems } = useDownloadProgress();
const { updatedMetadata, updatedListingMetadata } = useMetadataUpdates();
const { handleFavorite, handleLike, handleDislike, handleLaughedAt, blacklistImage, undoLastBlacklist } = useItemReactions();
const { markAsSeen } = useSeenStatus();
const { handleContextMenu } = useContextMenu();
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
    removeCurrentAndGoNext,
} = useImageZoom();

// Remove item from masonry view
const removeItemFromView = async (item: IBrowseItem) => {
    if (masonry.value && typeof masonry.value.onRemove === 'function') {
        masonry.value.onRemove(item);

        // Check if we need to load more items after removal
        // Use a small delay to allow the masonry to update its internal state
        setTimeout(async () => {
            try {
                // Check if there are no visible items left and more pages are available
                if (masonryItems.value.length === 0 && paginationState.value.nextPage) {
                    // If auto next is enabled, trigger autocycle, otherwise just load next page
                    if (currentFilters.value.autoNext) {
                        await autocycleUntilItems();
                    } else {
                        await loadNext();
                    }
                }
            } catch (error) {
                console.error('Error auto-loading after item removal:', error);
            }
        }, 500); // Small delay to allow masonry state to update
    }
};

// Simplified reaction handlers that include removal
const handleItemFavorite = (item: IBrowseItem, event: Event) => {
    handleFavorite(item, event, removeItemFromView);
};

const handleItemLike = (item: IBrowseItem, event: Event) => {
    handleLike(item, event, removeItemFromView);
};

const handleItemDislike = (item: IBrowseItem, event: Event) => {
    handleDislike(item, event, (item) => blacklistImage(item, removeItemFromView));
};

const handleItemLaughedAt = (item: IBrowseItem, event: Event) => {
    handleLaughedAt(item, event, removeItemFromView);
};

// Handle Alt+click for download and like
const handleAltClick = (item: IBrowseItem) => {
    // Trigger like reaction with removal callback (also starts download internally)
    handleLike(item, new Event('click'), removeItemFromView);
};

// Handle Alt+middle-click for download and favorite/love
const handleAltMiddleClick = (item: IBrowseItem) => {
    // Trigger favorite reaction with removal callback (also starts download internally)
    handleFavorite(item, new Event('click'), removeItemFromView);
};

// Handle Alt+right-click for blacklist
const handleAltRightClick = (item: IBrowseItem) => {
    blacklistImage(item, removeItemFromView);
};

// Handle left click for single image view
const handleLeftClick = (item: IBrowseItem) => {
    // Open image viewer with full list for navigation
    openImageViewer(item, masonryItems.value);
};

// Handle right click for context menu
const handleRightClick = (event: MouseEvent, item: IBrowseItem) => {
    event.preventDefault();
    handleContextMenu(
        event,
        {
            handler: 'browse-list',
            // Include postId and username for menu conditions
            item: {
                id: item.id,
                name: `File ${item.id}`,
                postId: item?.listingMetadata?.postId,
                username: item?.listingMetadata?.username,
            },
        },
        undefined,
    ); // Add endpoint for browse list context menu data
};

// Reaction handlers for full screen mode
const handleFullScreenFavorite = (item: IBrowseItem, event: Event) => {
    // Immediately advance to next item for responsive UI
    removeCurrentAndGoNext();
    removeItemFromView(item);

    // Handle the reaction in the background (already optimistic)
    handleFavorite(item, event);
};

const handleFullScreenLike = (item: IBrowseItem, event: Event) => {
    // Immediately advance to next item for responsive UI
    removeCurrentAndGoNext();
    removeItemFromView(item);

    // Handle the reaction in the background (already optimistic)
    handleLike(item, event);
};

const handleFullScreenDislike = (item: IBrowseItem, event: Event) => {
    // Immediately advance to next item for responsive UI
    removeCurrentAndGoNext();
    removeItemFromView(item);

    // Handle the reaction in the background
    handleDislike(item, event, () => {
        // Blacklist is already handled by dislike
    });
};

const handleFullScreenLaughedAt = (item: IBrowseItem, event: Event) => {
    // Immediately advance to next item for responsive UI
    removeCurrentAndGoNext();
    removeItemFromView(item);

    // Handle the reaction in the background (already optimistic)
    handleLaughedAt(item, event);
};

// Alt+Click shortcuts for full screen mode
const handleFullScreenAltClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value;

        // Immediately advance to next item for responsive UI
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);

        // Handle the reaction in the background (like triggers download internally)
        handleLike(itemToProcess, new Event('click'));
    }
};

const handleFullScreenAltMiddleClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value;

        // Immediately advance to next item for responsive UI
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);

        // Handle the reaction in the background (favorite triggers download internally)
        handleFavorite(itemToProcess, new Event('click'));
    }
};

const handleFullScreenAltRightClick = () => {
    if (currentImage.value) {
        const itemToProcess = currentImage.value;

        // Immediately advance to next item for responsive UI
        removeCurrentAndGoNext();
        removeItemFromView(itemToProcess);

        // Handle the blacklist in the background
        blacklistImage(itemToProcess);
    }
};

// Autocycle function - uses masonry's loadNext method repeatedly until it finds unpreviewed items based on current limit
const autocycleUntilItems = async (initialUnpreviewedCount: number = 0): Promise<void> => {
    isAutocycling.value = true;
    autocycleAttempts.value = 0;

    let attempts = 0;
    let totalUnpreviewedItems = initialUnpreviewedCount;
    const targetUnpreviewedItems = currentFilters.value.limit;

    try {
        while (attempts < MAX_AUTOCYCLE_ATTEMPTS && paginationState.value.nextPage && totalUnpreviewedItems < targetUnpreviewedItems) {
            attempts++;
            autocycleAttempts.value = attempts;

            if (masonry.value && typeof masonry.value.loadNext === 'function') {
                try {
                    // Get the response from loadNext which internally calls getPage
                    const response = await masonry.value.loadNext();

                    // Check the items from the API response, not the masonry items
                    if (response && response.items && response.items.length > 0) {
                        const unpreviewedItems = response.items.filter((item) => item.seen_preview_at === null);
                        totalUnpreviewedItems += unpreviewedItems.length;

                        // Continue cycling until we have enough unpreviewed items
                        if (totalUnpreviewedItems >= targetUnpreviewedItems) {
                            break; // Stop cycling, we have enough unpreviewed items
                        }
                        // Otherwise continue to next iteration
                    } else {
                        // If no items returned but nextPage still exists, continue
                        // If no more pages, the while condition will break the loop
                    }
                } catch (loadError) {
                    console.error('Error loading next page during autocycle:', loadError);
                    // Set autoNext to false if loadNext fails during autocycle
                    currentFilters.value.autoNext = false;
                    // Break the autocycle loop on error to avoid infinite retries
                    break;
                }

                // Small delay to prevent overwhelming the API
                await new Promise((resolve) => setTimeout(resolve, AUTOCYCLE_DELAY));
            } else {
                break;
            }
        }
    } catch (error) {
        console.error('Error in autocycleUntilItems:', error);
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
            limit: currentFilters.value.limit,
            nsfw: currentFilters.value.nsfw,
            autoNext: currentFilters.value.autoNext,
            container: currentFilters.value.container,
            search: 1,
        };

        // Use Inertia to fetch data
        return new Promise((resolve, reject) => {
            router.get(
                route('browse.data', queryParams),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'filters'],
                    onSuccess: (response) => {
                        const newItems = response.props.items as IBrowseItem[];
                        const filters = response.props.filters as IBrowseFilters;
                        const nextPage = filters.nextPage;
                        const currentPage = filters.page;

                        paginationState.value = {
                            page: currentPage,
                            nextPage: nextPage,
                        };

                        if (nextPage) {
                            const allNewItemsSeen = newItems.length > 0 && newItems.every((item) => item.seen_preview_at !== null);

                            // Check if we should auto cycle
                            if (
                                !isAutocycling.value &&
                                nextPage &&
                                currentFilters.value.autoNext &&
                                (newItems.length < currentFilters.value.limit || allNewItemsSeen)
                            ) {
                                const unpreviewedInNewItems = newItems.filter((item) => item.seen_preview_at === null).length;
                                // Automatically trigger autocycling if we have less items than the limit, or all items have been seen
                                setTimeout(() => autocycleUntilItems(unpreviewedInNewItems), 500);
                            }

                            resolve({
                                items: newItems,
                                nextPage: paginationState.value.nextPage,
                            });
                        } else {
                            reject(new Error('No more pages available'));
                        }
                    },
                    onError: (errors) => {
                        console.error('Error fetching page data:', errors);
                        reject(new Error('Failed to fetch page data'));
                    },
                },
            );
        });
    } catch (error) {
        console.error('Error in getPage:', error);
        // Explicitly throw the error
        throw new Error('Failed to load page data.');
    }
};

// Filter change handlers - navigate back to page 1 when filters change
const handleContainerChange = (newContainer: string) => {
    currentFilters.value.container = newContainer;
    applyFilters();
};

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
    // If autoNext is enabled, trigger next page load
    if (checked) {
        loadNext();
    }
};

const handleLimitChange = (newLimit: number) => {
    currentFilters.value.limit = newLimit;
    applyFilters();
};

const handleBackToFirst = () => {
    applyFilters();
};

// Apply filters by navigating to the browse page with new parameters (no page parameter to go to page 1)
const applyFilters = () => {
    paginationState.value.page = null;
    paginationState.value.nextPage = null;

    masonry.value?.reset();
    masonry.value?.loadPage(null);
};

// Load next page of images
const loadNext = async () => {
    if (masonry.value && typeof masonry.value.loadNext === 'function') {
        try {
            await masonry.value.loadNext();
        } catch (error) {
            console.error('Error loading next page:', error);
            // Set autoNext to false if loadNext fails
            currentFilters.value.autoNext = false;
        }
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

// Global blockers for browser back/forward mouse buttons while fullscreen viewer is open
const globalAuxMouseBlocker = (event: MouseEvent) => {
    if (!isImageViewerOpen.value) return;
    const btn = (event as any).button;
    if (btn === 3 || btn === 4) {
        event.preventDefault?.();
        event.stopImmediatePropagation?.();
    }
};

onMounted(() => {
    // Capture phase to intercept before browser handles navigation
    window.addEventListener('mousedown', globalAuxMouseBlocker, { capture: true });
    window.addEventListener('mouseup', globalAuxMouseBlocker, { capture: true });
    window.addEventListener('auxclick', globalAuxMouseBlocker, { capture: true } as any);
});

onBeforeUnmount(() => {
    window.removeEventListener('mousedown', globalAuxMouseBlocker, { capture: true } as any);
    window.removeEventListener('mouseup', globalAuxMouseBlocker, { capture: true } as any);
    window.removeEventListener('auxclick', globalAuxMouseBlocker, { capture: true } as any);
});

// Handle mouse button navigation in full screen mode
const handleMouseNavigation = (event: MouseEvent) => {
    // Normalize handling for auxiliary buttons to avoid browser navigation
    // Mouse button 3 = back button (previous)
    // Mouse button 4 = forward button (next)

    const isBackButton = event.button === 3;
    const isForwardButton = event.button === 4;

    if (isBackButton || isForwardButton) {
        // Prevent default browser back/forward behavior for aux buttons
        event.preventDefault?.();
        event.stopPropagation?.();
    }

    // Alt + previous mouse button (button 3) => Block post of current image
    if (isBackButton && event.altKey && currentImage.value?.listingMetadata?.postId) {
        const postId = currentImage.value.listingMetadata.postId;
        window?.dispatchEvent?.(new CustomEvent('browse:block-post', { detail: { postId } }));
        return;
    }

    // Previous image
    if (isBackButton && canGoPrevious.value) {
        goToPrevious();
        return;
    }

    // Alt + next mouse button (button 4) => Like post of current image
    if (isForwardButton && event.altKey && currentImage.value?.listingMetadata?.postId) {
        const postId = currentImage.value.listingMetadata.postId;
        window?.dispatchEvent?.(new CustomEvent('browse:like-post', { detail: { postId } }));
        return;
    }

    // Next image
    if (isForwardButton && canGoNext.value) {
        goToNext();
        return;
    }
};

// Track full screen view status for marking as viewed
const viewedFileIds = ref(new Set<number>());

// Watch for changes in the current image to mark as viewed
watch(
    currentImage,
    async (newImage) => {
        if (newImage && !newImage.seen_file_at && !viewedFileIds.value.has(newImage.id)) {
            viewedFileIds.value.add(newImage.id);
            const response = await markAsSeen(newImage.id, 'file');

            // Find the item in the masonry list and update it to trigger reactivity
            const itemIndex = masonryItems.value.findIndex((item) => item.id === newImage.id);
            if (itemIndex !== -1) {
                masonryItems.value[itemIndex] = {
                    ...masonryItems.value[itemIndex],
                    seen_file_at: response.timestamp,
                };
            }
        }
    },
    { immediate: true },
);

// Watch for metadata updates and update the masonry items
watch(
    updatedMetadata,
    (newMetadata) => {
        for (const fileId in newMetadata) {
            const itemIndex = masonryItems.value.findIndex((item) => item.id === parseInt(fileId));
            if (itemIndex !== -1) {
                masonryItems.value[itemIndex] = {
                    ...masonryItems.value[itemIndex],
                    metadata: newMetadata[fileId],
                };
            }

            // Also update the currentImage if it matches the updated file
            if (currentImage.value && currentImage.value.id === parseInt(fileId)) {
                currentImage.value = {
                    ...currentImage.value,
                    metadata: newMetadata[fileId],
                };
            }
        }
    },
    { deep: true },
);

// Compute counts for same postId and same username
const postCounts = computed(() => {
    const map = new Map<string | number, number>();
    for (const i of masonryItems.value) {
        const postId = i?.listingMetadata?.postId;
        if (postId) {
            map.set(postId, (map.get(postId) || 0) + 1);
        }
    }
    return map;
});

const userCounts = computed(() => {
    const map = new Map<string, number>();
    for (const i of masonryItems.value) {
        const username = i?.listingMetadata?.username;
        if (username) {
            map.set(username, (map.get(username) || 0) + 1);
        }
    }
    return map;
});

const getPostRelatedCount = (item: IBrowseItem): number => {
    const postId = item?.listingMetadata?.postId;
    if (!postId) return 0;
    const total = postCounts.value.get(postId) || 0;
    return Math.max(0, total); // exclude this item
};

const getUserRelatedCount = (item: IBrowseItem): number => {
    const username = item?.listingMetadata?.username;
    if (!username) return 0;
    const total = userCounts.value.get(username) || 0;
    return Math.max(0, total); // exclude this item
};

// Hover focus logic (instant) triggered by post count badge
const blurActiveId = ref<number | null>(null);

const handlePostBadgeEnter = (item: IBrowseItem) => {
    blurActiveId.value = item.id;
};

const handlePostBadgeLeave = () => {
    blurActiveId.value = null;
};

const getItemById = (id: number | null): IBrowseItem | null => {
    if (id == null) return null;
    return masonryItems.value.find((i) => i.id === id) ?? null;
};

const hasRelatedFor = (item: IBrowseItem | null): boolean => {
    if (!item) return false;
    const postCount = getPostRelatedCount(item);
    // Only activate on post relationships
    return postCount > 1;
};

const isRelatedToActive = (item: IBrowseItem): boolean => {
    const active = getItemById(blurActiveId.value);
    if (!active) return false;
    if (active.id === item.id) return true;
    const samePost = active?.listingMetadata?.postId && active.listingMetadata.postId === item?.listingMetadata?.postId;
    return Boolean(samePost);
};

const shouldBlur = (item: IBrowseItem): boolean => {
    const active = getItemById(blurActiveId.value);
    if (!active) return false; // not active yet
    // Only activate blur mode if the active item has related images (by post)
    if (!hasRelatedFor(active)) return false;
    return !isRelatedToActive(item);
};

// Watch for listing metadata updates and update masonry items

// Handle "Block post" context action dispatched from AppLayout
window.addEventListener('browse:block-post', async (e: any) => {
    const postId = e?.detail?.postId;
    if (!postId) return;

    // Gather current items with the same postId
    const toRemove = masonryItems.value.filter((i) => i?.listingMetadata?.postId === postId);
    if (toRemove.length === 0) return;

    const count = toRemove.length;

    // Optimistically remove from UI first (batch if supported)
    if (masonry.value && typeof masonry.value.removeMany === 'function') {
        await masonry.value.removeMany(toRemove);
    } else {
        for (const item of toRemove) {
            await removeItemFromView(item);
        }
    }

    try {
        await axios.post(route('browse.block-post'), {
            postId,
            fileIds: toRemove.map((i) => i.id),
        });

        toast({ title: 'Post blocked', description: `${count} item${count > 1 ? 's' : ''} removed.` });
    } catch (err) {
        console.error('Failed to block post:', err);
        toast({ title: 'Failed to block post', description: 'They were removed locally; server update failed.' });
    }
});

// Handle "Like Post" context action dispatched from AppLayout
window.addEventListener('browse:like-post', async (e: any) => {
    const postId = e?.detail?.postId;
    if (!postId) return;

    // Collect current items with same postId
    const toProcess = masonryItems.value.filter((i) => i?.listingMetadata?.postId === postId);
    if (toProcess.length === 0) return;

    const count = toProcess.length;

    // Optimistically remove from UI first (batch if supported)
    if (masonry.value && typeof masonry.value.removeMany === 'function') {
        await masonry.value.removeMany(toProcess);
    } else {
        for (const item of toProcess) {
            await removeItemFromView(item);
        }
    }

    try {
        await axios.post(route('browse.like-post'), {
            postId,
            fileIds: toProcess.map((i) => i.id),
            delayMs: 200,
        });
        toast({ title: 'Post liked', description: `${count} item${count > 1 ? 's' : ''} liked and queued for download.` });
    } catch (err) {
        console.error('Failed to like post:', err);
        toast({ title: 'Failed to like post', description: 'They were removed locally; server update failed.' });
    }
});

// Handle "Block user" context action dispatched from AppLayout
window.addEventListener('browse:block-user', async (e: any) => {
    const username = e?.detail?.username;
    if (!username) return;

    // Gather current items with the same username
    const toRemove = masonryItems.value.filter((i) => i?.listingMetadata?.username === username);
    if (toRemove.length === 0) return;

    const count = toRemove.length;

    // Optimistically remove from UI first (batch if supported)
    if (masonry.value && typeof masonry.value.removeMany === 'function') {
        await masonry.value.removeMany(toRemove);
    } else {
        for (const item of toRemove) {
            await removeItemFromView(item);
        }
    }

    try {
        // Backend may expose this route; if not, this will fail silently after local removal
        await axios.post(route('browse.block-user'), {
            username,
            fileIds: toRemove.map((i) => i.id),
        });
        toast({ title: 'User blocked', description: `${username} • ${count} item${count > 1 ? 's' : ''} removed.` });
    } catch (err) {
        console.error('Failed to block user (server):', err);
        toast({ title: 'User blocked locally', description: `${count} item${count > 1 ? 's' : ''} removed from view.` });
    }
});

watch(
    updatedListingMetadata,
    (newListing) => {
        for (const fileId in newListing) {
            const itemIndex = masonryItems.value.findIndex((item) => item.id === parseInt(fileId));
            if (itemIndex !== -1) {
                masonryItems.value[itemIndex] = {
                    ...masonryItems.value[itemIndex],
                    listingMetadata: newListing[fileId],
                };
            }

            if (currentImage.value && currentImage.value.id === parseInt(fileId)) {
                currentImage.value = {
                    ...currentImage.value,
                    listingMetadata: newListing[fileId],
                };
            }
        }
    },
    { deep: true },
);
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
                        @container-change="handleContainerChange"
                        @sort-change="handleSortChange"
                        @period-change="handlePeriodChange"
                        @limit-change="handleLimitChange"
                        @nsfw-change="handleNsfwChange"
                        @auto-next-change="handleAutoNextChange"
                        @back-to-first="handleBackToFirst"
                        @load-next="loadNext"
                        @undo-blacklist="handleUndoBlacklist"
                    />
                </div>
            </div>

            <p>{{ masonry?.paginationHistory }}</p>

            <!-- Masonry Container -->
            <div class="relative min-h-0 flex-1">
                <Masonry
                    ref="masonry"
                    v-model:items="masonryItems"
                    :get-next-page="getPage"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 3, xl: 5, '2xl': 8 },
                        footer: 32,
                    }"
                    :load-at-page="props.filters.page"
                    :max-items="150"
                    class="h-full"
                >
                    <template #item="{ item }">
<div
                            :class="['transition duration-150', shouldBlur(item) ? 'blur-[1px] blur-md' : '']"
                        >
                            <BrowseItem
                                :download-progress="downloadProgress[item.id]"
                                :is-downloaded="downloadedItems.has(item.id)"
                                :is-loading="masonry?.isLoading || isAutocycling"
                                :item="item"
                                :page-size="currentFilters.limit"
                                :post-related-count="getPostRelatedCount(item)"
                                :user-related-count="getUserRelatedCount(item)"
                                @contextmenu="(event) => handleRightClick(event, item)"
                                @dislike="handleItemDislike"
                                @favorite="handleItemFavorite"
                                @like="handleItemLike"
                                @laughed-at="handleItemLaughedAt"
                                @alt-click="handleAltClick"
                                @alt-middle-click="handleAltMiddleClick"
                                @alt-right-click="handleAltRightClick"
                                @left-click="handleLeftClick"
                                @post-badge-enter="handlePostBadgeEnter"
                                @post-badge-leave="handlePostBadgeLeave"
                            />
                        </div>
                    </template>
                </Masonry>

                <!-- Loading Popup (without background mask) -->
                <div v-if="masonry?.isLoading || isAutocycling" class="pointer-events-none absolute inset-0 z-50 flex items-center justify-center">
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
            class="fixed inset-0 z-50 flex bg-black/90"
            tabindex="0"
            @click="closeImageViewer"
            @mousedown.prevent.stop="handleMouseNavigation"
            @mouseup.prevent.stop="handleMouseNavigation"
            @auxclick.prevent.stop="handleMouseNavigation"
            @keydown.escape="closeImageViewer"
            @keydown.left="goToPrevious"
            @keydown.right="goToNext"
        >
            <!-- Main Content Area -->
            <div class="flex flex-1 flex-col">
                <!-- Top gutter -->
                <div class="flex h-16 flex-shrink-0 items-center justify-between px-4">
                    <!-- Zoom Controls (only for images) -->
                    <div v-if="isCurrentImage" class="flex gap-2">
                        <Button class="bg-white/10 backdrop-blur-sm hover:bg-white/20" size="sm" variant="outline" @click.stop="zoomOut">
                            <Icon class="h-4 w-4" name="zoomOut" />
                        </Button>
                        <Button class="bg-white/10 backdrop-blur-sm hover:bg-white/20" size="sm" variant="outline" @click.stop="resetZoom">
                            <Icon class="h-4 w-4" name="maximize" />
                        </Button>
                        <Button class="bg-white/10 backdrop-blur-sm hover:bg-white/20" size="sm" variant="outline" @click.stop="zoomIn">
                            <Icon class="h-4 w-4" name="zoomIn" />
                        </Button>
                        <!-- Zoom Level Indicator -->
                        <div class="rounded bg-white/10 px-2 py-1 text-sm text-white backdrop-blur-sm">{{ Math.round(imageViewerZoom * 100) }}%</div>
                    </div>
                    <div v-else></div>
                    <!-- Empty div for spacing when no zoom controls -->

                    <!-- Navigation Controls -->
                    <div class="flex gap-2">
                        <Button
                            v-if="canGoPrevious"
                            class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                            size="sm"
                            variant="outline"
                            @click.stop="goToPrevious"
                        >
                            <Icon class="h-4 w-4" name="chevronLeft" />
                            Previous
                        </Button>
                        <div class="rounded bg-white/10 px-3 py-2 text-sm text-white backdrop-blur-sm">
                            {{ currentIndex + 1 }} / {{ allImages.length }}
                        </div>
                        <Button
                            v-if="canGoNext"
                            class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                            size="sm"
                            variant="outline"
                            @click.stop="goToNext"
                        >
                            Next
                            <Icon class="h-4 w-4" name="chevronRight" />
                        </Button>
                    </div>

                    <!-- Close Button -->
                    <Button class="bg-white/10 backdrop-blur-sm hover:bg-white/20" size="sm" variant="outline" @click.stop="closeImageViewer">
                        <Icon class="h-4 w-4" name="x" />
                    </Button>
                </div>

                <!-- Media Container -->
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
                        <!-- Image Display -->
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

                        <!-- Video Display -->
                        <video
                            v-else-if="currentImage && isCurrentVideo"
                            :alt="currentImage.name || `Video ${currentImage.id}`"
                            :src="imageUrl"
                            autoplay
                            class="max-h-full max-w-full object-contain"
                            controls
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
                        class="absolute top-1/2 left-4 z-10 h-12 w-12 -translate-y-1/2 transform bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        size="lg"
                        variant="outline"
                        @click.stop="goToPrevious"
                    >
                        <Icon class="h-6 w-6" name="chevronLeft" />
                    </Button>

                    <Button
                        v-if="canGoNext"
                        class="absolute top-1/2 right-4 z-10 h-12 w-12 -translate-y-1/2 transform bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        size="lg"
                        variant="outline"
                        @click.stop="goToNext"
                    >
                        <Icon class="h-6 w-6" name="chevronRight" />
                    </Button>
                </div>

                <!-- Bottom gutter with reactions -->
                <div class="flex h-16 flex-shrink-0 items-center justify-center px-4" @click.stop>
                    <div v-if="currentImage" class="rounded-lg bg-white/10 p-3 backdrop-blur-sm">
                        <FileReactions
                            :file="currentImage"
                            :icon-size="20"
                            variant="player"
                            @dislike="(file, event) => handleFullScreenDislike(file, event)"
                            @favorite="(file, event) => handleFullScreenFavorite(file, event)"
                            @laughedAt="(file, event) => handleFullScreenLaughedAt(file, event)"
                            @like="(file, event) => handleFullScreenLike(file, event)"
                        />
                    </div>
                </div>
            </div>

            <!-- Metadata Panel -->
            <div v-if="currentImage && currentImage.metadata" class="w-80 flex-shrink-0 overflow-y-auto bg-black/80" @click.stop>
                <div class="p-4">
                    <h3 class="mb-3 text-sm font-medium text-white">Metadata</h3>
                    <p>{{ currentImage.id }}</p>
                    <pre v-if="currentImage.listingMetadata?.data?.meta?.prompt" class="text-xs whitespace-pre-wrap text-gray-300">{{
                        JSON.stringify(currentImage.listingMetadata.data.meta.prompt, null, 2)
                    }}</pre>
                    <pre v-else class="text-xs whitespace-pre-wrap text-gray-300">{{
                        JSON.stringify(currentImage.listingMetadata ?? currentImage.metadata, null, 2)
                    }}</pre>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
