<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Masonry } from '@wyxos/vibe';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Browse',
        href: '/browse',
    },
];

const items = ref([]);
const masonry = ref(null);

// Sample data for demonstration (similar to vibe example)
const sampleImages = [
    { id: 1, src: 'https://picsum.photos/300/400?random=1' },
    { id: 2, src: 'https://picsum.photos/300/600?random=2' },
    { id: 3, src: 'https://picsum.photos/300/300?random=3' },
    { id: 4, src: 'https://picsum.photos/300/500?random=4' },
    { id: 5, src: 'https://picsum.photos/300/700?random=5' },
    { id: 6, src: 'https://picsum.photos/300/350?random=6' },
    { id: 7, src: 'https://picsum.photos/300/450?random=7' },
    { id: 8, src: 'https://picsum.photos/300/550?random=8' },
    { id: 9, src: 'https://picsum.photos/300/650?random=9' },
    { id: 10, src: 'https://picsum.photos/300/400?random=10' },
];

// Simulate paginated data
const pages = [
    { items: sampleImages.slice(0, 3) },
    { items: sampleImages.slice(3, 6) },
    { items: sampleImages.slice(6, 9) },
    { items: sampleImages.slice(9, 10) },
];

const getPage = async (page: number) => {
    return new Promise((resolve) => {
        setTimeout(() => {
            const pageIndex = page - 1;
            let output = {
                items: pages[pageIndex]?.items || [],
                nextPage: pageIndex < pages.length - 1 ? page + 1 : null
            };
            resolve(output);
        }, 1000);
    });
};
</script>

<template>
    <Head title="Browse" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <!-- Header -->
            <div class="flex flex-col items-center gap-4 mb-6">
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

            <!-- Masonry Container -->
            <div class="flex-1 overflow-hidden">
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
                        <div class="relative bg-white rounded-lg shadow-sm overflow-hidden border border-border hover:shadow-md transition-shadow">
                            <img 
                                :src="item.src" 
                                :alt="`Image ${item.id}`"
                                class="w-full h-auto object-cover"
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
                            <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/50 to-transparent p-3">
                                <p class="text-white text-sm font-medium">Image {{ item.id }}</p>
                            </div>
                        </div>
                    </template>
                </Masonry>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* Add any custom styles if needed */
</style>
