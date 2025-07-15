<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

// Import our components
import AudioList from '@/components/audio/AudioList.vue';

const props = defineProps<{
    files: any[];
    search: any[];
    title?: string;
}>();

const page = usePage();

// Determine the current route name for search context
const currentSearchRoute = computed(() => {
    const currentUrl = page.url;
    if (currentUrl.includes('/audio/favorites')) {
        return route('audio.favorites');
    } else if (currentUrl.includes('/audio/liked')) {
        return route('audio.liked');
    } else if (currentUrl.includes('/audio/disliked')) {
        return route('audio.disliked');
    } else if (currentUrl.includes('/audio/unrated')) {
        return route('audio.unrated');
    } else if (currentUrl.includes('/audio/funny')) {
        return route('audio.funny');
    } else if (currentUrl.includes('/audio/podcasts')) {
        return route('audio.podcasts');
    } else {
        return route('audio');
    }
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: props.title || 'Audio',
        href: currentSearchRoute.value,
    },
];

// Reference to the AudioList component
const audioListRef = ref<InstanceType<typeof AudioList> | null>(null);

// Handle global click by delegating to AudioList component
function handleGlobalClick(event: Event): void {
    if (audioListRef.value) {
        audioListRef.value.handleGlobalClick(event);
    }
}
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col" @click="handleGlobalClick">
            <AudioList
                ref="audioListRef"
                :files="props.files"
                :search="props.search"
                :search-route="currentSearchRoute"
            />
        </div>
    </AppLayout>
</template>
