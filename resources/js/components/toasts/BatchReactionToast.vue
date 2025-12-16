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
           <div class="flex gap-2 items-center">
               <div class="stacked-images">
                   <template v-for="(reaction, index) in reactions.slice(0, 5)" :key="reaction.fileId">
                       <div v-if="reaction.previewUrl" class="stacked-image">
                           <img :src="reaction.previewUrl" :alt="`File #${reaction.fileId}`" />
                       </div>
                   </template>
               </div>

               <!-- Plus icon for additional items (if more than 5) -->
               <div v-if="reactions.length > 5" class="stacked-image-plus">
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

<style scoped>
.stacked-images {
    --s: 64px;
    /* image size (w-16 = 4rem = 64px) */
    display: flex;
    align-items: center;
    position: relative;
}

.stacked-image {
    position: relative;
    flex-shrink: 0;
}

.stacked-image img {
    width: var(--s);
    height: var(--s);
    object-fit: cover;
    border-radius: 0.25rem;
    display: block;
}

/* First image (100% visible) - on top */
.stacked-image:first-child {
    z-index: 5;
}

.stacked-image:first-child img {
    border: 2px solid rgb(59 130 246 / 1);
    /* border-smart-blue-500 */
    box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
    /* shadow-lg */
}

/* Second image (70% visible) - translate back 30% */
.stacked-image:nth-child(2) {
    z-index: 4;
    margin-left: calc(var(--s) * -0.3);
}

.stacked-image:nth-child(2) img {
    border: 1px solid rgb(59 130 246 / 0.5);
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    /* shadow-md */
    opacity: 0.9;
}

/* Third image (50% visible) - translate back 50% */
.stacked-image:nth-child(3) {
    z-index: 3;
    margin-left: calc(var(--s) * -0.5);
}

.stacked-image:nth-child(3) img {
    border: 1px solid rgb(59 130 246 / 0.4);
    box-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
    /* shadow */
    opacity: 0.8;
}

/* Fourth image (30% visible) - translate back 70% */
.stacked-image:nth-child(4) {
    z-index: 2;
    margin-left: calc(var(--s) * -0.7);
}

.stacked-image:nth-child(4) img {
    border: 1px solid rgb(59 130 246 / 0.3);
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    /* shadow-sm */
    opacity: 0.7;
}

/* Fifth image (10% visible) - translate back 90% */
.stacked-image:nth-child(5) {
    z-index: 1;
    margin-left: calc(var(--s) * -0.9);
}

.stacked-image:nth-child(5) img {
    border: 1px solid rgb(59 130 246 / 0.2);
    box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
    /* shadow-sm */
    opacity: 0.6;
}

/* Plus icon */
.stacked-image-plus {
    position: relative;
    z-index: 0;
    width: 80px;
    height: 80px;
    border-radius: 0.25rem;
    border: 1px solid rgb(59 130 246 / 0.3);
    background-color: rgb(59 130 246 / 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-left: calc(var(--s) * -0.9);
}
</style>
