<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import Icon from '@/components/Icon.vue';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { computed, ref } from 'vue';
import { formatDate } from '@/lib/utils';
import { useImageZoom } from '@/composables/useImageZoom';

interface FileData {
    id: number;
    name: string;
    url: string;
    type: string;
    size: number;
    created_at: string;
    mime_type?: string;
    liked?: boolean;
    loved?: boolean;
    disliked?: boolean;
    image_url?: string;
    downloaded?: boolean;
    not_found?: boolean;
    path?: string;
    covers: Array<{
        id: number;
        path: string;
        hash: string;
    }>;
}

interface MetadataItem {
    id: number;
    file_id: number;
    key: string;
    value: string;
}

const props = defineProps<{
    file: FileData;
    metadata?: MetadataItem[] | Record<string, any>;
    rawMetadata?: Record<string, any>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Images',
        href: route('images.index'),
    },
    {
        title: props.file.name,
        href: route('images.show', { file: props.file.id }),
    },
];

// Helper function to format file sizes
const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Get file type icon
const getFileTypeIcon = (mimeType: string): string => {
    if (mimeType?.startsWith('image/')) return 'image';
    return 'file';
};

// Get file type color
const getFileTypeColor = (mimeType: string): string => {
    if (mimeType?.startsWith('image/')) return 'text-green-600';
    return 'text-gray-600';
};

// Use image zoom composable
const { 
    isImageViewerOpen, 
    imageViewerZoom, 
    imageViewerPosition, 
    currentImage, 
    imageUrl: zoomImageUrl, 
    openImageViewer, 
    closeImageViewer, 
    zoomIn, 
    zoomOut, 
    resetZoom, 
    startDrag, 
    onDrag, 
    stopDrag,
    isDragging 
} = useImageZoom();

// Open image viewer with current file
const openImageViewerWithFile = () => {
    openImageViewer(props.file);
};

// Get the image URL for display
const imageUrl = computed(() => {
    // If the file is downloaded and we have a local path, use the atlas storage path
    if (props.file.downloaded && props.file.path && !props.file.not_found) {
        return `/atlas/${props.file.path}`;
    }
    
    // If the file is not downloaded but we have a URL, use the original URL
    if (!props.file.downloaded && props.file.url) {
        return props.file.url;
    }
    
    // Fallback to image_url attribute or constructed atlas path
    return props.file.image_url || `/atlas/${props.file.path}`;
});

// Filter and organize metadata
const organizedMetadata = computed(() => {
    let metadataToProcess: Record<string, any> = {};

    // Handle different metadata structures
    if (props.rawMetadata && typeof props.rawMetadata === 'object') {
        metadataToProcess = props.rawMetadata;
    } else if (props.metadata) {
        // If metadata is an array of key-value objects
        if (Array.isArray(props.metadata)) {
            metadataToProcess = props.metadata.reduce((acc, item) => {
                acc[item.key] = item.value;
                return acc;
            }, {} as Record<string, any>);
        } else if (typeof props.metadata === 'object') {
            metadataToProcess = props.metadata;
        }
    }

    if (!metadataToProcess || Object.keys(metadataToProcess).length === 0) {
        return {};
    }

    const organized: Record<string, Record<string, any>> = {
        'Basic Info': {},
        'Image Properties': {},
        'Technical': {},
        'Other': {}
    };

    Object.entries(metadataToProcess).forEach(([key, value]) => {
        if (!value || value === '') return; // Skip empty values

        const lowerKey = key.toLowerCase();

        if (['title', 'description', 'subject', 'keywords', 'creator', 'author'].includes(lowerKey)) {
            organized['Basic Info'][key] = value;
        } else if (['width', 'height', 'resolution', 'colorspace', 'bitdepth', 'compression'].includes(lowerKey)) {
            organized['Image Properties'][key] = value;
        } else if (['filesize', 'format', 'codec', 'container', 'created', 'modified'].includes(lowerKey)) {
            organized['Technical'][key] = value;
        } else {
            organized['Other'][key] = value;
        }
    });

    // Remove empty sections
    return Object.fromEntries(
        Object.entries(organized).filter(([, section]) => Object.keys(section).length > 0)
    );
});
</script>

