<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Pagination } from '@/components/ui/pagination';
import { Disc } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Albums',
        href: route('albums.index'),
    },
];

interface Album {
    id: number;
    name: string;
    covers?: Array<{
        id: number;
        path: string;
    }>;
}

const { albums } = defineProps<{
    albums: {
        data: Album[];
        links: any[];
        meta: any;
    };
}>();

// Get album cover image
function getAlbumCover(album: Album): string {
    if (album.covers && album.covers.length > 0) {
        return `/atlas/${album.covers[0].path}`;
    }
    return '';
}
</script>

<template>
    <Head title="Albums" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-3xl font-bold">Albums</h1>
                <p class="text-gray-600 mt-2">Browse your music collection by album</p>
            </div>

            <!-- Albums Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4 mb-8">
                <Card
                    v-for="album in albums.data"
                    :key="album.id"
                    class="hover:shadow-lg transition-shadow cursor-pointer"
                >
                    <CardContent class="p-4">
                        <div class="aspect-square mb-3 bg-gray-100 rounded-lg flex items-center justify-center overflow-hidden">
                            <img
                                v-if="getAlbumCover(album)"
                                :src="getAlbumCover(album)"
                                :alt="album.name"
                                class="w-full h-full object-cover"
                            />
                            <Disc v-else class="w-12 h-12 text-gray-400" />
                        </div>
                        <h3 class="font-semibold text-sm truncate" :title="album.name">
                            {{ album.name }}
                        </h3>
                    </CardContent>
                </Card>
            </div>

            <!-- Pagination -->
            <Pagination :data="albums" />

            <!-- Empty state -->
            <div v-if="albums.data.length === 0" class="text-center py-12">
                <Disc class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-gray-600 mb-2">No Albums Found</h3>
                <p class="text-gray-500">No albums are available in your music collection.</p>
            </div>
        </div>
    </AppLayout>
</template>
