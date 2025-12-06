<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
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

const route = useRoute();
const router = useRouter();

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number>(1); // Starts as 1, becomes cursor string
const nextCursor = ref<string | null>(null); // The cursor from API
const previousLoadingState = ref(false);
const loadAtPage = ref<string | number>(1); // Initial page to load, can be from URL

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

// Watch for loading state changes to update URL when page loads successfully
watch(
    () => masonry.value?.isLoading,
    (isLoading) => {
        // When loading transitions from true to false, a page has successfully loaded
        if (previousLoadingState.value && !isLoading) {
            updateUrl();
        }
        previousLoadingState.value = isLoading ?? false;
    },
    { immediate: true }
);

function updateUrl(): void {
    const query: Record<string, string> = {};

    // Add current page to query
    if (currentPage.value !== 1) {
        query.page = String(currentPage.value);
    }

    // Add next cursor to query if available
    if (nextCursor.value) {
        query.next = nextCursor.value;
    }

    // Update URL without triggering navigation
    router.replace({
        query: {
            ...route.query,
            ...query,
        },
    });
}

// Initialize from URL on mount
onMounted(() => {
    const pageParam = route.query.page;
    const nextParam = route.query.next;

    if (pageParam) {
        // If page is in URL, use it as the initial load page
        const pageValue = typeof pageParam === 'string' ? pageParam : String(pageParam);
        loadAtPage.value = pageValue;
        currentPage.value = pageValue;
    }

    if (nextParam) {
        // If next cursor is in URL, set it
        const nextValue = typeof nextParam === 'string' ? nextParam : String(nextParam);
        nextCursor.value = nextValue;
    }
});
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="mb-4 flex items-center justify-center gap-3">
            <!-- Count Pill -->
            <Pill label="Items" :value="items.length" variant="primary" reversed />
            <!-- Current Page Pill -->
            <Pill label="Page" :value="currentPage" variant="neutral" reversed />
            <!-- Next Page Pill -->
            <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed />
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
            <Masonry ref="masonry" v-model:items="items" :get-next-page="getNextPage" :load-at-page="loadAtPage"
                :layout="layout" layout-mode="auto" :mobile-breakpoint="768" />
        </div>
        <div class="mb-4 flex items-center justify-center gap-3">
            Filters will be here
        </div>
    </div>
</template>
