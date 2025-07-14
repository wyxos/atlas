<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Pagination } from '@/components/ui/pagination';
import { Video, Play } from 'lucide-vue-next';
import GenericSearch from '@/components/ui/search/GenericSearch.vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Video',
        href: route('video.index'),
    },
];

interface VideoFile {
    id: number;
    filename: string;
    title?: string;
    size: number;
    duration?: number;
    covers?: Array<{
        id: number;
        path: string;
    }>;
}

const props = defineProps<{
    videos: {
        data: VideoFile[];
        links: any[];
        meta: any;
    };
    search: VideoFile[];
}>();

const { videos } = props;

// Get video thumbnail/cover image
function getVideoThumbnail(video: VideoFile): string {
    if (video.covers && video.covers.length > 0) {
        return `/atlas/${video.covers[0].path}`;
    }
    return '';
}

// Format file size
function formatFileSize(bytes: number): string {
    const sizes = ['B', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
}

// Format duration
function formatDuration(seconds?: number): string {
    if (!seconds) return '';
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
        return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
}

// Get display title
function getDisplayTitle(video: VideoFile): string {
    return video.title || video.filename.replace(/\.[^/.]+$/, '');
}
</script>

<template>
    <Head title="Video" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-3xl font-bold">Video</h1>
                <p class="text-gray-600 mt-2">Browse your video collection</p>
            </div>

            <!-- Search Component -->
            <GenericSearch
                route-name="video.index"
                placeholder="Search videos..."
                :initial-query="$page.url.includes('query=') ? new URLSearchParams($page.url.split('?')[1]).get('query') : ''"
            >
                <template #noResults="{ query }">
                    <div class="text-center">
                        <Video class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                        <h3 class="text-lg font-semibold text-gray-600 mb-2">No Videos Found</h3>
                        <p class="text-gray-500">No videos match "{{ query }}"</p>
                    </div>
                </template>

                <!-- Videos Grid - YouTube-like layout -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-4 mb-8">
                    <Card
                        v-for="video in (props.search.length > 0 ? props.search : videos.data)"
                        :key="video.id"
                        class="hover:shadow-lg transition-shadow cursor-pointer group"
                    >
                        <CardContent class="p-0">
                            <!-- Video Thumbnail -->
                            <div class="relative aspect-video bg-gray-100 rounded-t-lg flex items-center justify-center overflow-hidden">
                                <img
                                    v-if="getVideoThumbnail(video)"
                                    :src="getVideoThumbnail(video)"
                                    :alt="getDisplayTitle(video)"
                                    class="w-full h-full object-cover"
                                />
                                <Video v-else class="w-12 h-12 text-gray-400" />

                                <!-- Play button overlay -->
                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all duration-200 flex items-center justify-center">
                                    <Play class="w-12 h-12 text-white opacity-0 group-hover:opacity-100 transition-opacity duration-200" />
                                </div>

                                <!-- Duration badge -->
                                <div v-if="video.duration" class="absolute bottom-2 right-2 bg-black bg-opacity-80 text-white text-xs px-2 py-1 rounded">
                                    {{ formatDuration(video.duration) }}
                                </div>
                            </div>

                            <!-- Video Info -->
                            <div class="p-3">
                                <h3 class="font-semibold text-sm line-clamp-2 mb-1" :title="getDisplayTitle(video)">
                                    {{ getDisplayTitle(video) }}
                                </h3>
                                <p class="text-xs text-gray-500">
                                    {{ formatFileSize(video.size) }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Pagination (only show when not searching) -->
                <Pagination v-if="props.search.length === 0" :data="videos" />

                <!-- Empty state (only show when not searching) -->
                <div v-if="props.search.length === 0 && videos.data.length === 0" class="text-center py-12">
                    <Video class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                    <h3 class="text-lg font-semibold text-gray-600 mb-2">No Videos Found</h3>
                    <p class="text-gray-500">No videos are available in your collection.</p>
                </div>
            </GenericSearch>
        </div>
    </AppLayout>
</template>

<style scoped>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
