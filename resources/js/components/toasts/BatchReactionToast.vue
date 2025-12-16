<script setup lang="ts">
import { Heart, ThumbsUp, ThumbsDown, Smile, X, Plus, Undo } from 'lucide-vue-next';
import type { QueuedReaction } from '@/composables/useReactionQueue';

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
    <div @mouseenter="handleMouseEnter" @mouseleave="handleMouseLeave"
        class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm">
        <div class="flex items-center gap-3 mb-2">
            <!-- Multiple Preview Images (up to 5, then plus icon) - stacked with overlapping effect -->
            <div class="flex shrink-0 items-center relative">
                <!-- First preview (on top) - no translation -->
                <div v-if="reactions.length > 0 && reactions[0].previewUrl" class="relative z-5">
                    <img :src="reactions[0].previewUrl" :alt="`File #${reactions[0].fileId}`"
                        class="w-16 h-16 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <!-- Second preview (behind first) - translateX(-10%) -->
                <div v-if="reactions.length > 1 && reactions[1].previewUrl" class="relative z-4"
                    style="transform: translateX(-10%);">
                    <img :src="reactions[1].previewUrl" :alt="`File #${reactions[1].fileId}`"
                        class="w-16 h-16 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <!-- Third preview (behind second) - translateX(-30%) -->
                <div v-if="reactions.length > 2 && reactions[2].previewUrl" class="relative z-3"
                    style="transform: translateX(-30%);">
                    <img :src="reactions[2].previewUrl" :alt="`File #${reactions[2].fileId}`"
                        class="w-16 h-16 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <!-- Fourth preview (behind third) - translateX(-50%) -->
                <div v-if="reactions.length > 3 && reactions[3].previewUrl" class="relative z-2"
                    style="transform: translateX(-50%);">
                    <img :src="reactions[3].previewUrl" :alt="`File #${reactions[3].fileId}`"
                        class="w-16 h-16 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <!-- Fifth preview (behind everything) - translateX(-70%) -->
                <div v-if="reactions.length > 4 && reactions[4].previewUrl" class="relative z-1"
                    style="transform: translateX(-70%);">
                    <img :src="reactions[4].previewUrl" :alt="`File #${reactions[4].fileId}`"
                        class="w-16 h-16 object-cover rounded border border-smart-blue-500/30" />
                </div>
                <!-- Plus icon for additional items (if more than 5) -->
                <div v-if="reactions.length > 5"
                    class="relative z-0 -ml-4 w-10 h-10 rounded border border-smart-blue-500/30 bg-smart-blue-500/20 flex items-center justify-center">
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
                <Undo X :size="16" />
            </button>
        </div>
    </div>
</template>
