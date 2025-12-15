<script setup lang="ts">
import { Heart, ThumbsUp, ThumbsDown, Smile, X } from 'lucide-vue-next';

interface Props {
    fileId: number;
    type: 'love' | 'like' | 'dislike' | 'funny';
    previewUrl?: string;
    countdown: number;
    onCancel?: (fileId: number) => void;
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
    if (props.onCancel) {
        props.onCancel(props.fileId);
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
        class="bg-prussian-blue-800 border border-smart-blue-500/50 rounded-lg p-3 shadow-lg backdrop-blur-sm flex items-center gap-3">
        <!-- Preview Image -->
        <img v-if="previewUrl" :src="previewUrl" :alt="`File #${fileId}`"
            class="w-12 h-12 object-cover rounded border border-smart-blue-500/30 shrink-0" />

        <!-- Reaction Icon -->
        <component :is="reactionIcons[type]" :size="20" :class="reactionColors[type]" />

        <!-- File ID and Progress Bar -->
        <div class="flex-1 min-w-0">
            <div class="text-sm text-white font-medium truncate mb-1">
                File #{{ fileId }}
            </div>
            <!-- Progress Bar -->
            <div class="w-full h-1.5 bg-white/10 rounded-full overflow-hidden">
                <div class="h-full bg-smart-blue-400"
                    :style="{ width: `${getProgress()}%`, transition: 'width 0.05s linear' }" />
            </div>
        </div>

        <!-- Cancel Button -->
        <button @click="handleCancel"
            class="p-1 rounded hover:bg-black/20 text-white/70 hover:text-white transition-colors"
            aria-label="Cancel reaction">
            <X :size="16" />
        </button>
    </div>
</template>
