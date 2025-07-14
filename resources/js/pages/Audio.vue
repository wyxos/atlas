<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { audioStore } from '@/stores/audioStore';

// Import our components and composables
import AudioListItem from '@/components/audio/AudioListItem.vue';
import AudioSearch from '@/components/audio/AudioSearch.vue';
import { useAudioList } from '@/composables/useAudioList';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Audio',
        href: route('audio'),
    },
];

const props = defineProps<{
    files: any[];
    search: any[];
}>();

// Use the audio list composable for all common functionality
const {
    loadedFiles,
    swipedItemId,
    recycleScrollerRef,
    initialQuery,
    playAudio,
    getFileData,
    toggleFavorite,
    likeItem,
    dislikeItem,
    laughedAtItem,
    handleTouchStart,
    handleTouchMove,
    handleTouchEnd,
    handleGlobalClick,
    onScroll
} = useAudioList(props);
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <!-- Search component -->
            <AudioSearch :initial-query="initialQuery">
                <template #noResults="{ query }">
                    <p class="text-gray-500">No match was found for "{{ query }}"</p>
                </template>

                <template #default="{ query }">
                    <!-- Results list -->
                    <div class="flex-1 md:p-4">
                        <RecycleScroller
                            ref="recycleScrollerRef"
                            class="h-[600px] RecycleScroller"
                            :items="query ? props.search : props.files"
                            :item-size="74"
                            key-field="id"
                            :emit-update="true"
                            @update="onScroll"
                            v-slot="{ item, index }"
                        >
                            <div class="relative overflow-hidden">
                                <!-- List item component -->
                                <AudioListItem
                                    :item="item"
                                    :index="index + 1"
                                    :loaded-file="loadedFiles[item.id]"
                                    :is-playing="audioStore.isPlaying"
                                    :current-file-id="audioStore.currentFile ? audioStore.currentFile.id : null"
                                    :is-swiped-open="swipedItemId === item.id"
                                    @play="playAudio(getFileData(item))"
                                    @touch-start="handleTouchStart"
                                    @touch-move="handleTouchMove"
                                    @touch-end="handleTouchEnd"
                                    @favorite="toggleFavorite"
                                    @like="likeItem"
                                    @dislike="dislikeItem"
                                    @laughed-at="laughedAtItem"
                                />
                            </div>
                        </RecycleScroller>
                    </div>
                </template>
            </AudioSearch>

        </div>
    </AppLayout>
</template>
