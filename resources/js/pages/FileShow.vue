<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
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
import { computed } from 'vue';
import { formatDate } from '@/lib/utils';

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
        href: '/files',
    },
    {
        title: props.file.name,
        href: `/files/${props.file.id}`,
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
        Object.entries(organized).filter(([_, section]) => Object.keys(section).length > 0)
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
                                <CardDescription class="flex items-center gap-2 mt-1">
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
                            <Button 
                                v-if="file.url" 
                                variant="outline" 
                                size="sm" 
                                asChild
                            >
                                <Link :href="file.url" target="_blank">
                                    <Icon name="externalLink" class="h-4 w-4 mr-2" />
                                    Open File
                                </Link>
                            </Button>
                            <Button 
                                v-else
                                variant="outline" 
                                size="sm" 
                                disabled
                            >
                                <Icon name="fileX" class="h-4 w-4 mr-2" />
                                File Not Available
                            </Button>
                            
                            <!-- Rating Buttons -->
                            <div class="flex items-center gap-1 ml-2">
                                <Button 
                                    variant="ghost" 
                                    size="sm"
                                    :class="file.loved ? 'text-red-500' : 'text-muted-foreground'"
                                >
                                    <Icon name="heart" class="h-4 w-4" />
                                </Button>
                                <Button 
                                    variant="ghost" 
                                    size="sm"
                                    :class="file.liked ? 'text-green-500' : 'text-muted-foreground'"
                                >
                                    <Icon name="thumbsUp" class="h-4 w-4" />
                                </Button>
                                <Button 
                                    variant="ghost" 
                                    size="sm"
                                    :class="file.disliked ? 'text-red-500' : 'text-muted-foreground'"
                                >
                                    <Icon name="thumbsDown" class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </CardHeader>
            </Card>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Left Column: Cover Art & Details -->
                <div class="space-y-6">
                    <!-- Cover Art -->
                    <Card v-if="file.covers && file.covers.length > 0">
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Icon name="image" class="h-5 w-5" />
                                Cover Art
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div class="grid gap-4">
                                <img
                                    v-for="cover in file.covers"
                                    :key="cover.id"
                                    :src="`/storage/${cover.path}`"
                                    alt="Cover Art"
                                    class="w-full rounded-lg shadow-md hover:shadow-lg transition-shadow"
                                />
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
                                    class="flex items-center gap-2 p-2 rounded-md hover:bg-muted transition-colors"
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
                                    class="flex items-center gap-2 p-2 rounded-md hover:bg-muted transition-colors"
                                >
                                    <Icon name="disc" class="h-4 w-4 text-muted-foreground" />
                                    <span>{{ album.name }}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <!-- Right Column: Metadata -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Organized Metadata -->
                    <div v-if="Object.keys(organizedMetadata).length > 0" class="space-y-4">
                        <Card v-for="(section, sectionName) in organizedMetadata" :key="sectionName">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <Icon 
                                        :name="sectionName === 'Basic Info' ? 'info' : sectionName === 'Audio' ? 'music' : sectionName === 'Technical' ? 'settings' : 'moreHorizontal'" 
                                        class="h-5 w-5" 
                                    />
                                    {{ sectionName }}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div 
                                        v-for="(value, key) in section" 
                                        :key="key"
                                        class="flex flex-col space-y-1 p-3 rounded-md bg-muted/30"
                                    >
                                        <span class="text-sm font-medium text-muted-foreground uppercase tracking-wide">{{ key }}</span>
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
                            <pre class="text-xs bg-muted/50 p-4 rounded-md overflow-auto max-h-96">{{ JSON.stringify(rawMetadata, null, 2) }}</pre>
                        </CardContent>
                    </Card>

                    <!-- No Metadata -->
                    <Card v-else>
                        <CardContent class="flex items-center justify-center py-8">
                            <div class="text-center text-muted-foreground">
                                <Icon name="fileQuestion" class="h-12 w-12 mx-auto mb-2" />
                                <p>No metadata available for this file</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
