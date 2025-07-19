<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import { PieChart } from '@/components/ui/pie-chart';
import { BarChart } from '@/components/ui/bar-chart';
import { HorizontalBarChart } from '@/components/ui/horizontal-bar-chart';
import { ProgressCard } from '@/components/ui/progress-card';
import Icon from '@/components/Icon.vue';
import { ref } from 'vue';

interface FileStats {
    // Audio Count & Space Usage
    audioFilesCount: number;
    audioSpaceUsed: number;
    audioNotFound: number;

    // Video Count & Space Usage
    videoFilesCount: number;
    videoSpaceUsed: number;
    videoNotFound: number;

    // Image Count & Space Usage
    imageFilesCount: number;
    imageSpaceUsed: number;
    imageNotFound: number;

    // Total Files Not Found
    totalFilesNotFound: number;

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

    // Disk Space Information
    diskSpaceTotal: number;
    diskSpaceUsed: number;
    diskSpaceFree: number;
    diskSpaceUsedPercent: number;
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

// File Type Distribution - Keep as Pie Chart (good for proportions)
const fileTypeData = [
    { name: 'Audio Files', value: props.fileStats.audioFiles },
    { name: 'Video Files', value: props.fileStats.videoFiles },
    { name: 'Image Files', value: props.fileStats.imageFiles },
    { name: 'Other Files', value: props.fileStats.otherFiles },
];

// Storage Usage - Better as Bar Chart for size comparison
const storageUsageData = [
    { name: 'Audio', value: props.fileStats.audioSize },
    { name: 'Video', value: props.fileStats.videoSize },
    { name: 'Image', value: props.fileStats.imageSize },
    { name: 'Other', value: props.fileStats.otherSize },
];

// File Type Comparison - Vertical Bar Chart
const fileTypeComparisonData = [
    { name: 'Audio Files', value: props.fileStats.audioFiles },
    { name: 'Video Files', value: props.fileStats.videoFiles },
    { name: 'Image Files', value: props.fileStats.imageFiles },
];

// Rating Data - Better as Horizontal Bar Charts
const globalRatingData = [
    { name: 'Loved', value: props.fileStats.globalLoved },
    { name: 'Liked', value: props.fileStats.globalLiked },
    { name: 'Disliked', value: props.fileStats.globalDisliked },
    { name: 'Funny', value: props.fileStats.globalLaughedAt },
    { name: 'No Rating', value: props.fileStats.globalNoRating },
];

const audioRatingData = [
    { name: 'Loved', value: props.fileStats.audioLoved },
    { name: 'Liked', value: props.fileStats.audioLiked },
    { name: 'Disliked', value: props.fileStats.audioDisliked },
    { name: 'Funny', value: props.fileStats.audioLaughedAt },
    { name: 'No Rating', value: props.fileStats.audioNoRating },
];

const videoRatingData = [
    { name: 'Loved', value: props.fileStats.videoLoved },
    { name: 'Liked', value: props.fileStats.videoLiked },
    { name: 'Disliked', value: props.fileStats.videoDisliked },
    { name: 'Funny', value: props.fileStats.videoLaughedAt },
    { name: 'No Rating', value: props.fileStats.videoNoRating },
];

const imageRatingData = [
    { name: 'Loved', value: props.fileStats.imageLoved },
    { name: 'Liked', value: props.fileStats.imageLiked },
    { name: 'Disliked', value: props.fileStats.imageDisliked },
    { name: 'Funny', value: props.fileStats.imageLaughedAt },
    { name: 'No Rating', value: props.fileStats.imageNoRating },
];

// Metadata totals for progress cards
const metadataTotal = props.fileStats.globalWithMetadata + props.fileStats.globalWithoutMetadata;
const reviewTotal = props.fileStats.globalMetadataReviewRequired + props.fileStats.globalMetadataReviewNotRequired;

// Disk Space with File Types Chart Data
const diskSpaceWithFileTypesData = [
    { name: 'Audio Files', value: Math.round(props.fileStats.audioSize / (1024 * 1024)) },
    { name: 'Video Files', value: Math.round(props.fileStats.videoSize / (1024 * 1024)) },
    { name: 'Image Files', value: Math.round(props.fileStats.imageSize / (1024 * 1024)) },
    { name: 'Other Files', value: Math.round(props.fileStats.otherSize / (1024 * 1024)) },
    { name: 'Free Space', value: Math.round(props.fileStats.diskSpaceFree / (1024 * 1024)) },
];

// Not Found Files Chart Data
const notFoundData = [
    { name: 'Audio Not Found', value: props.fileStats.audioNotFound },
    { name: 'Video Not Found', value: props.fileStats.videoNotFound },
    { name: 'Image Not Found', value: props.fileStats.imageNotFound },
];

// Helper function to format file sizes
const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

// Cache busting functionality
const isRefreshing = ref(false);

const refreshStats = (): void => {
    if (isRefreshing.value) return;

    isRefreshing.value = true;

    router.post(route('dashboard.clear-cache'), {}, {
        preserveState: false,
        preserveScroll: true,
        onFinish: () => {
            isRefreshing.value = false;
        }
    });
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <!-- Refresh Stats Button -->
            <div class="flex justify-end">
                <button
                    @click="refreshStats"
                    :disabled="isRefreshing"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium rounded-lg transition-colors duration-200"
                    :class="{ 'cursor-not-allowed': isRefreshing }"
                >
                    <Icon
                        :name="isRefreshing ? 'loader' : 'refresh'"
                        :class="['h-4 w-4', { 'animate-spin': isRefreshing }]"
                    />
                    {{ isRefreshing ? 'Refreshing...' : 'Refresh Stats' }}
                </button>
            </div>

            <!-- Disk Space & Not Found Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- Disk Space with File Types Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="hardDrive" class="h-5 w-5 text-foreground" />
                        Disk Space Overview
                    </h2>
                    <PieChart :data="diskSpaceWithFileTypesData" />
                    <div class="mt-4 text-center">
                        <div class="text-sm text-muted-foreground">
                            {{ formatFileSize(fileStats.diskSpaceUsed) }} used of {{ formatFileSize(fileStats.diskSpaceTotal) }} 
                            ({{ fileStats.diskSpaceUsedPercent }}%)
                        </div>
                    </div>
                </div>

                <!-- Not Found Files Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="alertTriangle" class="h-5 w-5 text-foreground" />
                        Files Not Found
                    </h2>
                    <BarChart :data="notFoundData" colorScheme="status" />
                    <div class="mt-4 text-center">
                        <div class="text-sm text-muted-foreground">
                            Total: {{ fileStats.totalFilesNotFound.toLocaleString() }} files not found
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overview Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <!-- File Types Pie Chart - Keep as pie (good for proportions) -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="pieChart" class="h-5 w-5 text-foreground" />
                        File Distribution
                    </h2>
                    <PieChart :data="fileTypeData" />
                </div>

                <!-- File Type Comparison Bar Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="barChart" class="h-5 w-5 text-foreground" />
                        File Type Count
                    </h2>
                    <BarChart :data="fileTypeComparisonData" colorScheme="default" />
                </div>

                <!-- Storage Usage Bar Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="hardDrive" class="h-5 w-5 text-foreground" />
                        Storage Usage
                    </h2>
                    <BarChart :data="storageUsageData.map(item => ({ name: item.name, value: Math.round(item.value / (1024 * 1024)) }))" colorScheme="default" />
                    <div class="mt-2 text-xs text-muted-foreground text-center">Values in MB</div>
                </div>

                <!-- Global Ratings Horizontal Bar Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="star" class="h-5 w-5 text-foreground" />
                        Global Ratings
                    </h2>
                    <HorizontalBarChart :data="globalRatingData" colorScheme="rating" />
                </div>
            </div>

            <!-- Metadata Status Progress Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <ProgressCard 
                    title="Files with Metadata"
                    :value="fileStats.globalWithMetadata"
                    :total="metadataTotal"
                    variant="success"
                />
                <ProgressCard 
                    title="Metadata Requiring Review"
                    :value="fileStats.globalMetadataReviewRequired"
                    :total="reviewTotal"
                    variant="warning"
                />
            </div>

            <!-- Ratings by Media Type -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Audio Ratings -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="music" class="h-5 w-5 text-foreground" />
                        Audio Ratings
                    </h2>
                    <HorizontalBarChart :data="audioRatingData" colorScheme="rating" />
                </div>

                <!-- Video Ratings -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="video" class="h-5 w-5 text-foreground" />
                        Video Ratings
                    </h2>
                    <HorizontalBarChart :data="videoRatingData" colorScheme="rating" />
                </div>

                <!-- Image Ratings -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4 flex items-center gap-2">
                        <Icon name="image" class="h-5 w-5 text-foreground" />
                        Image Ratings
                    </h2>
                    <HorizontalBarChart :data="imageRatingData" colorScheme="rating" />
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
