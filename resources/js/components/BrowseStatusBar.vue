<script setup lang="ts">
import { computed } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import Pill from './ui/Pill.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { MasonryInstance } from '@wyxos/vibe';

interface Props {
    items: FeedItem[];
    masonry: MasonryInstance | null;
    tab: { params?: { page?: string | number; next?: string | number | null } } | null;
    isLoading?: boolean;
    visible?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    isLoading: false,
    visible: true,
});

// Display page value - Vibe exposes the loaded page tokens.
// Fallback to tab.params.page when masonry isn't initialized yet.
const displayPage = computed(() => {
    const pagesLoaded = props.masonry?.pagesLoaded;
    const lastLoaded = Array.isArray(pagesLoaded) && pagesLoaded.length > 0
        ? pagesLoaded[pagesLoaded.length - 1]
        : null;

    return lastLoaded ?? props.tab?.params?.page ?? 1;
});

// Next cursor/page token comes directly from Vibe.
const nextCursor = computed(() => props.masonry?.nextPage ?? props.tab?.params?.next ?? null);

</script>

<template>
    <div v-if="visible" class="my-2 flex flex-wrap items-center justify-center gap-3" data-test="pagination-info">
        <!-- Count Pill -->
        <Pill label="Items" :value="items.length" variant="primary" reversed data-test="items-pill" />
        <!-- Current Page Pill -->
        <Pill label="Page" :value="displayPage" variant="neutral" reversed data-test="page-pill" />
        <!-- Next Page Pill -->
        <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed data-test="next-pill" />
        <!-- Status Pill -->
        <Pill :label="'Status'" :value="isLoading ? 'Loading...' : 'Ready'" :variant="isLoading ? 'danger' : 'success'"
            reversed data-test="status-pill">
            <template #value>
                <span v-if="isLoading" class="flex items-center gap-2">
                    <Loader2 :size="14" class="animate-spin" />
                    <span>Loading...</span>
                </span>
                <span v-else>Ready</span>
            </template>
        </Pill>
    </div>
</template>
