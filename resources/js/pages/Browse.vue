<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import { Masonry } from '@wyxos/vibe';

interface DemoItem {
    id: string;
    src: string;
    width: number;
    height: number;
    page: number;
    index: number;
    meta?: {
        model_name?: string;
        model_id?: number;
        version_name?: string;
        blurhash?: string;
    };
}

interface Props {
    initialImages: DemoItem[];
    currentPage: number | string;
    hasNextPage: boolean;
    nextCursor?: string | null;
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
let nextCursorToFetch: string | null = null; // Track next cursor to fetch

// Initialize with server-side data
onMounted(() => {
    if (props.initialImages && props.initialImages.length > 0) {
        items.value = [...props.initialImages];
        // Set next cursor based on whether we have more pages
        nextCursorToFetch = props.hasNextPage ? props.nextCursor : null;
    }
});

// Fetch more images for infinite scroll
const getPage = async (page: number) => {
    try {
        console.log('Masonry requesting page:', page, 'using cursor:', nextCursorToFetch); // Debug log
        
        // If there's no next cursor to fetch, return empty
        if (!nextCursorToFetch) {
            console.log('No more pages to fetch');
            return { items: [], nextPage: null };
        }
        
        // Use Inertia to navigate with cursor and get data
        return new Promise((resolve) => {
            router.get(
                route('browse', { cursor: nextCursorToFetch }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['initialImages', 'hasNextPage', 'nextCursor'],
                    onSuccess: (response) => {
                        const newImages = response.props.initialImages as DemoItem[];
                        const hasNext = response.props.hasNextPage;
                        const nextCursor = response.props.nextCursor;
                        
                        console.log('Fetched images:', newImages?.length, 'hasNext:', hasNext, 'nextCursor:', nextCursor); // Debug log
                        
                        if (newImages && newImages.length > 0) {
                            // Update next cursor to fetch
                            nextCursorToFetch = hasNext ? nextCursor : null;
                            
                            resolve({
                                items: newImages,
                                nextPage: hasNext ? nextCursor : null
                            });
                        } else {
                            nextCursorToFetch = null;
                            resolve({ items: [], nextPage: null });
                        }
                    },
                    onError: () => {
                        console.error('Failed to fetch more images');
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
</script>

<template>
    <Head title="Browse" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-screen flex flex-col overflow-hidden">
            <!-- Header -->
            <div class="flex-shrink-0 p-4 bg-white border-b">
                <div class="flex flex-col items-center gap-4">
                    <h1 class="text-3xl font-bold">Browse</h1>
                    <p class="text-muted-foreground">Vue Infinite Block Engine (VIBE) Example</p>
                    
                    <div v-if="masonry" class="flex items-center gap-4">
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-muted-foreground">Loading:</span>
                            <span 
                                class="px-3 py-1 rounded-full text-sm font-medium"
                                :class="masonry.isLoading ? 'bg-blue-500 text-white' : 'bg-green-500 text-white'"
                            >
                                {{ masonry.isLoading ? 'Loading...' : 'Ready' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-muted-foreground">Items:</span>
                            <span class="px-3 py-1 rounded-full bg-gray-100 text-gray-800 text-sm font-medium">
                                {{ items.length }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Masonry Container -->
            <div class="flex-1 min-h-0">
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
                            class="w-full h-auto"
                            loading="lazy"
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
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
