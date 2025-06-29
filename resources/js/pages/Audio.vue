<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
    {
        title: 'Audio',
        href: '/audio',
    },
];

defineProps<{
    files: any[];
}>();

function excerpt(text: string, length = 30): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col overflow-hidden">
            <div class="flex-1 overflow-hidden">
                <RecycleScroller class="h-[700px]" :items="files" :item-size="24 + 16 + 16" key-field="id" v-slot="{ item }">
                    <div class="file p-4">
                        {{ excerpt(item.metadata?.payload?.title) || 'Untilted' }}
                    </div>
                </RecycleScroller>
            </div>

            <div class="bg-red-500 p-4">
                Player here
            </div>
        </div>
    </AppLayout>
</template>
