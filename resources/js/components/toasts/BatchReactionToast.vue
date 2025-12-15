<script setup lang="ts">
import { Heart, ThumbsUp, ThumbsDown, Smile, X, Plus } from 'lucide-vue-next';
import type { QueuedReaction } from '../../composables/useReactionQueue';

interface Props {
    batchId: string;
    reactions: QueuedReaction[];
    type: 'love' | 'like' | 'dislike' | 'funny';
    countdown: number;
    onCancelBatch?: (batchId: string) => void;
}

const props = defineProps<Props>();

const QUEUE_DELAY_SECONDS = 5;

// Handle hover events to pause/resume countdown
function handleMouseEnter(): void {
    const win = window as any;
    if (win.__reactionQueuePauseAll) {
        win.__reactionQueuePauseAll();
    }
}

function handleMouseLeave(): void {
    const win = window as any;
    if (win.__reactionQueueResumeAll) {
        win.__reactionQueueResumeAll();
    }
}

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

function getProgress(): number {
    // Calculate progress percentage (0% at start, 100% at end - showing progress toward execution)
    return ((QUEUE_DELAY_SECONDS - props.countdown) / QUEUE_DELAY_SECONDS) * 100;
}

function handleCancel(): void {
    if (props.onCancelBatch) {
        props.onCancelBatch(props.batchId);
    }
    // Emit close-toast event for Vue Toastification
    emit('close-toast');
}

const emit = defineEmits<{
    'close-toast': [];
}>();
</script>

<template>
    <div
        @mouseenter="handleMouseEnter"
        @mouseleave="handleMouseLeave"
        class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm">
        <div class="flex items-center gap-3 mb-2">
            <!-- Multiple Preview Images (up to 5, then plus icon) -->
            <div class="flex items-center gap-1 shrink-0">
                <div v-for="(reaction, index) in reactions.slice(0, 5)" :key="reaction.id"
                    class="relative">
                    <img v-if="reaction.previewUrl" :src="reaction.previewUrl"
                        :alt="`File #${reaction.fileId}`"
                        class="w-10 h-10 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <div v-if="reactions.length > 5"
                    class="w-10 h-10 rounded border border-smart-blue-500/30 bg-smart-blue-500/20 flex items-center justify-center">
                    <Plus :size="16" class="text-smart-blue-400" />
                </div>
            </div>

            <div class="flex-1 flex gap-4 min-w-0">
                <!-- Reaction Icon -->
                <component :is="reactionIcons[type]" :size="20" :class="reactionColors[type]" />

                <!-- Batch Info and Progress Bar -->
                <div class="flex-1 min-w-0">
                    <div class="text-sm text-white font-medium truncate mb-1">
                        {{ reactions.length }} files
                    </div>
                    <!-- Progress Bar -->
                    <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                        <div class="h-full bg-smart-blue-400"
                            :style="{ width: `${getProgress()}%`, transition: 'width 0.05s linear' }" />
                    </div>
                </div>
            </div>

            <!-- Cancel Button -->
            <button @click="handleCancel"
                class="p-1 rounded hover:bg-black/20 text-white/70 hover:text-white transition-colors"
                aria-label="Cancel batch reaction">
                <X :size="16" />
            </button>
        </div>
    </div>
</template>
