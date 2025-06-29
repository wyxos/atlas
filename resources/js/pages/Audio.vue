<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { Play, Pause } from 'lucide-vue-next';
import { ref, computed } from 'vue';

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

// Audio player state
const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentFile = ref<any>(null);
const isPlaying = ref(false);

// Play the selected audio file
function playAudio(file: any): void {
    if (currentFile.value && currentFile.value.id === file.id) {
        // Toggle play/pause if it's the same file
        if (isPlaying.value) {
            audioPlayer.value?.pause();
            isPlaying.value = false;
        } else {
            audioPlayer.value?.play();
            isPlaying.value = true;
        }
    } else {
        // Play a new file
        currentFile.value = file;
        if (audioPlayer.value) {
            // Use the streaming route instead of direct file path
            audioPlayer.value.src = `/audio/stream/${file.id}`;
            audioPlayer.value.play()
                .then(() => {
                    isPlaying.value = true;
                })
                .catch(error => {
                    console.error('Error playing audio:', error);
                    isPlaying.value = false;
                });
        }
    }
}

// Get the current file title for display
const currentTitle = computed(() => {
    if (!currentFile.value) return 'No file selected';
    return currentFile.value.metadata?.payload?.title || 'Untitled';
});

function excerpt(text: string, length = 30): string {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
}
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col">
            <div class="flex-1">
                <RecycleScroller class="h-[700px]" :items="files" :item-size="24 + 16 + 16" key-field="id" v-slot="{ item }">
                    <div class="file p-4 flex justify-between items-center hover:bg-gray-100 rounded" :class="{ 'bg-blue-500': currentFile?.id === item.id }">
                        {{ excerpt(item.metadata?.payload?.title) || 'Untitled' }}
                        <button class="cursor-pointer" @click="playAudio(item)">
                            <Play v-if="!isPlaying || currentFile?.id !== item.id" :size="20" />
                            <Pause v-else :size="20" />
                        </button>
                    </div>
                </RecycleScroller>
            </div>

            <div class="bg-blue-950 p-4 border-t flex items-center">
                <audio ref="audioPlayer" class="hidden" @ended="isPlaying = false"></audio>
                <div class="flex-1">
                    <div class="font-medium">{{ excerpt(currentTitle) }}</div>
                </div>
                <div v-if="currentFile" class="flex items-center">
                    <button class="cursor-pointer" @click="playAudio(currentFile)">
                        <Play v-if="!isPlaying" :size="24" />
                        <Pause v-else :size="24" />
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
