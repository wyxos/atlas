<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import { BarChart } from '@/components/ui/bar-chart';

interface FileStats {
    totalFiles: number;
    audioFiles: number;
    videoFiles: number;
    imageFiles: number;
    otherFiles: number;
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

// Prepare data for the bar chart
const chartData = [
    { name: 'Total Files', value: props.fileStats.totalFiles },
    { name: 'Audio Files', value: props.fileStats.audioFiles },
    { name: 'Video Files', value: props.fileStats.videoFiles },
    { name: 'Image Files', value: props.fileStats.imageFiles },
    { name: 'Other Files', value: props.fileStats.otherFiles },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <!-- File Statistics Bar Chart -->
            <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6">
                <h2 class="text-xl font-semibold mb-4">File Statistics</h2>
                <BarChart :data="chartData" />
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
