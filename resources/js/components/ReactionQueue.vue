<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, X, Plus } from 'lucide-vue-next';
import type { QueuedReaction } from '../composables/useReactionQueue';

interface Props {
    queuedReactions: QueuedReaction[];
    onCancel?: (fileId: number) => void;
    onCancelBatch?: (batchId: string) => void;
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

// Group reactions by batchId
const groupedReactions = computed(() => {
    const batches = new Map<string, QueuedReaction[]>();
    const singles: QueuedReaction[] = [];

    for (const reaction of props.queuedReactions) {
        if (reaction.batchId) {
            if (!batches.has(reaction.batchId)) {
                batches.set(reaction.batchId, []);
            }
            batches.get(reaction.batchId)!.push(reaction);
        } else {
            singles.push(reaction);
        }
    }

    return {
        batches: Array.from(batches.entries()).map(([batchId, reactions]) => ({
            batchId,
            reactions,
            // Use the first reaction's properties for batch display (they should all be the same type)
            type: reactions[0]?.type || 'like',
            countdown: reactions[0]?.countdown || 0,
            startTime: reactions[0]?.startTime || Date.now(),
        })),
        singles,
    };
});

function handleCancel(fileId: number): void {
    if (props.onCancel) {
        props.onCancel(fileId);
    }
}

function handleCancelBatch(batchId: string): void {
    if (props.onCancelBatch) {
        props.onCancelBatch(batchId);
    }
}

function getProgress(queued: QueuedReaction | { countdown: number }): number {
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
        <!-- Batch Reactions -->
        <div v-for="batch in groupedReactions.batches" :key="batch.batchId"
            class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm">
            <div class="flex items-center gap-3 mb-2">
                <!-- Multiple Preview Images (up to 5, then plus icon) -->
                <div class="flex items-center gap-1 shrink-0">
                    <div v-for="(reaction, index) in batch.reactions.slice(0, 5)" :key="reaction.id"
                        class="relative">
                        <img v-if="reaction.previewUrl" :src="reaction.previewUrl"
                            :alt="`File #${reaction.fileId}`"
                            class="w-10 h-10 object-cover rounded border border-smart-blue-500/30" />
                    </div>
                    <div v-if="batch.reactions.length > 5"
                        class="w-10 h-10 rounded border border-smart-blue-500/30 bg-smart-blue-500/20 flex items-center justify-center">
                        <Plus :size="16" class="text-smart-blue-400" />
                    </div>
                </div>

                <div class="flex-1 flex gap-4 min-w-0">
                    <!-- Reaction Icon -->
                    <component :is="reactionIcons[batch.type]" :size="20" :class="reactionColors[batch.type]" />

                    <!-- Batch Info and Progress Bar -->
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-white font-medium truncate mb-1">
                            {{ batch.reactions.length }} files
                        </div>
                        <!-- Progress Bar -->
                        <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                            <div class="h-full bg-smart-blue-400"
                                 :style="{ width: `${getProgress(batch)}%`, transition: 'width 0.05s linear' }" />
                        </div>
                    </div>
                </div>

                <!-- Cancel Button -->
                <button @click="handleCancelBatch(batch.batchId)"
                    class="p-1 rounded hover:bg-black/20 text-white/70 hover:text-white transition-colors"
                    aria-label="Cancel batch reaction">
                    <X :size="16" />
                </button>
            </div>
        </div>

        <!-- Single Reactions -->
        <div v-for="queued in groupedReactions.singles" :key="queued.id"
            class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm flex items-center gap-3">
            <!-- Preview Image -->
            <img v-if="queued.previewUrl" :src="queued.previewUrl" :alt="`File #${queued.fileId}`"
                class="w-12 h-12 object-cover rounded border border-smart-blue-500/30 shrink-0" />

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
