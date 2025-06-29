<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import {Head, router} from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import { Play, Pause } from 'lucide-vue-next';
import {ref, computed, watch} from 'vue';
import { Input } from '@/components/ui/input';
import debounce from 'lodash/debounce';

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
    search: any[];
}>();

// Audio player state
const audioPlayer = ref<HTMLAudioElement | null>(null);
const currentFile = ref<any>(null);
const isPlaying = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const volume = ref(1); // Default volume (0-1)

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

// Format time in MM:SS format
function formatTime(seconds: number): string {
    if (isNaN(seconds) || !isFinite(seconds)) return '00:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
}

// Seek to a specific position in the audio
function seekTo(event: MouseEvent): void {
    if (!audioPlayer.value || !duration.value) return;

    const progressBar = event.currentTarget as HTMLElement;
    const rect = progressBar.getBoundingClientRect();
    const offsetX = event.clientX - rect.left;
    const percentage = offsetX / rect.width;

    audioPlayer.value.currentTime = percentage * duration.value;
}

// Set volume
// function setVolume(value: number): void {
//     if (!audioPlayer.value) return;
//
//     // Ensure volume is between 0 and 1
//     const newVolume = Math.max(0, Math.min(1, value));
//     audioPlayer.value.volume = newVolume;
//     volume.value = newVolume;
// }

const query = ref('');

const debouncedSearch = debounce((newQuery: string|null, oldQuery: string|null) => {
    if (newQuery && newQuery.trim()) {
        console.log('Searching for:', newQuery);
        router.get(route('audio'), { query: newQuery }, {
            preserveState: true,
            only: ['search'],
            replace: true,
            onSuccess: () => {
                console.log('Search completed');
            },
            onError: (error) => {
                console.error('Search error:', error);
            }
        });
    }

    console.log('Old query:', oldQuery, 'New query:', newQuery);

    if(oldQuery && !newQuery) {
        console.log('Clearing search, resetting results');
        // If query is cleared, reset search results
        router.get(route('audio'), {}, {
            preserveState: true,
            only: ['search'],
            replace: true,
            onSuccess: () => {
                console.log('Search reset');
            },
            onError: (error) => {
                console.error('Reset error:', error);
            }
        });
    }
}, 500); // adjust delay as needed (ms)

watch(query, (newQuery, oldQuery) => {
    debouncedSearch(newQuery, oldQuery);
});
</script>

<template>
    <Head title="Audio" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="h-full flex flex-col">
            <div>
                <Input type="search" placeholder="Search" v-model="query" />
            </div>
            <div class="flex-1">
                <RecycleScroller class="h-[640px]" :items="query ? search : files" :item-size="40 + 16 + 16" key-field="id" v-slot="{ item }">
                    <div class="file p-4 flex justify-between items-center hover:bg-gray-100 rounded border-b-2 border-blue-200" :class="{ 'bg-blue-500': currentFile?.id === item.id }">
                        <div class="flex flex-col">
                            <span class="text-xs">{{ excerpt(item.metadata?.payload?.artist, 25) || 'Untitled' }}</span>
                            <span>{{ excerpt(item.metadata?.payload?.title, 25) || 'Untitled' }}</span>
                        </div>
                        <button class="cursor-pointer" @click="playAudio(item)">
                            <Play v-if="!isPlaying || currentFile?.id !== item.id" :size="20" />
                            <Pause v-else :size="20" />
                        </button>
                    </div>
                </RecycleScroller>
            </div>

            <div class="bg-blue-950 p-4 border-t fixed bottom-0 left-0 right-0">
                <audio
                    ref="audioPlayer"
                    class="hidden"
                    @ended="isPlaying = false"
                    @timeupdate="currentTime = audioPlayer?.currentTime || 0"
                    @loadedmetadata="duration = audioPlayer?.duration || 0"
                    @volumechange="volume = audioPlayer?.volume || 1"
                ></audio>

                <!-- Title and controls -->
                <div class="flex items-center mb-2">
                    <div class="flex-1">
                        <div class="font-medium text-white">{{ excerpt(currentTitle) }}</div>
                    </div>
                    <div v-if="currentFile" class="flex items-center">
                        <button class="cursor-pointer text-white" @click="playAudio(currentFile)">
                            <Play v-if="!isPlaying" :size="24" />
                            <Pause v-else :size="24" />
                        </button>
                    </div>
                </div>

                <!-- Progress bar -->
                <div v-if="currentFile" class="mb-2">
                    <div
                        class="h-2 bg-gray-700 rounded-full cursor-pointer mb-2"
                        @click="seekTo($event)"
                    >
                        <div
                            class="h-full bg-blue-500 rounded-full"
                            :style="{ width: `${(currentTime / duration) * 100 || 0}%` }"
                        ></div>
                    </div>
                    <div class="flex justify-between text-xs text-white mb-2">
                        <span>{{ formatTime(currentTime) }}</span>
                        <span>{{ formatTime(duration) }}</span>
                    </div>
                </div>

<!--                &lt;!&ndash; Volume control &ndash;&gt;-->
<!--                <div v-if="currentFile" class="flex items-center">-->
<!--                    <span class="text-xs text-white mr-2">Volume</span>-->
<!--                    <input-->
<!--                        type="range"-->
<!--                        min="0"-->
<!--                        max="1"-->
<!--                        step="0.01"-->
<!--                        :value="volume"-->
<!--                        @input="setVolume(parseFloat(($event.target as HTMLInputElement).value))"-->
<!--                        class="w-24"-->
<!--                    />-->
<!--                </div>-->
            </div>
        </div>
    </AppLayout>
</template>
