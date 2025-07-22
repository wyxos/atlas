<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { Masonry } from '@wyxos/vibe';
import axios from 'axios';
import AudioReactions from '@/components/audio/AudioReactions.vue';
import { useEchoPublic } from '@laravel/echo-vue';
import { Button } from '@/components/ui/button';

interface Item {
    id: number; // Use actual CivitAI numeric ID
    src: string;
    width: number;
    height: number;
    page: string | number;
    index: number;
}

interface Props {
    items: Item[];
    page: number | string | null;
    nextPage: number | string | null;
    hasNextPage: boolean;
    allItemsBlacklisted?: boolean;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Browse',
        href: '/browse',
    },
];
const masonryItems = ref<Item[]>([]);

const masonry = ref(null);

// Download progress tracking
const downloadProgress = ref<Record<number, number>>({});
const downloadedItems = ref<Set<number>>(new Set());

// Setup Echo listener for download progress using useEchoPublic composable for public channel
useEchoPublic(
    'file-download-progress',
    'FileDownloadProgress',
    (e: any) => {
        console.log('Received download progress event:', e);
        downloadProgress.value[e.fileId] = e.progress;

        if (e.progress === 100) {
            downloadedItems.value.add(e.fileId);
            // Remove progress after a delay
            setTimeout(() => {
                delete downloadProgress.value[e.fileId];
            }, 2000);
        }
    }
);

// Unified pagination state - works with both cursor and page-based pagination
const paginationState = ref<{
    page: number | string | null;
    nextPage: number | string | null;
    hasNextPage: boolean;
}>({
    page: props.page,
    nextPage: props.nextPage,
    hasNextPage: props.hasNextPage
});

// Initialize with server-side data
onMounted(() => {
    if (props.items && props.items.length > 0) {
        masonryItems.value = [...props.items];
    }

    // // If all items are blacklisted, trigger next page fetch
    // if (props.allItemsBlacklisted && masonry.value) {
    //     console.log('All items blacklisted, triggering next page fetch');
    //     // Use setTimeout to ensure masonry is fully initialized
    //     setTimeout(() => {
    //         if (masonry.value && typeof masonry.value.loadNext === 'function') {
    //             masonry.value.loadNext();
    //         }
    //     }, 100);
    // }
});

// Download function that starts the download process
const startDownload = async (item: Item) => {
    try {
        await axios.post(
            route('browse.download', { file: item.id }),
        );
        console.log('Download started for item:', item.id);
        downloadProgress.value[item.id] = 0;
    } catch (error) {
        console.error('Failed to start download:', error);
    }
};

// Reaction handlers
const handleFavorite = async (file: any, event: Event) => {
    console.log('Love reaction - starting download:', file.id);

    // Update local state optimistically
    const originalLoved = file.loved;
    file.loved = !file.loved;
    if (file.loved) {
        file.liked = false;
        file.disliked = false;
        file.funny = false;
    }

    try {
        // Persist to backend
        const response = await axios.post(route('audio.love', { file: file.id }));

        // Update with server response
        Object.assign(file, response.data);

        // Start download
        startDownload(file);
    } catch (error) {
        // Revert on error
        file.loved = originalLoved;
        console.error('Failed to toggle love status:', error);
    }
};

const handleLike = async (file: any, event: Event) => {
    console.log('Like reaction - starting download:', file.id);

    // Update local state optimistically
    const originalLiked = file.liked;
    file.liked = !file.liked;
    if (file.liked) {
        file.loved = false;
        file.disliked = false;
        file.funny = false;
    }

    try {
        // Persist to backend
        const response = await axios.post(route('audio.like', { file: file.id }));

        // Update with server response
        Object.assign(file, response.data);

        // Start download
        startDownload(file);
    } catch (error) {
        // Revert on error
        file.liked = originalLiked;
        console.error('Failed to toggle like status:', error);
    }
};

const handleDislike = async (file: any, event: Event) => {
    console.log('Dislike reaction - blacklisting:', file.id);

    // Update local state optimistically
    const originalDisliked = file.disliked;
    file.disliked = !file.disliked;
    if (file.disliked) {
        file.loved = false;
        file.liked = false;
        file.funny = false;
    }

    try {
        // Persist to backend
        const response = await axios.post(route('audio.dislike', { file: file.id }));

        // Update with server response
        Object.assign(file, response.data);
    } catch (error) {
        // Revert on error
        file.disliked = originalDisliked;
        console.error('Failed to toggle dislike status:', error);
    }

    // Blacklist the image
    blacklistImage(file);
};

const handleLaughedAt = async (file: any, event: Event) => {
    console.log('Funny reaction - starting download:', file.id);

    // Update local state optimistically
    const originalFunny = file.funny;
    file.funny = !file.funny;
    if (file.funny) {
        file.loved = false;
        file.liked = false;
        file.disliked = false;
    }

    try {
        // Persist to backend
        const response = await axios.post(route('audio.laughed-at', { file: file.id }));

        // Update with server response
        Object.assign(file, response.data);

        // Start download
        startDownload(file);
    } catch (error) {
        // Revert on error
        file.funny = originalFunny;
        console.error('Failed to toggle funny status:', error);
    }
};

// Mock download function
const downloadImage = (item: Item) => {
    console.log('Downloading image:', item.id, item.src);
    // TODO: Implement actual download functionality
    alert(`Downloading image: ${item.id}`);
};

