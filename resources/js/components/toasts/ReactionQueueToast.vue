<script setup lang="ts">
import { computed } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import { queueManager } from '@/composables/useQueue';
import { cancelQueuedReaction, type QueuedReactionType } from '@/utils/reactionQueue';
import { Ban, Heart, ThumbsUp, Smile, Undo2 } from 'lucide-vue-next';

const toast = useToast();

interface Props {
    queueId: string;
    fileId: number;
    reactionType: QueuedReactionType;
    thumbnail?: string;
}

const props = defineProps<Props>();
const queue = queueManager;
const queueFreeze = queue.freeze;
const progress = queue.query.getProgressComputed(props.queueId);
const remainingTime = queue.query.getRemainingTimeComputed(props.queueId);

const reactionConfig = computed(() => {
    const configs: Record<QueuedReactionType, { label: string; icon: typeof Heart; color: string }> = {
        love: { label: 'Loved', icon: Heart, color: 'text-red-500' },
        like: { label: 'Liked', icon: ThumbsUp, color: 'text-blue-500' },
        funny: { label: 'Funny', icon: Smile, color: 'text-yellow-500' },
        blacklist: { label: 'Blacklisted', icon: Ban, color: 'text-danger-400' },
    };
    return configs[props.reactionType];
});

const Icon = computed(() => reactionConfig.value.icon);
const toastClasses = 'reaction-queue-toast group relative flex items-center gap-3 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl';
const textColor = 'text-twilight-indigo-100';
const secondaryTextColor = 'text-twilight-indigo-300';
const iconColor = computed(() => reactionConfig.value.color);
const progressBarBg = 'bg-twilight-indigo-500/20';
const progressBarFill = 'bg-smart-blue-400';
const dismissButtonClasses = 'flex-shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100';
const undoButtonClasses = 'flex items-center gap-1 rounded bg-twilight-indigo-500/20 px-2 py-1 text-xs font-medium text-twilight-indigo-200 transition-colors hover:bg-twilight-indigo-500/30 hover:text-twilight-indigo-100';

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
    <div :class="toastClasses" @mouseenter="queueFreeze.freezeAll()" @mouseleave="queueFreeze.unfreezeAll()" class="flex! gap-4!">
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