<template>
    <Head :title="file.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <!-- File Header -->
            <Card>
                <CardHeader>
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <Icon
                                :name="getFileTypeIcon(file.mime_type || file.type)"
                                :class="`h-8 w-8 ${getFileTypeColor(file.mime_type || file.type)}`"
                            />
                            <div>
                                <CardTitle class="text-xl">{{ file.name }}</CardTitle>
                                <CardDescription class="mt-1 flex items-center gap-2">
                                    <span>{{ formatFileSize(file.size) }}</span>
                                    <Separator orientation="vertical" class="h-4" />
                                    <span>{{ file.mime_type || file.type }}</span>
                                    <Separator orientation="vertical" class="h-4" />
                                    <span>{{ formatDate(file.created_at) }}</span>
                                </CardDescription>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex items-center gap-2">
                            <!-- View Full Size Button -->
                            <Button
                                variant="outline"
                                size="sm"
                                @click="openImageViewerWithFile"
                            >
                                <Icon name="maximize" class="h-4 w-4 mr-2" />
                                View Full Size
                            </Button>

                            <!-- Rating Buttons -->
                            <div class="ml-2 flex items-center gap-1">
                                <Button variant="ghost" size="sm" :class="file.loved ? 'text-red-500' : 'text-muted-foreground'">
                                    <Icon name="heart" class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="sm" :class="file.liked ? 'text-green-500' : 'text-muted-foreground'">
                                    <Icon name="thumbsUp" class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="sm" :class="file.disliked ? 'text-red-500' : 'text-muted-foreground'">
                                    <Icon name="thumbsDown" class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardHeader>
            </Card>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Left Column: Image Display -->
                <div class="space-y-6">
                    <!-- Image Display -->
                    <Card>
                        <CardContent class="p-0">
                            <div class="relative overflow-hidden rounded-lg">
                                <div class="relative aspect-auto">
                                    <img
                                        :src="imageUrl"
                                        :alt="file.name"
                                        class="h-full w-full cursor-pointer rounded-lg object-contain max-h-96"
                                        @click="openImageViewerWithFile"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right Column: Metadata -->
                <div class="space-y-6 lg:col-span-2">
                    <!-- Organized Metadata -->
                    <div v-if="Object.keys(organizedMetadata).length > 0" class="space-y-4">
                        <Card v-for="(section, sectionName) in organizedMetadata" :key="sectionName">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <Icon
                                        :name="
                                            sectionName === 'Basic Info'
                                                ? 'info'
                                                : sectionName === 'Image Properties'
                                                  ? 'image'
                                                  : sectionName === 'Technical'
                                                    ? 'settings'
                                                    : 'moreHorizontal'
                                        "
                                        class="h-5 w-5"
                                    />
                                    {{ sectionName }}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                    <div v-for="(value, key) in section" :key="key" class="flex flex-col space-y-1 rounded-md bg-muted/30 p-3">
                                        <span class="text-sm font-medium tracking-wide text-muted-foreground uppercase">{{ key }}</span>
                                        <span class="text-sm">{{ value }}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <!-- Raw Metadata Fallback -->
                    <Card v-else-if="rawMetadata && Object.keys(rawMetadata).length > 0">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Icon name="code" class="h-5 w-5" />
                                Raw Metadata
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre class="max-h-96 overflow-auto rounded-md bg-muted/50 p-4 text-xs">{{ JSON.stringify(rawMetadata, null, 2) }}</pre>
                        </CardContent>
                    </Card>

                    <!-- No Metadata -->
                    <Card v-else>
                        <CardContent class="flex items-center justify-center py-8">
                            <div class="text-center text-muted-foreground">
                                <Icon name="fileQuestion" class="mx-auto mb-2 h-12 w-12" />
                                <p>No metadata available for this image</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Full Screen Image Viewer Modal -->
        <div
            v-if="isImageViewerOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/90"
            @click="closeImageViewer"
        >
            <div class="relative h-full w-full">
                <!-- Close Button -->
                <Button
                    variant="outline"
                    size="sm"
                    class="absolute top-4 right-4 z-10 bg-white/10 backdrop-blur-sm hover:bg-white/20"
                    @click="closeImageViewer"
                >
                    <Icon name="x" class="h-4 w-4" />
                </Button>

                <!-- Zoom Controls -->
                <div class="absolute top-4 left-4 z-10 flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click="zoomOut"
                    >
                        <Icon name="zoomOut" class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click="resetZoom"
                    >
                        <Icon name="maximize" class="h-4 w-4" />
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="bg-white/10 backdrop-blur-sm hover:bg-white/20"
                        @click="zoomIn"
                    >
                        <Icon name="zoomIn" class="h-4 w-4" />
                    </Button>
                </div>

                <!-- Zoom Level Indicator -->
                <div class="absolute bottom-4 left-4 z-10 rounded bg-white/10 px-2 py-1 text-sm text-white backdrop-blur-sm">
                    {{ Math.round(imageViewerZoom * 100) }}%
                </div>

                <!-- Image Container -->
                <div
                    class="flex h-full w-full items-center justify-center overflow-hidden"
                    @mousedown="startDrag"
                    @mousemove="onDrag"
                    @mouseup="stopDrag"
                    @mouseleave="stopDrag"
                >
                    <img
                        :src="zoomImageUrl"
                        :alt="currentImage?.name || file.name"
                        :style="{
                            transform: `scale(${imageViewerZoom}) translate(${imageViewerPosition.x / imageViewerZoom}px, ${imageViewerPosition.y / imageViewerZoom}px)`,
                            cursor: imageViewerZoom > 1 ? (isDragging ? 'grabbing' : 'grab') : 'default'
                        }"
                        class="max-h-full max-w-full object-contain transition-transform"
                        @click.stop
                        @dragstart.prevent
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
