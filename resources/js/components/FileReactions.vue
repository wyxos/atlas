<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, Eye, EyeOff } from 'lucide-vue-next';

interface Props {
    fileId?: number;
    previewedCount?: number;
    viewedCount?: number;
    currentIndex?: number;
    totalItems?: number;
    variant?: 'default' | 'small';
}

const props = withDefaults(defineProps<Props>(), {
    fileId: undefined,
    previewedCount: 0,
    viewedCount: 0,
    currentIndex: undefined,
    totalItems: undefined,
    variant: 'default',
});

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

    isUpdating.value = true;
    try {
        const response = await window.axios.post(`/api/files/${props.fileId}/reaction`, {
            type, // Server handles toggle logic (removes if same type, replaces if different)
        });

        // Update local state based on response
        currentReaction.value = response.data.reaction?.type || null;
    } catch (error) {
        console.error('Failed to update reaction:', error);
        // Optionally revert on error
    } finally {
        isUpdating.value = false;
    }
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

const indexDisplay = computed(() => {
    if (props.currentIndex !== undefined && props.totalItems !== undefined) {
        if (props.variant === 'small') {
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
    <div @click.stop :class="[
        'flex items-center justify-center bg-black/60 backdrop-blur-sm rounded-lg',
        variant === 'small' ? 'gap-1.5 px-2 py-1' : 'gap-4 px-4 py-2'
    ]">
        <!-- Reaction Icons -->
        <div :class="[
            'flex items-center gap-2'
        ]">
            <!-- Favorite -->
            <button @click="handleFavoriteClick" :disabled="isUpdating" class="rounded-full p-2 text-white"
                aria-label="Favorite">
                <Heart :size="18" />
            </button>

            <!-- Like -->
            <button @click="handleLikeClick" :disabled="isUpdating" class="rounded-full p-2 text-white"
                aria-label="Like">
                <ThumbsUp :size="18" />
            </button>

            <!-- Dislike -->
            <button @click="handleDislikeClick" :disabled="isUpdating" class="rounded-full p-2 text-white"
                aria-label="Dislike">
                <ThumbsDown :size="18" />
            </button>

            <!-- Funny -->
            <button @click="handleFunnyClick" :disabled="isUpdating" class="rounded-full p-2 text-white"
                aria-label="Funny">
                <Smile :size="18" />
            </button>
        </div>

        <!-- Separator -->
        <div :class="[
            'w-px bg-white/20',
            variant === 'small' ? 'h-4' : 'h-6'
        ]" />

        <!-- Count Icons -->
        <div :class="[
            'flex items-center',
            variant === 'small' ? 'gap-3' : 'gap-3'
        ]">
            <!-- Previewed Count -->
            <div class="flex items-center text-white gap-1.5">
                <Eye :size="18" />
                <span :class="variant === 'small' ? 'text-xs font-medium' : 'text-sm font-medium'">{{ previewedCount
                }}</span>
            </div>

            <!-- Viewed Count -->
            <div class="flex items-center text-white gap-1.5">
                <EyeOff :size="18" />
                <span :class="variant === 'small' ? 'text-xs font-medium' : 'text-sm font-medium'">{{ viewedCount
                }}</span>
            </div>
        </div>

        <!-- Index/Total -->
        <div v-if="indexDisplay" class="flex items-center">
            <span :class="[
                'font-medium text-white',
                variant === 'small' ? 'text-xs' : 'text-sm'
            ]">{{ indexDisplay }}</span>
        </div>
    </div>
</template>
