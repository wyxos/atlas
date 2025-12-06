<script setup lang="ts">
import { ref } from 'vue';
import { Masonry } from '@wyxos/vibe';

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
    nextPage: number | null;
};

const items = ref<MasonryItem[]>([]);

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 8 },
};

async function getNextPage(page: number): Promise<GetPageResult> {
    const response = await fetch(`/api/browse?page=${page}`);
    const data = await response.json();
    return {
        items: data.items,
        nextPage: data.nextPage,
    };
}
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="flex-1 min-h-0">
            <Masonry v-model:items="items" :get-next-page="getNextPage" :load-at-page="1" :layout="layout"
                layout-mode="auto" :mobile-breakpoint="768" />
        </div>
    </div>
</template>
