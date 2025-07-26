<script lang="ts" setup>
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { onMounted, ref } from 'vue';
import BrowseFilters from '@/components/browse/BrowseFilters.vue';
import BrowseItem from '@/components/browse/BrowseItem.vue';
import { useDownloadProgress } from '@/composables/useDownloadProgress';
import { useItemReactions } from '@/composables/useItemReactions';
import { MAX_AUTOCYCLE_ATTEMPTS, AUTOCYCLE_DELAY } from '@/constants/browse';
import type { BrowseProps, BrowseItem as IBrowseItem, BrowseFilters as IBrowseFilters, PaginationState } from '@/types/browse';

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
const autoNext = ref(false);

// Filter state management
const currentFilters = ref<IBrowseFilters>({
    sort: props.filters.sort,
    period: props.filters.period,
    nsfw: props.filters.nsfw,
});

// Unified pagination state - works with both cursor and page-based pagination
const paginationState = ref<PaginationState>({
    page: props.page,
    nextPage: props.nextPage,
    hasNextPage: props.hasNextPage,
});

// Use composables
const { downloadProgress, downloadedItems } = useDownloadProgress();
const { 
    startDownload,
    handleFavorite, 
    handleLike, 
    handleDislike, 
    handleLaughedAt, 
    blacklistImage 
} = useItemReactions();

// Initialize with server-side data
onMounted(() => {
    if (props.items && props.items.length > 0) {
        masonryItems.value = [...props.items];
    } else if (autoNext.value && paginationState.value.hasNextPage && paginationState.value.nextPage && !isAutocycling.value) {
        // Automatically trigger next page if auto next is enabled
        autocycleUntilItems();
    }
});

// Remove item from masonry view
const removeItemFromView = (item: IBrowseItem) => {
    if (masonry.value && typeof masonry.value.onRemove === 'function') {
        masonry.value.onRemove(item);
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
    blacklistImage(item, masonry.value);
};

// Autocycle function - uses masonry's loadNext method repeatedly until items are found
const autocycleUntilItems = async (): Promise<void> => {
    isAutocycling.value = true;

    let attempts = 0;
    const initialItemCount = masonryItems.value.length;

    try {
        while (attempts < MAX_AUTOCYCLE_ATTEMPTS && paginationState.value.hasNextPage && paginationState.value.nextPage) {
            attempts++;
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
        console.log('Masonry requesting:', pageParam, 'current state:', paginationState.value);

        // If there's no next page to fetch, return empty
        if (!paginationState.value.hasNextPage || !paginationState.value.nextPage) {
            console.log('No more pages to fetch');
            return { items: [], nextPage: null };
        }

        // Use the nextPage value directly - backend determines if it's cursor or page number
        // Include current filters to maintain consistency
        const queryParams = {
            page: paginationState.value.nextPage,
            sort: currentFilters.value.sort,
            period: currentFilters.value.period,
            nsfw: currentFilters.value.nsfw.toString(),
        };

        // Use Inertia to fetch data
        return new Promise((resolve) => {
            router.get(
                route('browse', queryParams),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'hasNextPage', 'nextPage', 'page'],
                    onSuccess: (response) => {
                        try {
                            const newItems = response.props.items as IBrowseItem[];
                            const hasNext = response.props.hasNextPage;
                            const nextPage = response.props.nextPage;
                            const currentPage = response.props.page;

                            console.log('Fetched items:', newItems?.length, 'hasNext:', hasNext, 'nextPage:', nextPage, 'currentPage:', currentPage);

                            paginationState.value = {
                                page: currentPage,
                                nextPage: hasNext ? nextPage : null,
                                hasNextPage: hasNext,
                            };

                            // Check if we should auto cycle after getting empty results
                            if (!isAutocycling.value && newItems.length === 0 && hasNext && nextPage && autoNext.value) {
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
    console.log('NSFW checkbox changed to:', checked);
    currentFilters.value.nsfw = checked;
    console.log('Current filters after change:', currentFilters.value);
    applyFilters();
};

// Apply filters by navigating to the browse page with new parameters (no page parameter to go to page 1)
const applyFilters = () => {
    const queryParams = {
        sort: currentFilters.value.sort,
        period: currentFilters.value.period,
        nsfw: currentFilters.value.nsfw.toString(),
    };

    console.log('Applying filters with query params:', queryParams);
    console.log('Route URL will be:', route('browse', queryParams));

    router.get(
        route('browse', queryParams),
        {},
        {
            preserveState: false, // Don't preserve state to get fresh data
            preserveScroll: false, // Don't preserve scroll to go back to top
        },
    );
};

// Load next page of images
const loadNext = async () => {
    if (masonry.value && typeof masonry.value.loadNext === 'function') {
        console.log('Loading next page of images');
        await masonry.value.loadNext();
    } else {
        console.warn('Masonry component not ready or loadNext function not available');
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
                        :auto-next="autoNext"
                        @sort-change="handleSortChange"
                        @period-change="handlePeriodChange"
                        @nsfw-change="handleNsfwChange"
                        @auto-next-change="(value) => autoNext = value"
                        @load-next="loadNext"
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
                    :skip-initial-load="true"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <BrowseItem
                            :item="item"
                            :download-progress="downloadProgress[item.id]"
                            :is-downloaded="downloadedItems.has(item.id)"
                            @favorite="(file, event) => handleFavorite(file, event, removeItemFromView)"
                            @like="(file, event) => handleLike(file, event, removeItemFromView)"
                            @dislike="(file, event) => handleDislike(file, event, (item) => blacklistImage(item, masonry))"
                            @laughed-at="(file, event) => handleLaughedAt(file, event, removeItemFromView)"
                            @alt-click="handleAltClick"
                            @alt-right-click="handleAltRightClick"
                        />
                    </template>
                </Masonry>

                <!-- Loading Overlay -->
                <div
                    v-if="masonry?.isLoading || isAutocycling"
                    class="bg-opacity-30 absolute inset-0 z-50 flex items-center justify-center backdrop-blur-[2px]"
                >
                    <div class="flex items-center gap-3 rounded-lg bg-primary p-6 shadow-lg">
                        <div class="h-6 w-6 animate-spin rounded-full border-b-2 border-blue-500"></div>
                        <span class="font-medium text-white">
                            {{ isAutocycling ? 'Finding available items...' : 'Loading more images...' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
