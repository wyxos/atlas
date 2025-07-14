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

interface Playlist {
    id: number;
    name: string;
    description?: string;
    created_at: string;
    user: {
        id: number;
        name: string;
    };
    covers?: any[];
}

const props = defineProps<{
    playlist: Playlist;
    files: any[];
    search: any[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Playlists',
        href: route('playlists.index'),
    },
    {
        title: props.playlist.name,
        href: route('playlists.show', { playlist: props.playlist.id }),
    },
];

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

// Format date - playlist-specific functionality
function formatDate(dateString: string): string {
    return new Date(dateString).toLocaleDateString();
}
</script>

<template>
    <Head :title="playlist.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <!-- Playlist Header -->
            <div class="p-6 border-b">
                <div class="flex items-center space-x-4">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gray-100 rounded-lg flex items-center justify-center">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h1 class="text-3xl font-bold truncate" :title="playlist.name">
                            {{ playlist.name }}
                        </h1>
                        <div class="flex items-center space-x-4 text-sm text-gray-500 mt-1">
                            <span>{{ files.length }} {{ files.length === 1 ? 'track' : 'tracks' }}</span>
                            <span>Created {{ formatDate(playlist.created_at) }}</span>
                            <span>by {{ playlist.user.name }}</span>
                        </div>
                        <p v-if="playlist.description" class="text-gray-600 text-sm mt-2">
                            {{ playlist.description }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Search component -->
            <AudioSearch :initial-query="initialQuery">
                <template #noResults="{ query }">
                    <p class="text-gray-500">No match was found for "{{ query }}" in this playlist</p>
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

            <!-- Empty state -->
            <div v-if="files.length === 0" class="flex-1 flex items-center justify-center">
                <div class="text-center py-12">
                    <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                    </svg>
                    <h3 class="text-lg font-semibold text-gray-600 mb-2">No Tracks in Playlist</h3>
                    <p class="text-gray-500 mb-4">This playlist is empty. Add some tracks to get started.</p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
