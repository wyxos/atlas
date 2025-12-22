<script setup lang="ts">
import { computed } from 'vue';
import { useToast } from 'vue-toastification';
import { useQueue } from '@/composables/useQueue';
import { cancelBatchQueuedReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';
import { Heart, ThumbsUp, ThumbsDown, Smile, Undo2, Plus } from 'lucide-vue-next';

const toast = useToast();

interface PreviewItem {
    fileId: number;
    thumbnail?: string;
}

interface Props {
    queueId: string;
    reactionType: ReactionType;
    previews: PreviewItem[];
    totalCount: number;
}

const props = defineProps<Props>();
const queue = useQueue();

// Use computed for reactive updates
const progress = computed(() => queue.getProgress(props.queueId));
const remainingTime = computed(() => queue.getRemainingTime(props.queueId));

// Show up to 5 previews, with + icon if more
const visiblePreviews = computed(() => props.previews.slice(0, 5));
const hasMore = computed(() => props.totalCount > 5);

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
        return 'batch-reaction-queue-toast group relative flex items-center gap-3 rounded-lg border border-danger-500/50 bg-danger-600 p-4 shadow-xl';
    }
    return 'batch-reaction-queue-toast group relative flex items-center gap-3 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl';
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
        return 'shrink-0 rounded p-1 text-white transition-colors hover:bg-white/20 hover:text-white';
    }
    return 'shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100';
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

/**
 * Calculate the overlap offset in pixels for each preview based on its index.
 * Image 1: 0px offset (100% visible)
 * Image 2: 12.8px offset (20% of 64px, 80% visible, 20% behind image 1)
 * Image 3: 25.6px offset (40% of 64px, 60% visible, 40% behind image 2)
 * Image 4: 38.4px offset (60% of 64px, 40% visible, 60% behind image 3)
 * Image 5: 51.2px offset (80% of 64px, 20% visible, 80% behind image 4)
 *
 * The offset is calculated as a percentage of the image width (64px).
 * Each subsequent image is offset by 20% more than the previous one.
 */
function getPreviewOffset(index: number): number {
    return (index * 20 / 100) * 64; // Convert percentage to pixels: 0px, 12.8px, 25.6px, 38.4px, 51.2px
}

/**
 * Calculate the total width needed for all previews and the plus icon.
 * Each image is 64px wide, and they overlap by 80% of the previous image.
 * Image 1: 64px (full width)
 * Image 2: 64px - 12.8px (20% of 64px) = 51.2px additional width
 * Image 3: 64px - 25.6px (40% of 64px) = 38.4px additional width
 * Image 4: 64px - 38.4px (60% of 64px) = 25.6px additional width
 * Image 5: 64px - 51.2px (80% of 64px) = 12.8px additional width
 * Total: 64 + 51.2 + 38.4 + 25.6 + 12.8 = 192px
 * Plus icon: +64px = 256px total
 */
const previewsContainerWidth = computed(() => {
    if (visiblePreviews.value.length === 0) return '64px';

    // Calculate width based on overlapping images
    // First image takes full 64px, each subsequent adds (64px - overlap)
    let totalWidth = 64; // First image
    for (let i = 1; i < visiblePreviews.value.length; i++) {
        const overlap = (i * 20) / 100 * 64; // Overlap in pixels
        totalWidth += (64 - overlap);
    }

    // Add space for plus icon if needed
    if (hasMore.value) {
        totalWidth += 64;
    }

    return `${totalWidth}px`;
});

async function handleUndo(): Promise<void> {
    await cancelBatchQueuedReaction(props.queueId);
}

function handleDismiss(): void {
    toast.dismiss(props.queueId);
}
</script>

<template>
    <div
        :class="toastClasses"
        @mouseenter="queue.freezeAll()"
        @mouseleave="queue.unfreezeAll()"
        class="flex! gap-4!"
    >
        <!-- Overlapping Preview Thumbnails -->
        <div class="relative shrink-0 flex items-center" :style="{ width: previewsContainerWidth, height: '64px' }">
            <div
                v-for="(preview, index) in visiblePreviews"
                :key="preview.fileId"
                class="relative rounded object-cover border-2"
                :class="isDislike ? 'border-danger-500/50' : 'border-twilight-indigo-500/50'"
                :style="{
                    width: '64px',
                    height: '64px',
                    zIndex: visiblePreviews.length - index,
                    marginLeft: index === 0 ? '0' : `-${getPreviewOffset(index)}px`,
                }"
            >
                <img
                    v-if="preview.thumbnail"
                    :src="preview.thumbnail"
                    :alt="`File ${preview.fileId}`"
                    class="size-full rounded object-cover"
                />
                <div
                    v-else
                    class="size-full rounded bg-twilight-indigo-500/20 flex items-center justify-center"
                >
                    <span class="text-xs text-twilight-indigo-300">#{{ preview.fileId }}</span>
                </div>
            </div>
            <!-- + Icon positioned to the right of the last preview -->
            <div
                v-if="hasMore"
                class="relative rounded flex flex-col items-center justify-center border-2 ml-2"
                :class="isDislike ? 'border-danger-500/50 bg-danger-500/20' : 'border-twilight-indigo-500/50 bg-twilight-indigo-500/20'"
                style="width: 64px; height: 64px;"
            >
                <Plus class="size-6" :class="isDislike ? 'text-white' : 'text-twilight-indigo-300'" />
                <span class="text-xs font-bold mt-1" :class="isDislike ? 'text-white' : 'text-twilight-indigo-300'">{{ totalCount - 5 }}</span>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div :class="['shrink-0', iconColor]">
                        <component :is="Icon" class="size-4" />
                    </div>
                    <p :class="['text-sm font-semibold truncate', textColor]">
                        {{ reactionConfig.label }} {{ totalCount }} file{{ totalCount !== 1 ? 's' : '' }}
                    </p>
                </div>
                <button
                    @click="handleDismiss"
                    :class="dismissButtonClasses"
                    aria-label="Dismiss"
                >
                    <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <!-- Progress bar -->
            <div :class="['mt-2 h-1 w-full overflow-hidden rounded-full', progressBarBg]">
                <div
                    :class="['h-full transition-all duration-100 ease-linear', progressBarFill]"
                    :style="{ width: `${progress}%` }"
                />
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
.batch-reaction-queue-toast {
    min-width: 300px;
    max-width: 600px;
}
</style>

