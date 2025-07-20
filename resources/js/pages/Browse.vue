<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { Masonry } from '@wyxos/vibe';

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
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Browse',
        href: '/browse',
    },
];

const items = ref<Item[]>([]);
const masonry = ref(null);
const isLoading = ref(false);

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
        items.value = [...props.items];
    }
});

// Mock download function
const downloadImage = (item: Item) => {
    console.log('Downloading image:', item.id, item.src);
    // TODO: Implement actual download functionality
    alert(`Downloading image: ${item.id}`);
};

// Blacklist function - removes the item
const blacklistImage = (item: Item, onRemove: any) => {
    console.log('Blacklisting image:', item.id);
    onRemove(item);
};

// Handle Alt+click for download
const handleAltClick = (item: Item) => {
    downloadImage(item);
};

// Handle Alt+right-click for blacklist
const handleAltRightClick = (item: Item, onRemove: any) => {
    blacklistImage(item, onRemove);
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

        // Set loading state
        isLoading.value = true;

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
                    only: ['items', 'hasNextPage', 'nextPage', 'page'],
                    onSuccess: (response) => {
                        try {
                            const newItems = response.props.items as Item[];
                            const hasNext = response.props.hasNextPage;
                            const nextPage = response.props.nextPage;
                            const currentPage = response.props.page;

                            console.log('Fetched items:', newItems?.length, 'hasNext:', hasNext, 'nextPage:', nextPage, 'currentPage:', currentPage);

                            if (newItems && newItems.length > 0) {
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
                            } else {
                                paginationState.value.hasNextPage = false;
                                paginationState.value.nextPage = null;
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
                    :load-at-page="null"
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
                            @load="() => console.debug('Loaded image:', item.id)"
                            @click.alt.exact.prevent="handleAltClick(item)"
                            @contextmenu.alt.exact.prevent="handleAltRightClick(item, onRemove)"
                        />
                        <button
                            class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white p-2 rounded-full cursor-pointer shadow-lg transition-colors opacity-80 hover:opacity-100"
                            @click="onRemove(item)"
                            title="Remove item"
                        >
                            <svg class="w-4 h-4" stroke="currentColor" viewBox="0 0 24 24">
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
