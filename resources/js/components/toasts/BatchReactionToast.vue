<script setup lang="ts">
import { Heart, ThumbsUp, ThumbsDown, Smile, Plus, Undo } from 'lucide-vue-next';
import type { QueuedReaction } from '@/composables/useReactionQueue';
import type { ReactionType } from '@/types/reaction';

interface Props {
    batchId: string;
    reactions: QueuedReaction[];
    type: ReactionType;
    countdown: number;
    onCancelBatch?: (batchId: string) => void;
}

const props = defineProps<Props>();

const QUEUE_DELAY_SECONDS = 5;

// Handle hover events to pause/resume countdown (uses centralized timer manager)
function handleMouseEnter(): void {
    const win = window as Window & {
        __timerManagerFreeze?: () => void;
        __reactionQueuePauseAll?: () => void;
    };
    // Use new timer manager functions (backward compatible with old names)
    if (win.__timerManagerFreeze) {
        win.__timerManagerFreeze();
    } else if (win.__reactionQueuePauseAll) {
        win.__reactionQueuePauseAll();
    }
}

function handleMouseLeave(): void {
    const win = window as Window & {
        __timerManagerUnfreeze?: () => void;
        __reactionQueueResumeAll?: () => void;
    };
    // Use new timer manager functions (backward compatible with old names)
    if (win.__timerManagerUnfreeze) {
        win.__timerManagerUnfreeze();
    } else if (win.__reactionQueueResumeAll) {
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
                    <template v-for="reaction in reactions.slice(0, 5)" :key="reaction.fileId">
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
@reference "../../../css/app.css";

.stacked-images {
    --s: 64px;
    /* image size (w-16 = 4rem = 64px) */
    @apply flex items-center relative;
}

.stacked-image {
    @apply relative shrink-0;
}

.stacked-image img {
    width: var(--s);
    height: var(--s);
    @apply object-cover rounded block;
}

/* First image (100% visible) - on top */
.stacked-image:first-child {
    @apply z-[5];
}

.stacked-image:first-child img {
    @apply border-2 border-smart-blue-500 shadow-lg;
}

/* Second image (70% visible) - translate back 30% */
.stacked-image:nth-child(2) {
    @apply z-[4];
    margin-left: calc(var(--s) * -0.3);
}

.stacked-image:nth-child(2) img {
    @apply border border-smart-blue-500/50 shadow-md opacity-90;
}

/* Third image (50% visible) - translate back 50% */
.stacked-image:nth-child(3) {
    @apply z-[3];
    margin-left: calc(var(--s) * -0.5);
}

.stacked-image:nth-child(3) img {
    @apply border border-smart-blue-500/40 shadow opacity-80;
}

/* Fourth image (30% visible) - translate back 70% */
.stacked-image:nth-child(4) {
    @apply z-[2];
    margin-left: calc(var(--s) * -0.7);
}

.stacked-image:nth-child(4) img {
    @apply border border-smart-blue-500/30 shadow-sm opacity-70;
}

/* Fifth image (10% visible) - translate back 90% */
.stacked-image:nth-child(5) {
    @apply z-[1];
    margin-left: calc(var(--s) * -0.9);
}

.stacked-image:nth-child(5) img {
    @apply border border-smart-blue-500/20 shadow-sm opacity-60;
}

/* Plus icon */
.stacked-image-plus {
    @apply relative z-0 rounded border border-smart-blue-500/30 bg-smart-blue-500/20 flex items-center justify-center w-10 h-10;
}
</style>
