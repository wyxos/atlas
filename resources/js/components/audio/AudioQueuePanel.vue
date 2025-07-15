<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { RecycleScroller } from 'vue-virtual-scroller';
import { X } from 'lucide-vue-next';
import { audioStore, audioActions } from '@/stores/audioStore';
import AudioListItem from '@/components/audio/AudioListItem.vue';
import { useAudioFileLoader } from '@/components/audio/useAudioFileLoader';
import { useAudioSwipeHandler } from '@/components/audio/useAudioSwipeHandler';

interface Props {
    isOpen: boolean;
}

const props = defineProps<Props>();
const emit = defineEmits<{
    close: [];
}>();

// RecycleScroller ref
const recycleScrollerRef = ref<InstanceType<typeof RecycleScroller> | null>(null);

// Use audio file loader for data loading
const { loadedFiles, loadFileDetails } = useAudioFileLoader();

// Use swipe handler for touch interactions
const { swipedItemId, handleTouchStart, handleTouchMove, handleTouchEnd } = useAudioSwipeHandler();

// Play audio function
async function playAudio(file: any): Promise<void> {
    if (!file) return;

    try {
        // Find the file in the current playlist and set it as current
        const index = audioStore.playlist.findIndex(track => track.id === file.id);
        if (index !== -1) {
            audioStore.currentIndex = index;
            await audioActions.setCurrentFile(file, loadFileDetails);
            audioActions.setPlaying(true);
        }
    } catch (error) {
        console.error('Error playing audio:', error);
    }
}

// Get file data with loaded details
function getFileData(item: any): any {
    return loadedFiles[item.id] || item;
}

// Reaction handlers (simplified for queue panel)
function handleReaction(item: any, event: Event, reactionType: string): void {
    // Implementation similar to useAudioList but simplified for queue
    console.log('Queue panel reaction:', reactionType, item.id);
}

function toggleFavorite(item: any, event: Event): void {
    handleReaction(item, event, 'loved');
}

function likeItem(item: any, event: Event): void {
    handleReaction(item, event, 'liked');
}

function dislikeItem(item: any, event: Event): void {
    handleReaction(item, event, 'disliked');
}

function laughedAtItem(item: any, event: Event): void {
    handleReaction(item, event, 'funny');
}

// Scroll to current track when panel opens
function scrollToCurrentTrack(): void {
    if (!recycleScrollerRef.value || !audioStore.currentFile || audioStore.playlist.length === 0) return;

    // Find the current track index in the playlist
    const currentIndex = audioStore.currentIndex;

    if (currentIndex !== -1 && currentIndex < audioStore.playlist.length) {
        // Use scrollToItem to scroll to the current track
        recycleScrollerRef.value.scrollToItem(currentIndex);
    }
}

// Handle scroll for prefetch/data loading
function onScroll(startIndex: number, endIndex: number, visibleStartIndex: number, visibleEndIndex: number): void {
    // Pre-load item details for visible items
    for (let i = visibleStartIndex; i <= visibleEndIndex; i++) {
        if (i >= 0 && i < audioStore.playlist.length) {
            const item = audioStore.playlist[i];
            if (item && !loadedFiles[item.id]) {
                loadFileDetails(item.id, true); // Load with priority
            }
        }
    }
}

// Watch for panel open to scroll to current track
onMounted(() => {
    if (props.isOpen) {
        // Delay scroll to ensure RecycleScroller is fully rendered
        setTimeout(() => {
            scrollToCurrentTrack();
        }, 100);
    }
});

// Scroll to current track when panel opens
function handlePanelOpen(): void {
    if (props.isOpen) {
        setTimeout(() => {
            scrollToCurrentTrack();
        }, 100);
    }
}

// Watch for isOpen changes
watch(() => props.isOpen, (newValue) => {
    if (newValue) {
        handlePanelOpen();
    }
});
</script>

<template>
    <!-- Sliding Panel Overlay -->
    <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-40 transition-opacity duration-300"
        @click="emit('close')"
    >
    </div>

    <!-- Panel Content -->
    <div
        class="fixed right-0 top-0 h-full w-96 bg-card border-l border-border shadow-xl transform transition-transform duration-300 ease-in-out flex flex-col z-50"
        :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
        @click.stop
    >
            <!-- Panel Header -->
            <div class="flex items-center justify-between p-4 border-b border-border flex-shrink-0">
                <h2 class="text-lg font-semibold">Queue</h2>
                <button
                    @click="emit('close')"
                    class="button circular small empty"
                    title="Close Queue"
                >
                    <X :size="16" />
                </button>
            </div>

            <!-- Queue List -->
            <div class="flex-1 min-h-0">
                <div v-if="audioStore.playlist.length === 0" class="p-4 text-center text-muted-foreground">
                    No tracks in queue
                </div>

                <RecycleScroller
                    v-else
                    ref="recycleScrollerRef"
                    class="h-full RecycleScroller"
                    :items="audioStore.playlist"
                    :item-size="74"
                    key-field="id"
                    :emit-update="true"
                    @update="onScroll"
                    v-slot="{ item, index }"
                >
                    <div class="relative overflow-hidden">
                        <!-- List item component -->
                        <AudioListItem
                            :item="item"
                            :index="index + 1"
                            :loaded-file="loadedFiles[item.id]"
                            :is-playing="audioStore.isPlaying"
                            :current-file-id="audioStore.currentFile ? audioStore.currentFile.id : null"
                            :is-swiped-open="swipedItemId === item.id"
                            :show-queue-indicator="true"
                            :is-current-track="audioStore.currentIndex === index"
                            @play="playAudio(getFileData(item))"
                            @touch-start="handleTouchStart"
                            @touch-move="handleTouchMove"
                            @touch-end="handleTouchEnd"
                            @favorite="toggleFavorite"
                            @like="likeItem"
                            @dislike="dislikeItem"
                            @laughed-at="laughedAtItem"
                        />
                    </div>
                </RecycleScroller>
            </div>
    </div>
</template>
