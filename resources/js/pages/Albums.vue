<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { ChevronLeft, ChevronRight, Disc } from 'lucide-vue-next';

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
        return `/storage/${album.covers[0].path}`;
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
            <div v-if="albums.links && albums.links.length > 3" class="mt-4 flex justify-center">
                <nav class="flex space-x-2">
                    <template v-for="link in albums.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="rounded-md px-3 py-2 text-sm"
                            :class="{
                                'bg-blue-500 text-white': link.active,
                                'bg-gray-200 text-gray-700 hover:bg-gray-300': !link.active,
                            }"
                        >
                            <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                            <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                            <span v-else>{{ link.label }}</span>
                        </Link>
                        <span v-else class="px-3 py-2 text-sm text-gray-400">
                            <ChevronLeft v-if="link.label.includes('Previous')" class="h-4 w-4" />
                            <ChevronRight v-else-if="link.label.includes('Next')" class="h-4 w-4" />
                            <span v-else>{{ link.label }}</span>
                        </span>
                    </template>
                </nav>
            </div>

            <!-- Empty state -->
            <div v-if="albums.data.length === 0" class="text-center py-12">
                <Disc class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                <h3 class="text-lg font-semibold text-gray-600 mb-2">No Albums Found</h3>
                <p class="text-gray-500">No albums are available in your music collection.</p>
            </div>
        </div>
    </AppLayout>
</template>
