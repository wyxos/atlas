<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, Eye, EyeOff, Hash } from 'lucide-vue-next';

interface Props {
    fileId?: number;
    previewedCount?: number;
    viewedCount?: number;
    currentIndex?: number;
    totalItems?: number;
    variant?: 'default' | 'small';
    removeItem?: () => void;
}

const props = withDefaults(defineProps<Props>(), {
    fileId: undefined,
    previewedCount: 0,
    viewedCount: 0,
    currentIndex: undefined,
    totalItems: undefined,
    variant: 'default',
    removeItem: undefined,
});

const emit = defineEmits<{
    reaction: [type: 'love' | 'like' | 'dislike' | 'funny'];
}>();

// Reaction state
const currentReaction = ref<string | null>(null);
const isUpdating = ref(false);

// Computed properties for each reaction type
const favorite = computed(() => currentReaction.value === 'love');
const like = computed(() => currentReaction.value === 'like');
const dislike = computed(() => currentReaction.value === 'dislike');
const funny = computed(() => currentReaction.value === 'funny');

// Fetch current reaction when fileId changes
async function fetchReaction(): Promise<void> {
    if (!props.fileId) {
        currentReaction.value = null;
        return;
    }

    try {
        const response = await window.axios.get(`/api/files/${props.fileId}/reaction`);
        currentReaction.value = response.data.reaction?.type || null;
    } catch (error) {
        console.error('Failed to fetch reaction:', error);
        currentReaction.value = null;
    }
}

// Handle reaction click
async function handleReactionClick(type: 'love' | 'like' | 'dislike' | 'funny'): Promise<void> {
    if (!props.fileId || isUpdating.value) {
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
const isDefault = computed(() => props.variant === 'default');

// Computed properties for styling classes
const containerClasses = computed(() => [
    'flex items-center justify-center bg-black/60 backdrop-blur-sm rounded-lg',
    isSmall.value ? 'gap-2 px-2 py-1' : 'gap-4 px-4 py-2'
]);

const buttonClasses = computed(() => (baseClasses: string, activeClasses: string, hoverClasses: string) => [
    'rounded transition-colors',
    isSmall.value ? 'p-1' : 'p-2',
    baseClasses,
    activeClasses,
    hoverClasses
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

// Watch fileId and fetch reaction when it changes
watch(() => props.fileId, fetchReaction, { immediate: true });
</script>

<template>
    <div @click.stop :class="containerClasses">
        <!-- Reaction Icons -->
        <div class="flex items-center gap-2">
            <!-- Favorite -->
            <button @click="handleFavoriteClick" :disabled="isUpdating" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                favorite ? 'bg-red-500 text-white' : 'text-white hover:text-red-400'
            ]" aria-label="Favorite">
                <Heart :size="18" />
            </button>

            <!-- Like -->
            <button @click="handleLikeClick" :disabled="isUpdating" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                like ? 'bg-smart-blue-500 text-white' : 'text-white hover:text-smart-blue-400'
            ]" aria-label="Like">
                <ThumbsUp :size="18" />
            </button>

            <!-- Dislike -->
            <button @click="handleDislikeClick" :disabled="isUpdating" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                dislike ? 'bg-gray-500 text-white' : 'text-white hover:text-gray-400'
            ]" aria-label="Dislike">
                <ThumbsDown :size="18" />
            </button>

            <!-- Funny -->
            <button @click="handleFunnyClick" :disabled="isUpdating" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                funny ? 'bg-yellow-500 text-white' : 'text-white hover:text-yellow-400'
            ]" aria-label="Funny">
                <Smile :size="18" />
            </button>
        </div>

        <!-- Separator -->
        <div :class="['w-px bg-white/20', separatorHeight]" />

        <!-- Count Icons -->
        <div class="flex items-center gap-2">
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
        <div v-if="indexDisplay" class="flex items-center text-white gap-1.5">
            <Hash :size="18" />
            <span :class="['font-medium text-white', textSize]">{{ indexDisplay }}</span>
        </div>
    </div>
</template>