// Blacklist function - removes the item immediately for better UX
const blacklistImage = async (item: Item) => {
    console.log('Blacklisting image:', item.id);

    // Remove from UI immediately for better user experience
    if(masonry.value){
        masonry.value.onRemove(item);
    }

    try {
        // Call backend to blacklist the item using axios
        await axios.post(
            route('browse.blacklist', { file: item.id }),
            { reason: 'Blacklisted via browse interface' }
        );
        console.log('Item blacklisted successfully:', item.id);
    } catch (error) {
        console.error('Failed to blacklist item:', error);
        // Could optionally show a toast notification here
        // but we don't re-add the item since the user intent was to remove it
    }
};

// Handle Alt+click for download and like
const handleAltClick = (item: Item) => {
    // Start download
    startDownload(item);
    // Also trigger like reaction
    handleLike(item, new Event('click'));
};

// Handle Alt+right-click for blacklist
const handleAltRightClick = (item: Item) => {
    blacklistImage(item);
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
        const queryParams = {
            page: paginationState.value.nextPage
        };

        // Use Inertia to fetch data
        return new Promise((resolve) => {
            router.get(
                route('browse', queryParams),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'hasNextPage', 'nextPage', 'page', 'allItemsBlacklisted'],
                    onSuccess: (response) => {
                        try {
                            const newItems = response.props.items as Item[];
                            const hasNext = response.props.hasNextPage;
                            const nextPage = response.props.nextPage;
                            const currentPage = response.props.page;
                            const allBlacklisted = response.props.allItemsBlacklisted;

                            console.log('Fetched items:', newItems?.length, 'hasNext:', hasNext, 'nextPage:', nextPage, 'currentPage:', currentPage, 'allBlacklisted:', allBlacklisted);

                            // if (newItems && newItems.length > 0) {
                                // Update pagination state - backend provides both current page and nextPage values
                                paginationState.value = {
                                    page: currentPage,
                                    nextPage: hasNext ? nextPage : null,
                                    hasNextPage: hasNext
                                };

                                resolve({
                                    items: newItems,
                                    nextPage: paginationState.value.nextPage
                                });
                            // } else if (allBlacklisted && hasNext) {
                                // All items were blacklisted, but we have more pages - continue fetching
                                // console.log('All items blacklisted, continuing to next page automatically');
                                // paginationState.value = {
                                //     page: currentPage,
                                //     nextPage: hasNext ? nextPage : null,
                                //     hasNextPage: hasNext
                                // };

                                // // Recursively fetch the next page
                                // setTimeout(async () => {
                                //     const nextResult = await getPage(nextPage);
                                //     resolve(nextResult);
                                // }, 100);
                            // } else {
                            //     paginationState.value.hasNextPage = false;
                            //     paginationState.value.nextPage = null;
                            //     resolve({ items: [], nextPage: null });
                            // }
                        } catch (error) {
                            console.error('Error processing response:', error);
                            resolve({ items: [], nextPage: null });
                        }
                    },
                    onError: (errors) => {
                        console.error('Failed to fetch more images:', errors);
                        resolve({ items: [], nextPage: null });
                    }
                }
            );
        });
    } catch (error) {
        console.error('Failed to fetch more images:', error);
        return { items: [], nextPage: null };
    }
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
        <div class="h-screen flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex-shrink-0 p-4 border-b">
                <div class="flex flex-col items-center gap-4">
                    <Button  @click="loadNext()">Next+</Button>
                </div>
            </div>

            <!-- Masonry Container -->
            <div class="flex-1 min-h-0 relative">
                <Masonry
                    v-model:items="masonryItems"
                    :get-next-page="getPage"
                    :skip-initial-load="true"
                    ref="masonry"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 },
                        gutterX: 16,
                        gutterY: 16
                    }"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <div class="relative">
                            <img
                                :src="item.src"
                                :alt="`Image ${item.id}`"
                                class="w-full h-auto cursor-pointer transition-all duration-500 ease-in-out"
                                loading="lazy"
                                @error="(e) => console.warn('Failed to load image:', item.id, e)"
                                @load="() => console.debug('Loaded image:', item.id)"
                                @click.alt.exact.prevent="handleAltClick(item)"
                                @contextmenu.alt.exact.prevent="handleAltRightClick(item)"
                            />

                            <!-- AudioReactions component -->
                            <div class="absolute bottom-2 right-2">
                                <AudioReactions
                                    :file="item"
                                    :icon-size="16"
                                    variant="list"
                                    @favorite="handleFavorite"
                                    @like="handleLike"
                                    @dislike="(file, event) => handleDislike(file, event)"
                                    @laughedAt="handleLaughedAt"
                                />
                            </div>

                            <!-- Download progress bar -->
                            <div v-if="downloadProgress[item.id] !== undefined" class="absolute bottom-0 left-0 right-0 bg-black/50">
                                <div class="bg-blue-500 h-1 transition-all duration-300" :style="{ width: downloadProgress[item.id] + '%' }"></div>
                                <div class="text-white text-xs p-1 text-center">
                                    Downloading... {{ downloadProgress[item.id] }}%
                                </div>
                            </div>

                            <!-- Downloaded indicator -->
                            <div v-if="downloadedItems.has(item.id)" class="absolute top-2 left-2 bg-green-500 text-white px-2 py-1 rounded text-xs">
                                ✓ Downloaded
                            </div>
                        </div>
                    </template>
                </Masonry>

                <!-- Loading Overlay -->
                <div
                    v-if="masonry?.isLoading"
                    class="absolute inset-0 bg-opacity-30 flex items-center justify-center z-50 backdrop-blur-[2px]"
                >
                    <div class="bg-primary rounded-lg p-6 shadow-lg flex items-center gap-3">
                        <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-blue-500"></div>
                        <span class="font-medium text-white">Loading more images...</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
