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
import { router } from '@inertiajs/vue3';

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
    covers: Array<{
        id: number;
        path: string;
        hash: string;
    }>;
    artists: Array<{
        id: number;
        name: string;
    }>;
    albums: Array<{
        id: number;
        name: string;
        covers?: Array<{
            id: number;
            path: string;
            hash: string;
        }>;
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
        title: 'Files',
        href: route('audio'),
    },
    {
        title: props.file.name,
        href: route('files.show', { file: props.file.id }),
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

// Helper function to format dates (using imported formatDate from utils)

// Get file type icon
const getFileTypeIcon = (mimeType: string): string => {
    if (mimeType?.startsWith('audio/')) return 'music';
    if (mimeType?.startsWith('video/')) return 'video';
    if (mimeType?.startsWith('image/')) return 'image';
    return 'file';
};

// Get file type color
const getFileTypeColor = (mimeType: string): string => {
    if (mimeType?.startsWith('audio/')) return 'text-blue-600';
    if (mimeType?.startsWith('video/')) return 'text-purple-600';
    if (mimeType?.startsWith('image/')) return 'text-green-600';
    return 'text-gray-600';
};

// Computed property to get all available covers with priority: album covers first, then file covers
const availableCovers = computed(() => {
    const covers: Array<{ id: number; path: string; hash: string }> = [];

    // First add album covers
    if (props.file.albums && props.file.albums.length > 0) {
        for (const album of props.file.albums) {
            if (album.covers && album.covers.length > 0) {
                covers.push(...album.covers);
            }
        }
    }

    return covers;
});

// Carousel state
const currentSlide = ref(0);
const isDragging = ref(false);

// Carousel functions
const nextSlide = () => {
    if (availableCovers.value.length > 0) {
        currentSlide.value = (currentSlide.value + 1) % availableCovers.value.length;
    }
};

const prevSlide = () => {
    if (availableCovers.value.length > 0) {
        currentSlide.value = currentSlide.value === 0 ? availableCovers.value.length - 1 : currentSlide.value - 1;
    }
};

const goToSlide = (index: number) => {
    currentSlide.value = index;
};

// Drag and drop functions
const handleDragEnter = (event: DragEvent) => {
    event.preventDefault();
    isDragging.value = true;
};

const handleDragOver = (event: DragEvent) => {
    event.preventDefault();
};

const handleDragLeave = (event: DragEvent) => {
    event.preventDefault();
    // Only set isDragging to false if we're actually leaving the drop zone
    // Check if the related target is outside the current target
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const x = event.clientX;
    const y = event.clientY;

    if (x < rect.left || x > rect.right || y < rect.top || y > rect.bottom) {
        isDragging.value = false;
    }
};

const handleDrop = async (event: DragEvent, coverIndex: number) => {
    event.preventDefault();
    isDragging.value = false;

    const files = event.dataTransfer?.files;
    if (!files || files.length === 0) return;

    const file = files[0];
    if (!file.type.startsWith('image/')) {
        alert('Please drop an image file');
        return;
    }

    const coverId = availableCovers.value[coverIndex]?.id;

    try {
        // Use Inertia router to upload the new cover
        router.post(route('covers.update', { coverId: coverId }), {
            file: file,
        }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                // The page will be refreshed with updated covers
            },
            onError: (errors) => {
                console.error('Error uploading cover:', errors);
                alert('Failed to upload cover image');
            }
        });
    } catch (error) {
        console.error('Error uploading cover:', error);
        alert('Failed to upload cover image');
    }
};

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
        'Audio': {},
        'Technical': {},
        'Other': {}
    };

    Object.entries(metadataToProcess).forEach(([key, value]) => {
        if (!value || value === '') return; // Skip empty values

        const lowerKey = key.toLowerCase();

        if (['title', 'artist', 'album', 'date', 'genre', 'track', 'albumartist'].includes(lowerKey)) {
            organized['Basic Info'][key] = value;
        } else if (['duration', 'bitrate', 'samplerate', 'channels', 'encoding'].includes(lowerKey)) {
            organized['Audio'][key] = value;
        } else if (['filesize', 'format', 'codec', 'container'].includes(lowerKey)) {
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
                            <!--                            <Button -->
                            <!--                                v-if="file.url" -->
                            <!--                                variant="outline" -->
                            <!--                                size="sm" -->
                            <!--                                asChild-->
                            <!--                            >-->
                            <!--                                <Link :href="file.url" target="_blank">-->
                            <!--                                    <Icon name="externalLink" class="h-4 w-4 mr-2" />-->
                            <!--                                    Open File-->
                            <!--                                </Link>-->
                            <!--                            </Button>-->
                            <!--                            <Button -->
                            <!--                                v-else-->
                            <!--                                variant="outline" -->
                            <!--                                size="sm" -->
                            <!--                                disabled-->
                            <!--                            >-->
                            <!--                                <Icon name="fileX" class="h-4 w-4 mr-2" />-->
                            <!--                                File Not Available-->
                            <!--                            </Button>-->

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
                <!-- Left Column: Cover Art & Details -->
                <div class="space-y-6">
                    <!-- Cover Art Carousel -->
                    <Card v-if="availableCovers.length > 0">
                        <CardContent class="p-0">
                            <div class="relative overflow-hidden rounded-lg">
                                <!-- Carousel Container -->
                                <div class="relative aspect-square">
                                    <!-- Current Image with Drag/Drop -->
                                    <div
                                        class="absolute inset-0 transition-all duration-300"
                                        :class="isDragging ? 'border-2 border-dashed border-blue-300 bg-blue-50' : ''"
                                        @dragenter="handleDragEnter"
                                        @dragover="handleDragOver"
                                        @dragleave="handleDragLeave"
                                        @drop="(event) => handleDrop(event, currentSlide)"
                                    >
                                        <img
                                            :src="`/atlas/${availableCovers[currentSlide].path}`"
                                            alt="Cover Art"
                                            class="h-full w-full rounded-lg object-cover"
                                            :class="isDragging ? 'opacity-50' : ''"
                                        />

                                        <!-- Drag Overlay -->
                                        <div v-if="isDragging" class="absolute inset-0 flex items-center justify-center rounded-lg bg-blue-50/80">
                                            <div class="text-center">
                                                <Icon name="upload" class="mx-auto mb-2 h-12 w-12 text-blue-500" />
                                                <p class="font-medium text-blue-700">Drop image to replace</p>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Navigation Buttons -->
                                    <div v-if="availableCovers.length > 1" class="absolute inset-0 flex items-center justify-between p-4">
                                        <Button variant="outline" size="sm" class="bg-white/80 backdrop-blur-sm hover:bg-white/90" @click="prevSlide">
                                            <Icon name="chevronLeft" class="h-4 w-4" />
                                        </Button>
                                        <Button variant="outline" size="sm" class="bg-white/80 backdrop-blur-sm hover:bg-white/90" @click="nextSlide">
                                            <Icon name="chevronRight" class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </div>

                                <!-- Dots Indicator -->
                                <div v-if="availableCovers.length > 1" class="flex justify-center gap-2 p-4">
                                    <button
                                        v-for="(cover, index) in availableCovers"
                                        :key="cover.id"
                                        class="h-2 w-2 rounded-full transition-colors"
                                        :class="index === currentSlide ? 'bg-primary' : 'bg-muted-foreground/30'"
                                        @click="goToSlide(index)"
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Artists -->
                    <Card v-if="file.artists && file.artists.length > 0">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Icon name="users" class="h-5 w-5" />
                                Artists
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <div
                                    v-for="artist in file.artists"
                                    :key="artist.id"
                                    class="flex items-center gap-2 rounded-md p-2 transition-colors hover:bg-muted"
                                >
                                    <Icon name="user" class="h-4 w-4 text-muted-foreground" />
                                    <span>{{ artist.name }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Albums -->
                    <Card v-if="file.albums && file.albums.length > 0">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Icon name="disc" class="h-5 w-5" />
                                Albums
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="space-y-2">
                                <div
                                    v-for="album in file.albums"
                                    :key="album.id"
                                    class="flex items-center gap-2 rounded-md p-2 transition-colors hover:bg-muted"
                                >
                                    <Icon name="disc" class="h-4 w-4 text-muted-foreground" />
                                    <span>{{ album.name }}</span>
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
                                                : sectionName === 'Audio'
                                                  ? 'music'
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
                                <p>No metadata available for this file</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
