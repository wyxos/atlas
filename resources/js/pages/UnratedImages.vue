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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('dashboard') },
    { title: 'Images', href: route('images.index') },
    { title: 'Unrated', href: route('images.unrated') },
];

const items = ref<IBrowseItem[]>([] as any);
const masonry = ref<any>(null);
const getPage = createMasonryPageLoader({ routeName: 'images.unrated.data', defaultLimit: 40 });

// Reactions
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

// Alt shortcuts (match Browse.vue behavior)
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
    <Head title="Unrated Images" />
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
                    :load-at-page="1"
                    :max-items="150"
                    class="h-full"
                >
                    <template #item="{ item }">
                        <div class="group relative">
<img
                                :src="item.image_url || item.src"
                                :width="item.width"
                                :height="item.imageHeight || item.height"
                                alt="Image"
                                class="h-full w-full cursor-pointer object-cover"
                                @click.alt.exact.prevent="onAltClick(item)"
                                @click.middle.alt.exact.prevent="onAltMiddleClick(item)"
                                @contextmenu.alt.exact.prevent="onAltRightClick(item)"
                            />
<!-- Footer area for reactions (mirror BrowseItem) -->
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
