<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Pagination } from '@/components/ui/pagination';
import { Image, Eye } from 'lucide-vue-next';
import GenericSearch from '@/components/ui/search/GenericSearch.vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Images',
        href: route('images.index'),
    },
];

interface ImageFile {
    id: number;
    filename: string;
    path?: string;
    image_url?: string;
    title?: string;
    size: number;
    width?: number;
    height?: number;
    covers?: Array<{
        id: number;
        path: string;
    }>;
}

const props = defineProps<{
    images: {
        data: ImageFile[];
        links: any[];
        meta: any;
    };
}>();

const { images } = props;

// Get initial query from URL
function getInitialQuery(): string {
    if (typeof window !== 'undefined' && window.location.search) {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.get('query') || '';
    }
    return '';
}

// Get image path
function getImagePath(image: ImageFile): string {
    // Use the normalized URL from the controller if available, otherwise fallback to manual construction
    return image.image_url || `/atlas/${image.path}`;
}

// Format file size
function formatFileSize(bytes: number): string {
    const sizes = ['B', 'KB', 'MB', 'GB'];
    if (bytes === 0) return '0 B';
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
}

// Format dimensions
function formatDimensions(image: ImageFile): string {
    if (image.width && image.height) {
        return `${image.width} × ${image.height}`;
    }
    return '';
}

// Get display title
function getDisplayTitle(image: ImageFile): string {
    return image.title || image.filename.replace(/\.[^/.]+$/, '');
}
</script>

<template>
    <Head title="Images" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-6">
            <div class="mb-6">
                <h1 class="text-3xl font-bold">Images</h1>
                <p class="text-gray-600 mt-2">Browse your image collection</p>
            </div>

            <!-- Search Component -->
            <GenericSearch
                route-name="images.index"
                placeholder="Search images..."
                :initial-query="getInitialQuery()"
            >
                <template #noResults="{ query }">
                    <div class="text-center">
                        <Image class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                        <h3 class="text-lg font-semibold text-gray-600 mb-2">No Images Found</h3>
                        <p class="text-gray-500">No images match "{{ query }}"</p>
                    </div>
                </template>

                <!-- Images Grid - Pinterest-like layout -->
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-4 mb-8">
                    <Card
                        v-for="image in images.data"
                        :key="image.id"
                        class="hover:shadow-lg transition-shadow cursor-pointer group overflow-hidden"
                    >
                        <CardContent class="p-0">
                            <!-- Image Thumbnail -->
                            <div class="relative bg-gray-100 flex items-center justify-center overflow-hidden">
                                <img
                                    v-if="getImagePath(image)"
                                    :src="getImagePath(image)"
                                    :alt="getDisplayTitle(image)"
                                    class="w-full h-auto object-cover"
                                    loading="lazy"
                                />
                                <div v-else class="aspect-square flex items-center justify-center">
                                    <Image class="w-12 h-12 text-gray-400" />
                                </div>

                                <!-- View overlay -->
<!--                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all duration-200 flex items-center justify-center">-->
<!--                                    <Eye class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity duration-200" />-->
<!--                                </div>-->

                                <!-- Dimensions badge -->
                                <div v-if="formatDimensions(image)" class="absolute top-2 right-2 bg-black bg-opacity-80 text-white text-xs px-2 py-1 rounded">
                                    {{ formatDimensions(image) }}
                                </div>
                            </div>

                            <!-- Image Info -->
                            <div class="p-3">
                                <h3 class="font-semibold text-sm line-clamp-2 mb-1" :title="getDisplayTitle(image)">
                                    {{ getDisplayTitle(image) }}
                                </h3>
                                <p class="text-xs text-gray-500">
                                    {{ formatFileSize(image.size) }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Pagination -->
                <Pagination :data="images" />

                <!-- Empty state -->
                <div v-if="images.data.length === 0" class="text-center py-12">
                    <Image class="w-16 h-16 text-gray-400 mx-auto mb-4" />
                    <h3 class="text-lg font-semibold text-gray-600 mb-2">No Images Found</h3>
                    <p class="text-gray-500">No images are available in your collection.</p>
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
