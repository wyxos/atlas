<script setup lang="ts">
import { ref } from 'vue';
import { Masonry } from '@wyxos/vibe';
import { Loader2 } from 'lucide-vue-next';
import Pill from '../components/ui/Pill.vue';

type MasonryItem = {
    id: string;
    width: number;
    height: number;
    page: number;
    index: number;
    src: string;
    type?: 'image' | 'video';
    notFound?: boolean;
    [key: string]: unknown;
};

type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
};

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number>(1); // Starts as 1, becomes cursor string
const nextCursor = ref<string | null>(null); // The cursor from API

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getNextPage(page: number | string): Promise<GetPageResult> {
    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(page));

    const response = await fetch(url.toString());
    const data = await response.json();

    // Update current page to the cursor we just used (or keep as 1 if it was the first page)
    if (page === 1) {
        currentPage.value = 1;
    } else {
        currentPage.value = page; // This is the cursor we just used
    }

    // Update next cursor from API response
    nextCursor.value = data.nextPage; // This is the cursor string from CivitAI

    return {
        items: data.items,
        nextPage: data.nextPage, // Pass cursor to Masonry for next request
    };
}
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="mb-4 flex items-center justify-center gap-3">
            <!-- Count Pill -->
            <Pill label="Items" :value="items.length" variant="primary" reversed />
            <!-- Current Page Pill -->
            <Pill label="Page" :value="currentPage" variant="neutral" reversed />
            <!-- Next Page Pill -->
            <Pill v-if="nextCursor != null" label="Next" :value="nextCursor" variant="secondary" reversed />
            <!-- Status Pill -->
            <Pill :label="'Status'" :value="masonry?.isLoading ? 'Loading...' : 'Ready'"
                :variant="masonry?.isLoading ? 'primary' : 'success'" reversed>
                <template #label>
                    <span class="flex items-center gap-1.5">
                        Status
                    </span>
                </template>
                <template #value>
                    <Loader2 v-if="masonry?.isLoading" :size="14" class="animate-spin" />
                </template>
            </Pill>
        </div>
        <div class="flex-1 min-h-0">
            <Masonry ref="masonry" v-model:items="items" :get-next-page="getNextPage" :load-at-page="1" :layout="layout"
                layout-mode="auto" :mobile-breakpoint="768" />
        </div>
        <div class="mb-4 flex items-center justify-center gap-3">
            Filters will be here
        </div>
    </div>
</template>
