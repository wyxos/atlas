<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, X } from 'lucide-vue-next';
import type { QueuedReaction } from '../composables/useReactionQueue';

interface Props {
    queuedReactions: QueuedReaction[];
    onCancel?: (fileId: number) => void;
    onPause?: () => void;
    onResume?: () => void;
}

const props = defineProps<Props>();

const QUEUE_DELAY_SECONDS = 5;

const reactionIcons = {
    love: Heart,
    like: ThumbsUp,
    dislike: ThumbsDown,
    funny: Smile,
};

const reactionColors = {
    love: 'text-red-400',
    like: 'text-smart-blue-400',
    dislike: 'text-gray-400',
    funny: 'text-yellow-400',
};

function handleCancel(fileId: number): void {
    if (props.onCancel) {
        props.onCancel(fileId);
    }
}

function getProgress(queued: QueuedReaction): number {
    // Calculate progress percentage (0% at start, 100% at end - showing progress toward execution)
    return ((QUEUE_DELAY_SECONDS - queued.countdown) / QUEUE_DELAY_SECONDS) * 100;
}

function handleMouseEnter(): void {
    if (props.onPause) {
        props.onPause();
    }
}

function handleMouseLeave(): void {
    if (props.onResume) {
        props.onResume();
    }
}
</script>

<template>
    <div v-if="queuedReactions.length > 0" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 max-w-sm"
        @mouseenter="handleMouseEnter" @mouseleave="handleMouseLeave">
        <div v-for="queued in queuedReactions" :key="queued.id"
            class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm flex items-center gap-3">
            <!-- Preview Image -->
            <img v-if="queued.previewUrl" :src="queued.previewUrl" :alt="`File #${queued.fileId}`"
                class="w-12 h-12 object-cover rounded border border-smart-blue-500/30 flex-shrink-0" />
            
            <!-- Reaction Icon -->
            <component :is="reactionIcons[queued.type]" :size="20" :class="reactionColors[queued.type]" />

            <!-- File ID and Progress Bar -->
            <div class="flex-1 min-w-0">
                <div class="text-sm text-white font-medium truncate mb-1">
                    File #{{ queued.fileId }}
                </div>
                <!-- Progress Bar -->
                <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                    <div class="h-full bg-smart-blue-400"
                        :style="{ width: `${getProgress(queued)}%`, transition: 'width 0.05s linear' }" />
                </div>
            </div>

            <!-- Cancel Button -->
            <button @click="handleCancel(queued.fileId)"
                class="p-1 rounded hover:bg-black/20 text-white/70 hover:text-white transition-colors"
                aria-label="Cancel reaction">
                <X :size="16" />
            </button>
        </div>
    </div>
</template>
