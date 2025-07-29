<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Card, CardContent } from '@/components/ui/card';
import { Pagination } from '@/components/ui/pagination';
import { Button } from '@/components/ui/button';
import { Image, Eye, Trash2, X } from 'lucide-vue-next';
import GenericSearch from '@/components/ui/search/GenericSearch.vue';
import axios from 'axios';
import { ref } from 'vue';

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
    source?: string;
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

// Reactive state for handling deletions
const deletingImages = ref<Set<number>>(new Set());
const removedImages = ref<Set<number>>(new Set());

// Delete or blacklist an image
const handleDeleteImage = async (image: ImageFile, event: Event) => {
    event.stopPropagation(); // Prevent card click
    
    const actionText = image.source === 'local' ? 'delete' : 'blacklist';
    
    if (!confirm(`Are you sure you want to ${actionText} this image?`)) {
        return;
    }

    deletingImages.value.add(image.id);

    try {
        const response = await axios.delete(route('images.delete', { file: image.id }));
        
        if (response.data.success) {
            removedImages.value.add(image.id);
            
            // Show success message
            alert(response.data.message);
        }
    } catch (error) {
        console.error('Failed to delete/blacklist image:', error);
        alert('Failed to process the image. Please try again.');
    } finally {
        deletingImages.value.delete(image.id);
    }
};

// Get button text based on source
const getDeleteButtonText = (image: ImageFile): string => {
    return image.source === 'local' ? 'Delete' : 'Blacklist';
};

// Get button variant based on source
const getDeleteButtonVariant = (image: ImageFile): 'destructive' | 'secondary' => {
    return image.source === 'local' ? 'destructive' : 'secondary';
};

// Check if image should be hidden (removed)
const isImageRemoved = (image: ImageFile): boolean => {
    return removedImages.value.has(image.id);
};

// Check if image is being deleted
const isImageDeleting = (image: ImageFile): boolean => {
    return deletingImages.value.has(image.id);
};
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
                                
                                <!-- Delete/Blacklist Button -->
                                <div class="mt-3">
                                    <Button
                                        v-if="!isImageRemoved(image)"
                                        :variant="getDeleteButtonVariant(image)"
                                        size="sm"
                                        :disabled="isImageDeleting(image)"
                                        @click="(event) => handleDeleteImage(image, event)"
                                    >
                                        <template v-if="isImageDeleting(image)">
                                            <div class="w-4 h-4 border-2 border-t-transparent rounded-full animate-spin"></div>
                                        </template>
                                        <template v-else>
                                            <component :is="image.source === 'local' ? Trash2 : X" class="w-4 h-4 mr-2" />
                                            {{ getDeleteButtonText(image) }}
                                        </template>
                                    </Button>
                                    <div v-else class="text-sm text-green-600 font-medium">
                                        ✓ {{ image.source === 'local' ? 'Deleted' : 'Blacklisted' }}
                                    </div>
                                </div>
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
