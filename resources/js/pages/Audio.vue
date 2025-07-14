<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

// Import our components
import AudioList from '@/components/audio/AudioList.vue';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('dashboard'),
    },
    {
        title: 'Audio',
        href: route('audio'),
    },
];

const props = defineProps<{
    files: any[];
    search: any[];
}>();

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
            />
        </div>
    </AppLayout>
</template>
