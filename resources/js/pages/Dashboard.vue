<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import { BarChart } from '@/components/ui/bar-chart';
import { PieChart } from '@/components/ui/pie-chart';
import Icon from '@/components/Icon.vue';

interface FileStats {
    // Audio Count & Space Usage
    audioFilesCount: number;
    audioSpaceUsed: number;
    audioNotFound: number;

    // Audio Metadata Stats
    audioWithMetadata: number;
    audioWithoutMetadata: number;
    audioMetadataReviewRequired: number;
    audioMetadataReviewNotRequired: number;

    // Global Metadata Stats
    globalWithMetadata: number;
    globalWithoutMetadata: number;
    globalMetadataReviewRequired: number;
    globalMetadataReviewNotRequired: number;

    // Audio Rating Stats
    audioLoved: number;
    audioLiked: number;
    audioDisliked: number;
    audioLaughedAt: number;
    audioNoRating: number;

    // Global Rating Stats
    globalLoved: number;
    globalLiked: number;
    globalDisliked: number;
    globalLaughedAt: number;
    globalNoRating: number;

    // Video Rating Stats
    videoLoved: number;
    videoLiked: number;
    videoDisliked: number;
    videoLaughedAt: number;
    videoNoRating: number;

    // Image Rating Stats
    imageLoved: number;
    imageLiked: number;
    imageDisliked: number;
    imageLaughedAt: number;
    imageNoRating: number;

    // File Type Distribution (for pie chart)
    audioFiles: number;
    videoFiles: number;
    imageFiles: number;
    otherFiles: number;
    audioSize: number;
    videoSize: number;
    imageSize: number;
    otherSize: number;
}

