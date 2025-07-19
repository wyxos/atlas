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

interface DemoItem {
    id: string;
    src: string;
    width: number;
    height: number;
    page: number;
    index: number;
}

// Sample data generator - simulates API calls
const generateSampleData = (page: number, itemsPerPage: number = 24): DemoItem[] => {
    const items: DemoItem[] = [];
    const startIndex = (page - 1) * itemsPerPage;
    
    for (let i = 0; i < itemsPerPage; i++) {
        const index = startIndex + i;
        const id = `item-${page}-${i}`;
        const picId = (index % 1000) + 1; // Use picsum photo IDs 1-1000
        const width = 250 + Math.floor(Math.random() * 300); // Random width between 250-550
        const height = 200 + Math.floor(Math.random() * 400); // Random height between 200-600
        
        items.push({
            id,
            src: `https://picsum.photos/id/${picId}/${width}/${height}`,
            width,
            height,
            page,
            index
        });
    }
    
    return items;
};

// Simulate API call with delay
const getPage = async (page: number) => {
    return new Promise<{ items: DemoItem[], nextPage: number }>((resolve) => {
        setTimeout(() => {
            const newItems = generateSampleData(page);
            const output = {
                items: newItems,
                nextPage: page + 1
            };
            resolve(output);
        }, 800); // Simulate network delay
    });
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
