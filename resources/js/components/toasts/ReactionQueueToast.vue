<script setup lang="ts">
import { computed } from 'vue';
import { useToast } from 'vue-toastification';
import { useQueue } from '@/composables/useQueue';
import { cancelQueuedReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import { Heart, ThumbsUp, ThumbsDown, Smile, Undo2 } from 'lucide-vue-next';

const toast = useToast();

interface Props {
    queueId: string;
    fileId: number;
    reactionType: ReactionType;
    thumbnail?: string;
}

const props = defineProps<Props>();
const queue = useQueue();

// Use computed for reactive updates
const progress = computed(() => queue.getProgress(props.queueId));
const remainingTime = computed(() => queue.getRemainingTime(props.queueId));

const reactionConfig = computed(() => {
    const configs: Record<ReactionType, { label: string; icon: typeof Heart; color: string }> = {
        love: { label: 'Loved', icon: Heart, color: 'text-red-500' },
        like: { label: 'Liked', icon: ThumbsUp, color: 'text-blue-500' },
        dislike: { label: 'Disliked', icon: ThumbsDown, color: 'text-danger-400' },
        funny: { label: 'Funny', icon: Smile, color: 'text-yellow-500' },
    };
    return configs[props.reactionType];
});

const Icon = computed(() => reactionConfig.value.icon);

/**
 * Check if this is a dislike reaction (for danger theme).
 */
const isDislike = computed(() => props.reactionType === 'dislike');

/**
 * Toast container classes - danger theme for dislike, default for others.
 */
const toastClasses = computed(() => {
    if (isDislike.value) {
        return 'reaction-queue-toast group relative flex items-center gap-3 rounded-lg border border-danger-500/50 bg-danger-600 p-4 shadow-xl';
    }
    return 'reaction-queue-toast group relative flex items-center gap-3 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl';
});

/**
 * Text color classes - danger theme for dislike.
 */
const textColor = computed(() => {
    if (isDislike.value) {
        return 'text-danger-100';
    }
    return 'text-twilight-indigo-100';
});

/**
 * Secondary text color classes - danger theme for dislike.
 * Use white for better visibility on red background.
 */
const secondaryTextColor = computed(() => {
    if (isDislike.value) {
        return 'text-white';
    }
    return 'text-twilight-indigo-300';
});

/**
 * Icon color classes - danger theme for dislike.
 * Use white for better visibility on red background.
 */
const iconColor = computed(() => {
    if (isDislike.value) {
        return 'text-white';
    }
    return reactionConfig.value.color;
});

/**
 * Progress bar background classes - danger theme for dislike.
 * Use white with opacity for better visibility on red background.
 */
const progressBarBg = computed(() => {
    if (isDislike.value) {
        return 'bg-white/20';
    }
    return 'bg-twilight-indigo-500/20';
});

/**
 * Progress bar fill classes - danger theme for dislike.
 * Use white for better visibility on red background.
 */
const progressBarFill = computed(() => {
    if (isDislike.value) {
        return 'bg-white';
    }
    return 'bg-smart-blue-400';
});

/**
 * Dismiss button classes - danger theme for dislike.
 */
const dismissButtonClasses = computed(() => {
    if (isDislike.value) {
        return 'flex-shrink-0 rounded p-1 text-danger-200 transition-colors hover:bg-danger-500/20 hover:text-danger-100';
    }
    return 'flex-shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100';
});

/**
 * Undo button classes - danger theme for dislike.
 * Use white text for better visibility on red background.
 */
const undoButtonClasses = computed(() => {
    if (isDislike.value) {
        return 'flex items-center gap-1 rounded bg-white/20 px-2 py-1 text-xs font-medium text-white transition-colors hover:bg-white/30 hover:text-white';
    }
    return 'flex items-center gap-1 rounded bg-twilight-indigo-500/20 px-2 py-1 text-xs font-medium text-twilight-indigo-200 transition-colors hover:bg-twilight-indigo-500/30 hover:text-twilight-indigo-100';
});

/**
 * Format remaining time as ss:mm (seconds:centiseconds).
 */
const formattedCountdown = computed(() => {
    const totalMs = Math.max(0, remainingTime.value);
    const seconds = Math.floor(totalMs / 1000);
    const centiseconds = Math.floor((totalMs % 1000) / 10);

    return `${seconds.toString().padStart(2, '0')}:${centiseconds.toString().padStart(2, '0')}`;
});

async function handleUndo(): Promise<void> {
    await cancelQueuedReaction(props.fileId, props.reactionType);
}

function handleDismiss(): void {
    toast.dismiss(props.queueId);
}
</script>

<template>
    <div :class="toastClasses" @mouseenter="queue.freezeAll()" @mouseleave="queue.unfreezeAll()">
        <!-- File Preview Thumbnail -->
        <div v-if="thumbnail" class="shrink-0">
            <img :src="thumbnail" :alt="`File ${fileId}`" class="size-16 rounded object-cover" />
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div :class="['shrink-0', iconColor]">
                        <component :is="Icon" class="size-4" />
                    </div>
                    <p :class="['text-sm font-semibold truncate', textColor]">
                        {{ reactionConfig.label }} file #{{ fileId }}
                    </p>
                </div>
                <button @click="handleDismiss" :class="dismissButtonClasses" aria-label="Dismiss">
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Progress bar -->
            <div :class="['mt-2 h-1 w-full overflow-hidden rounded-full', progressBarBg]">
                <div :class="['h-full transition-all duration-100 ease-linear', progressBarFill]"
                    :style="{ width: `${progress}%` }" />
            </div>

            <!-- Actions and remaining time -->
            <div class="mt-2 flex items-center justify-between gap-2">
                <p :class="['text-xs font-mono', secondaryTextColor]">
                    {{ formattedCountdown }}
                </p>
                <button @click="handleUndo" :class="undoButtonClasses">
                    <Undo2 class="size-3" />
                    Undo
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.reaction-queue-toast {
    min-width: 300px;
    max-width: 600px;
}
</style>
