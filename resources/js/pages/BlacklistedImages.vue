<script lang="ts" setup>
import FileReactions from '@/components/audio/FileReactions.vue';
import { createMasonryPageLoader } from '@/composables/useMasonryData';
import { useItemReactions } from '@/composables/useItemReactions';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { BrowseItem as IBrowseItem } from '@/types/browse';
import { Head } from '@inertiajs/vue3';
import { Masonry } from '@wyxos/vibe';
import { ref } from 'vue';

const props = defineProps<{
    filters: {
        page: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Images', href: route('images.index') },
    { title: 'Blacklisted', href: route('images.blacklisted') },
];

const items = ref<IBrowseItem[]>([] as any);
const masonry = ref<any>(null);
const getPage = createMasonryPageLoader({ routeName: 'images.blacklisted.data', defaultLimit: 40 });

// Reactions (mirror Browse.vue behavior for list items)
const { handleFavorite, handleLike, handleDislike, handleLaughedAt, blacklistImage, startDownload } = useItemReactions();

const removeItemFromView = (item: IBrowseItem) => {
    if (masonry.value && typeof masonry.value.onRemove === 'function') {
        masonry.value.onRemove(item);
    }
};

const onFavorite = (item: IBrowseItem, event: Event) => handleFavorite(item, event, removeItemFromView);
const onLike = (item: IBrowseItem, event: Event) => handleLike(item, event, removeItemFromView);
const onDislike = (item: IBrowseItem, event: Event) => handleDislike(item, event, () => blacklistImage(item, removeItemFromView));
const onLaughedAt = (item: IBrowseItem, event: Event) => handleLaughedAt(item, event, removeItemFromView);

// Alt-based shortcuts on thumbnails
const onAltClick = (item: IBrowseItem) => {
    startDownload(item);
    handleLike(item, new Event('click'), removeItemFromView);
};
const onAltMiddleClick = (item: IBrowseItem) => {
    startDownload(item);
    handleFavorite(item, new Event('click'), removeItemFromView);
};
const onAltRightClick = (item: IBrowseItem) => {
    blacklistImage(item, removeItemFromView);
};
</script>

<template>
    <Head title="Blacklisted Images" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-screen flex-col overflow-hidden">
            <!-- Masonry Container -->
            <div class="relative min-h-0 flex-1">
                <Masonry
                    ref="masonry"
                    v-model:items="items"
                    :get-next-page="getPage"
                    :layout="{
                        sizes: { base: 1, sm: 2, md: 3, lg: 3, xl: 5, '2xl': 8 },
                        footer: 32,
                    }"
                    :load-at-page="filters.page"
                    :max-items="150"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <div class="group relative">
                            <!-- Media container with reserved footer space (32px) -->
                            <div style="padding-bottom: 32px">
                                <img
                                    :src="item.image_url || item.src"
                                    :width="item.width"
                                    :height="item.imageHeight || item.height"
                                    alt="Image"
                                    class="w-full cursor-pointer object-cover block"
                                    @click.left.exact="/* open viewer later if needed */ null"
                                    @click.alt.exact.prevent="onAltClick(item)"
                                    @click.middle.alt.exact.prevent="onAltMiddleClick(item)"
                                    @contextmenu.alt.exact.prevent="onAltRightClick(item)"
                                />
                            </div>
                            <!-- Footer area for reactions (list variant) -->
                            <div class="absolute right-0 bottom-0 left-0 flex items-center justify-end p-2" style="height: 32px">
                                <div>
                                    <FileReactions
                                        :file="item"
                                        :icon-size="16"
                                        variant="list"
                                        @favorite="onFavorite(item, $event)"
                                        @like="onLike(item, $event)"
                                        @dislike="onDislike(item, $event)"
                                        @laughedAt="onLaughedAt(item, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>
                </Masonry>
            </div>
        </div>
    </AppLayout>
</template>
