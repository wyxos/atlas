<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import { BarChart } from '@/components/ui/bar-chart';
import { PieChart } from '@/components/ui/pie-chart';

interface FileStats {
    totalFiles: number;
    audioFiles: number;
    videoFiles: number;
    imageFiles: number;
    otherFiles: number;
    notFoundFiles: number;
    withoutMetadataFiles: number;
    requiresReviewFiles: number;
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

// Prepare data for the bar chart (file status breakdown)
const fileStatusData = [
    { name: 'Not Found', value: props.fileStats.notFoundFiles },
    { name: 'No Metadata', value: props.fileStats.withoutMetadataFiles },
    { name: 'Needs Review', value: props.fileStats.requiresReviewFiles },
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
            <!-- Charts Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <!-- File Types Pie Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4">File Distribution & Space Usage</h2>
                    <PieChart :data="fileTypeData" />
                    <!-- Space Usage Bar -->
                    <div class="mt-4 space-y-3">
                        <div class="flex justify-between items-center">
                            <div class="text-sm font-medium">Space Usage</div>
                            <div class="text-sm font-medium">{{ formatFileSize(fileStats.audioSize + fileStats.videoSize + fileStats.imageSize + fileStats.otherSize) }}</div>
                        </div>
                        
                        <!-- Segmented Progress Bar -->
                        <div class="w-full bg-sidebar-border/30 rounded-full h-3 overflow-hidden">
                            <div class="h-full flex">
                                <!-- Audio Segment -->
                                <div 
                                    class="bg-blue-600 transition-all duration-300"
                                    :style="{ width: getSpacePercentage(fileStats.audioSize) + '%' }"
                                    :title="`Audio: ${formatFileSize(fileStats.audioSize)}`"
                                ></div>
                                <!-- Video Segment -->
                                <div 
                                    class="bg-emerald-600 transition-all duration-300"
                                    :style="{ width: getSpacePercentage(fileStats.videoSize) + '%' }"
                                    :title="`Video: ${formatFileSize(fileStats.videoSize)}`"
                                ></div>
                                <!-- Image Segment -->
                                <div 
                                    class="bg-purple-600 transition-all duration-300"
                                    :style="{ width: getSpacePercentage(fileStats.imageSize) + '%' }"
                                    :title="`Images: ${formatFileSize(fileStats.imageSize)}`"
                                ></div>
                                <!-- Other Segment -->
                                <div 
                                    class="bg-red-600 transition-all duration-300"
                                    :style="{ width: getSpacePercentage(fileStats.otherSize) + '%' }"
                                    :title="`Other: ${formatFileSize(fileStats.otherSize)}`"
                                ></div>
                            </div>
                        </div>
                        
                        <!-- Space Legend -->
                        <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-blue-600 rounded-sm"></div>
                                <span class="text-muted-foreground">Audio:</span>
                                <span class="font-medium ml-auto">{{ formatFileSize(fileStats.audioSize) }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-emerald-600 rounded-sm"></div>
                                <span class="text-muted-foreground">Video:</span>
                                <span class="font-medium ml-auto">{{ formatFileSize(fileStats.videoSize) }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-purple-600 rounded-sm"></div>
                                <span class="text-muted-foreground">Images:</span>
                                <span class="font-medium ml-auto">{{ formatFileSize(fileStats.imageSize) }}</span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-red-600 rounded-sm"></div>
                                <span class="text-muted-foreground">Other:</span>
                                <span class="font-medium ml-auto">{{ formatFileSize(fileStats.otherSize) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- File Status Bar Chart -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                    <h2 class="text-lg font-semibold mb-4">File Status Issues</h2>
                    <BarChart :data="fileStatusData" />
                    <div class="mt-4 text-xs text-muted-foreground space-y-1">
                        <div><strong>Not Found:</strong> Files flagged as missing/inaccessible</div>
                        <div><strong>No Metadata:</strong> Files without extracted metadata</div>
                        <div><strong>Needs Review:</strong> Files requiring manual review</div>
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
