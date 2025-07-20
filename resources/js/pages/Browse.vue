<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { Masonry } from '@wyxos/vibe';

interface DemoItem {
    id: number; // Use actual CivitAI numeric ID
    src: string;
    width: number;
    height: number;
    page: string | number;
    index: number;
    meta?: {
        model_name?: string;
        model_id?: number;
        version_name?: string;
        blurhash?: string;
    };
}

interface Props {
    items: DemoItem[];
    currentPage: number | string;
    hasNextPage: boolean;
    nextCursor: string | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Browse',
        href: '/browse',
    },
];

const items = ref<DemoItem[]>([]);
const masonry = ref(null);
const isLoading = ref(false);
let nextCursorToFetch: string | null = null; // Track next cursor to fetch

// Initialize with server-side data
onMounted(() => {
    if (props.items && props.items.length > 0) {
        items.value = [...props.items];
        // Set next cursor based on whether we have more pages
        nextCursorToFetch = props.hasNextPage ? props.nextCursor : null;
    }
});

// Mock download function
const downloadImage = (item: DemoItem) => {
    console.log('Downloading image:', item.id, item.src);
    // TODO: Implement actual download functionality
    alert(`Downloading image: ${item.id}`);
};

// Blacklist function - removes the item
const blacklistImage = (item: DemoItem, onRemove: Function) => {
    console.log('Blacklisting image:', item.id);
    onRemove(item);
};

// Handle image click events
const handleImageClick = (event: MouseEvent, item: DemoItem, onRemove: Function) => {
    if (event.altKey && event.button === 0) { // Alt + Left click
        event.preventDefault();
        downloadImage(item);
    }
};

// Handle image context menu (right click)
const handleImageContextMenu = (event: MouseEvent, item: DemoItem, onRemove: Function) => {
    if (event.altKey) { // Alt + Right click
        event.preventDefault();
        blacklistImage(item, onRemove);
    }
};

// Fetch more images for infinite scroll
const getPage = async (page: number) => {
    try {
        console.log('Masonry requesting page:', page, 'using cursor:', nextCursorToFetch);

        // If there's no next cursor to fetch, return empty
        if (!nextCursorToFetch) {
            console.log('No more pages to fetch');
            return { items: [], nextPage: null };
        }

        // Set loading state
        isLoading.value = true;

        // Use Inertia to navigate with cursor and get data
        return new Promise((resolve, reject) => {
            router.get(
                route('browse', { cursor: nextCursorToFetch }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['items', 'hasNextPage', 'nextCursor'],
                    onSuccess: (response) => {
                        try {
                            const newItems = response.props.items as DemoItem[];
                            const hasNext = response.props.hasNextPage;
                            const nextCursor = response.props.nextCursor;

                            console.log('Fetched items:', newItems?.length, 'hasNext:', hasNext, 'nextCursor:', nextCursor);

                            if (newItems && newItems.length > 0) {
                                // Update next cursor to fetch
                                nextCursorToFetch = hasNext ? nextCursor : null;

                                resolve({
                                    items: newItems,
                                    nextPage: hasNext ? nextCursor : null
                                });
                            } else {
                                nextCursorToFetch = null;
                                resolve({ items: [], nextPage: null });
                            }
                        } catch (error) {
                            console.error('Error processing response:', error);
                            resolve({ items: [], nextPage: null });
                        } finally {
                            // Clear loading state
                            isLoading.value = false;
                        }
                    },
                    onError: (errors) => {
                        console.error('Failed to fetch more images:', errors);
                        isLoading.value = false;
                        resolve({ items: [], nextPage: null });
                    }
                }
            );
        });
    } catch (error) {
        console.error('Failed to fetch more images:', error);
        isLoading.value = false;
        return { items: [], nextPage: null };
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
                </div>
            </div>

            <!-- Masonry Container -->
            <div class="flex-1 min-h-0 relative">
                <Masonry
                    v-model:items="items"
                    :get-next-page="getPage"
                    ref="masonry"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 4, xl: 5, '2xl': 6 },
                        gutterX: 16,
                        gutterY: 16
                    }"
                    class="h-full"
                >
                    <template #item="{ item, onRemove }">
                        <img
                            :src="item.src"
                            :alt="`Image ${item.id}`"
                            class="w-full h-auto cursor-pointer"
                            loading="lazy"
                            @error="(e) => console.warn('Failed to load image:', item.id, e)"
                            @load="(e) => console.debug('Loaded image:', item.id)"
                            @mousedown="(e) => handleImageClick(e, item, onRemove)"
                            @contextmenu="(e) => handleImageContextMenu(e, item, onRemove)"
                        />
                        <button
                            class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white p-2 rounded-full cursor-pointer shadow-lg transition-colors opacity-80 hover:opacity-100"
                            @click="onRemove(item)"
                            title="Remove item"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                            </svg>
                        </button>
                    </template>
                </Masonry>

                <!-- Loading Overlay -->
                <div
                    v-if="isLoading"
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
