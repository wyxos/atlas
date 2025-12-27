<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, Eye, EyeOff, Hash } from 'lucide-vue-next';
import type { ReactionType } from '@/types/reaction';

interface Props {
    fileId?: number;
    reaction?: { type: string } | null;
    previewedCount?: number;
    viewedCount?: number;
    currentIndex?: number;
    totalItems?: number;
    variant?: 'default' | 'small';
    mode?: 'default' | 'reaction-only';
    removeItem?: () => void;
    hideDislike?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    fileId: undefined,
    reaction: null,
    previewedCount: 0,
    viewedCount: 0,
    currentIndex: undefined,
    totalItems: undefined,
    variant: 'default',
    mode: 'default',
    removeItem: undefined,
    hideDislike: false,
});

const emit = defineEmits<{
    reaction: [type: ReactionType];
}>();

// Computed properties for each reaction type
const favorite = computed(() => props.reaction?.type === 'love');
const like = computed(() => props.reaction?.type === 'like');
const dislike = computed(() => props.reaction?.type === 'dislike');
const funny = computed(() => props.reaction?.type === 'funny');

// Handle reaction click
function handleReactionClick(type: ReactionType): void {
    if (!props.fileId) {
        return;
    }

    // If removeItem is provided, call it immediately (for masonry removal)
    if (props.removeItem) {
        props.removeItem();
    }

    // Emit reaction event (parent will handle queueing and API call)
    emit('reaction', type);
}

function handleFavoriteClick(): void {
    handleReactionClick('love');
}

function handleLikeClick(): void {
    handleReactionClick('like');
}

function handleDislikeClick(): void {
    handleReactionClick('dislike');
}

function handleFunnyClick(): void {
    handleReactionClick('funny');
}

// Computed properties for variant checks
const isSmall = computed(() => props.variant === 'small');
const isReactionOnly = computed(() => props.mode === 'reaction-only');

// Computed properties for styling classes
const containerClasses = computed(() => [
    'flex items-center justify-center bg-black/60 backdrop-blur-sm rounded-lg',
    isSmall.value ? 'gap-2 px-2 py-1' : 'gap-4 px-4 py-2'
]);

const separatorHeight = computed(() => isSmall.value ? 'h-4' : 'h-6');

const textSize = computed(() => isSmall.value ? 'text-xs' : 'text-sm');

const indexDisplay = computed(() => {
    if (props.currentIndex !== undefined && props.totalItems !== undefined) {
        if (isSmall.value) {
            return `${props.currentIndex + 1}`;
        }
        return `${props.currentIndex + 1}/${props.totalItems}`;
    }
    return null;
});
</script>

<template>
    <div @click.stop :class="containerClasses">
        <!-- Reaction Icons -->
        <div class="flex items-center gap-2">
            <!-- Favorite -->
            <button @click="handleFavoriteClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                favorite ? 'bg-red-500 text-white' : 'text-white hover:text-red-400'
            ]" aria-label="Favorite">
                <Heart :size="18" />
            </button>

            <!-- Like -->
            <button @click="handleLikeClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                like ? 'bg-smart-blue-500 text-white' : 'text-white hover:text-smart-blue-400'
            ]" aria-label="Like">
                <ThumbsUp :size="18" />
            </button>

            <!-- Dislike -->
            <button v-if="!hideDislike && !isReactionOnly" @click="handleDislikeClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                dislike ? 'bg-gray-500 text-white' : 'text-white hover:text-gray-400'
            ]" aria-label="Dislike">
                <ThumbsDown :size="18" />
            </button>

            <!-- Funny -->
            <button @click="handleFunnyClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                funny ? 'bg-yellow-500 text-white' : 'text-white hover:text-yellow-400'
            ]" aria-label="Funny">
                <Smile :size="18" />
            </button>
        </div>

        <!-- Separator -->
        <div v-if="!isReactionOnly" :class="['w-px bg-white/20', separatorHeight]" />

        <!-- Count Icons -->
        <div v-if="!isReactionOnly" class="flex items-center gap-2">
            <!-- Previewed Count -->
            <div class="flex items-center text-white gap-1.5">
                <span :class="[textSize, 'font-medium']">{{ previewedCount }}</span>
                <Eye :size="18" />
            </div>

            <!-- Viewed Count -->
            <div class="flex items-center text-white gap-1.5" v-if="!isSmall">
                <span :class="[textSize, 'font-medium']">{{ viewedCount }}</span>
                <EyeOff :size="18" />
            </div>
        </div>

        <!-- Index/Total -->
        <div v-if="!isReactionOnly && indexDisplay" class="flex items-center text-white gap-1.5">
            <Hash :size="18" />
            <span :class="['font-medium text-white', textSize]">{{ indexDisplay }}</span>
        </div>
    </div>
</template>
