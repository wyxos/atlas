<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Smile, Eye, EyeOff } from 'lucide-vue-next';

interface Props {
    favorite?: boolean;
    like?: boolean;
    dislike?: boolean;
    funny?: boolean;
    previewedCount?: number;
    viewedCount?: number;
    currentIndex?: number;
    totalItems?: number;
}

const props = withDefaults(defineProps<Props>(), {
    favorite: false,
    like: false,
    dislike: false,
    funny: false,
    previewedCount: 0,
    viewedCount: 0,
    currentIndex: undefined,
    totalItems: undefined,
});

const emit = defineEmits<{
    'favorite-click': [];
    'like-click': [];
    'dislike-click': [];
    'funny-click': [];
}>();

function handleFavoriteClick(): void {
    emit('favorite-click');
}

function handleLikeClick(): void {
    emit('like-click');
}

function handleDislikeClick(): void {
    emit('dislike-click');
}

function handleFunnyClick(): void {
    emit('funny-click');
}

const indexDisplay = computed(() => {
    if (props.currentIndex !== undefined && props.totalItems !== undefined) {
        return `${props.currentIndex + 1}/${props.totalItems}`;
    }
    return null;
});
</script>

<template>
    <div class="flex items-center justify-center gap-4 px-4 py-2 bg-black/60 backdrop-blur-sm rounded-lg">
        <!-- Reaction Icons -->
        <div class="flex items-center gap-2">
            <!-- Favorite -->
            <button
                @click="handleFavoriteClick"
                :class="[
                    'p-2 rounded-full transition-all duration-200 hover:scale-110',
                    favorite ? 'text-red-500 bg-red-500/20' : 'text-white/70 hover:text-red-400 hover:bg-red-500/10'
                ]"
                aria-label="Favorite"
            >
                <Heart :size="18" :fill="favorite ? 'currentColor' : 'none'" />
            </button>

            <!-- Like -->
            <button
                @click="handleLikeClick"
                :class="[
                    'p-2 rounded-full transition-all duration-200 hover:scale-110',
                    like ? 'text-blue-500 bg-blue-500/20' : 'text-white/70 hover:text-blue-400 hover:bg-blue-500/10'
                ]"
                aria-label="Like"
            >
                <ThumbsUp :size="18" :fill="like ? 'currentColor' : 'none'" />
            </button>

            <!-- Dislike -->
            <button
                @click="handleDislikeClick"
                :class="[
                    'p-2 rounded-full transition-all duration-200 hover:scale-110',
                    dislike ? 'text-orange-500 bg-orange-500/20' : 'text-white/70 hover:text-orange-400 hover:bg-orange-500/10'
                ]"
                aria-label="Dislike"
            >
                <ThumbsDown :size="18" :fill="dislike ? 'currentColor' : 'none'" />
            </button>

            <!-- Funny -->
            <button
                @click="handleFunnyClick"
                :class="[
                    'p-2 rounded-full transition-all duration-200 hover:scale-110',
                    funny ? 'text-yellow-500 bg-yellow-500/20' : 'text-white/70 hover:text-yellow-400 hover:bg-yellow-500/10'
                ]"
                aria-label="Funny"
            >
                <Smile :size="18" :fill="funny ? 'currentColor' : 'none'" />
            </button>
        </div>

        <!-- Separator -->
        <div class="h-6 w-px bg-white/20" />

        <!-- Count Icons -->
        <div class="flex items-center gap-3">
            <!-- Previewed Count -->
            <div class="flex items-center gap-1.5 text-white/70">
                <Eye :size="16" />
                <span class="text-sm font-medium">{{ previewedCount }}</span>
            </div>

            <!-- Viewed Count -->
            <div class="flex items-center gap-1.5 text-white/70">
                <EyeOff :size="16" />
                <span class="text-sm font-medium">{{ viewedCount }}</span>
            </div>
        </div>

        <!-- Index/Total -->
        <div v-if="indexDisplay" class="flex items-center">
            <span class="text-sm font-medium text-white/70">{{ indexDisplay }}</span>
        </div>
    </div>
</template>