const props = defineProps<{
    fileStats: FileStats;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Prepare data for the pie chart (file types with space information)
const fileTypeData = [
    { name: 'Audio Files', value: props.fileStats.audioFiles, size: props.fileStats.audioSize },
    { name: 'Video Files', value: props.fileStats.videoFiles, size: props.fileStats.videoSize },
    { name: 'Image Files', value: props.fileStats.imageFiles, size: props.fileStats.imageSize },
    { name: 'Other Files', value: props.fileStats.otherFiles, size: props.fileStats.otherSize },
];

// Prepare data for the metadata with/without pie chart
const metadataWithWithoutData = [
    { name: 'With Metadata', value: props.fileStats.globalWithMetadata },
    { name: 'Without Metadata', value: props.fileStats.globalWithoutMetadata },
];

// Prepare data for the metadata review status pie chart
const metadataReviewData = [
    { name: 'Review Required', value: props.fileStats.globalMetadataReviewRequired },
    { name: 'Review Not Required', value: props.fileStats.globalMetadataReviewNotRequired },
];

// Prepare data for global rating bar chart
const globalRatingData = [
    { name: 'Loved', value: props.fileStats.globalLoved },
    { name: 'Liked', value: props.fileStats.globalLiked },
    { name: 'Disliked', value: props.fileStats.globalDisliked },
    { name: 'Funny', value: props.fileStats.globalLaughedAt },
    { name: 'No Rating', value: props.fileStats.globalNoRating },
];

// Prepare data for audio rating bar chart
const audioRatingData = [
    { name: 'Loved', value: props.fileStats.audioLoved },
    { name: 'Liked', value: props.fileStats.audioLiked },
    { name: 'Disliked', value: props.fileStats.audioDisliked },
    { name: 'Funny', value: props.fileStats.audioLaughedAt },
    { name: 'No Rating', value: props.fileStats.audioNoRating },
];

// Prepare data for video rating bar chart
const videoRatingData = [
    { name: 'Loved', value: props.fileStats.videoLoved },
    { name: 'Liked', value: props.fileStats.videoLiked },
    { name: 'Disliked', value: props.fileStats.videoDisliked },
    { name: 'Funny', value: props.fileStats.videoLaughedAt },
    { name: 'No Rating', value: props.fileStats.videoNoRating },
];

// Prepare data for image rating bar chart
const imageRatingData = [
    { name: 'Loved', value: props.fileStats.imageLoved },
    { name: 'Liked', value: props.fileStats.imageLiked },
    { name: 'Disliked', value: props.fileStats.imageDisliked },
    { name: 'Funny', value: props.fileStats.imageLaughedAt },
    { name: 'No Rating', value: props.fileStats.imageNoRating },
];

// Helper function to format file sizes
const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Helper function to calculate space usage percentages
const getSpacePercentage = (bytes: number): number => {
    const totalSpace = props.fileStats.audioSize + props.fileStats.videoSize + props.fileStats.imageSize + props.fileStats.otherSize;
    if (totalSpace === 0) return 0;
    return (bytes / totalSpace) * 100;
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <!-- Audio Count & Space Usage Block -->
            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                    <Icon name="music" class="h-5 w-5 text-foreground" />
                    Audio Count & Space Usage
                </h2>
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-blue-600">{{ fileStats.audioFilesCount.toLocaleString() }}</div>
                        <div class="text-sm text-muted-foreground">Audio Files</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-green-600">{{ formatFileSize(fileStats.audioSpaceUsed) }}</div>
                        <div class="text-sm text-muted-foreground">Space Used</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-2xl font-bold text-red-600">{{ fileStats.audioNotFound.toLocaleString() }}</div>
                        <div class="text-sm text-muted-foreground">Not Found</div>
                    </div>
                </div>
            </div>

            <!-- Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- File Types Pie Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="pieChart" class="h-5 w-5 text-foreground" />
                        File Distribution
                    </h2>
                    <PieChart :data="fileTypeData" />
                </div>

                <!-- Metadata With/Without Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="fileText" class="h-5 w-5 text-foreground" />
                        Metadata Availability
                    </h2>
                    <PieChart :data="metadataWithWithoutData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div><strong>With Metadata:</strong> Files with extracted metadata</div>
                        <div><strong>Without Metadata:</strong> Files missing metadata</div>
                    </div>
                </div>

                <!-- Metadata Review Status Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="search" class="h-5 w-5 text-foreground" />
                        Metadata Review Status
                    </h2>
                    <PieChart :data="metadataReviewData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div><strong>Review Required:</strong> Metadata flagged for review</div>
                        <div><strong>Review Not Required:</strong> Clean metadata</div>
                    </div>
                </div>

            </div>

            <!-- Rating Blocks Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-4 gap-4">
                <!-- Global Ratings Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 text-blue-700 flex items-center gap-2">
                        <Icon name="star" class="h-5 w-5 text-blue-700" />
                        Global Ratings
                    </h2>
                    <PieChart :data="globalRatingData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div class="flex items-center gap-1"><strong>Loved:</strong> <Icon name="heart" class="h-3 w-3 text-red-500" /> Most loved files</div>
                        <div class="flex items-center gap-1"><strong>Liked:</strong> <Icon name="thumbsUp" class="h-3 w-3 text-green-500" /> Liked files</div>
                        <div class="flex items-center gap-1"><strong>Disliked:</strong> <Icon name="thumbsDown" class="h-3 w-3 text-red-500" /> Disliked files</div>
                        <div class="flex items-center gap-1"><strong>Funny:</strong> <Icon name="laugh" class="h-3 w-3 text-yellow-500" /> Files that made you laugh</div>
                        <div class="flex items-center gap-1"><strong>No Rating:</strong> <Icon name="minus" class="h-3 w-3 text-gray-500" /> Unrated files</div>
                    </div>
                </div>

                <!-- Audio Ratings Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="music" class="h-5 w-5 text-foreground" />
                        Audio Ratings
                    </h2>
                    <PieChart :data="audioRatingData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div class="flex items-center gap-1"><strong>Loved:</strong> <Icon name="heart" class="h-3 w-3 text-red-500" /> Favorite audio tracks</div>
                        <div class="flex items-center gap-1"><strong>Liked:</strong> <Icon name="thumbsUp" class="h-3 w-3 text-green-500" /> Liked audio tracks</div>
                        <div class="flex items-center gap-1"><strong>Disliked:</strong> <Icon name="thumbsDown" class="h-3 w-3 text-red-500" /> Disliked audio tracks</div>
                        <div class="flex items-center gap-1"><strong>Funny:</strong> <Icon name="laugh" class="h-3 w-3 text-yellow-500" /> Audio that made you laugh</div>
                        <div class="flex items-center gap-1"><strong>No Rating:</strong> <Icon name="minus" class="h-3 w-3 text-gray-500" /> Unrated audio tracks</div>
                    </div>
                </div>

                <!-- Video Ratings Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="video" class="h-5 w-5 text-foreground" />
                        Video Ratings
                    </h2>
                    <PieChart :data="videoRatingData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div class="flex items-center gap-1"><strong>Loved:</strong> <Icon name="heart" class="h-3 w-3 text-red-500" /> Most watched videos</div>
                        <div class="flex items-center gap-1"><strong>Liked:</strong> <Icon name="thumbsUp" class="h-3 w-3 text-green-500" /> Liked videos</div>
                        <div class="flex items-center gap-1"><strong>Disliked:</strong> <Icon name="thumbsDown" class="h-3 w-3 text-red-500" /> Disliked videos</div>
                        <div class="flex items-center gap-1"><strong>Funny:</strong> <Icon name="laugh" class="h-3 w-3 text-yellow-500" /> Videos that made you laugh</div>
                        <div class="flex items-center gap-1"><strong>No Rating:</strong> <Icon name="minus" class="h-3 w-3 text-gray-500" /> Unrated videos</div>
                    </div>
                </div>

                <!-- Image Ratings Block -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="image" class="h-5 w-5 text-foreground" />
                        Image Ratings
                    </h2>
                    <PieChart :data="imageRatingData" />
                    <div class="mt-4 text-xs text-muted-foreground grid grid-cols-1 gap-1">
                        <div class="flex items-center gap-1"><strong>Loved:</strong> <Icon name="heart" class="h-3 w-3 text-red-500" /> Most loved images</div>
                        <div class="flex items-center gap-1"><strong>Liked:</strong> <Icon name="thumbsUp" class="h-3 w-3 text-green-500" /> Liked images</div>
                        <div class="flex items-center gap-1"><strong>Disliked:</strong> <Icon name="thumbsDown" class="h-3 w-3 text-red-500" /> Disliked images</div>
                        <div class="flex items-center gap-1"><strong>Funny:</strong> <Icon name="laugh" class="h-3 w-3 text-yellow-500" /> Images that made you laugh</div>
                        <div class="flex items-center gap-1"><strong>No Rating:</strong> <Icon name="minus" class="h-3 w-3 text-gray-500" /> Unrated images</div>
                    </div>
                </div>
            </div>

            <!-- Existing content -->
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
