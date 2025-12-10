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
    variant?: 'default' | 'small';
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
    variant: 'default',
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
        if (props.variant === 'small') {
            return `${props.currentIndex + 1}`;
        }
        return `${props.currentIndex + 1}/${props.totalItems}`;
    }
    return null;
});
</script>

<template>
    <div @click.stop :class="[
        'flex items-center justify-center bg-black/60 backdrop-blur-sm rounded-lg',
        variant === 'small' ? 'gap-1.5 px-2 py-1' : 'gap-4 px-4 py-2'
    ]">
        <!-- Reaction Icons -->
        <div :class="[
            'flex items-center',
            variant === 'small' ? 'gap-2' : 'gap-2'
        ]">
            <!-- Favorite -->
            <button @click="handleFavoriteClick" :class="[
                'rounded-full transition-all duration-200 hover:scale-110',
                variant === 'small' ? 'p-1' : 'p-2',
                favorite ? 'text-red-500 bg-red-500/20' : 'text-white/70 hover:text-red-400 hover:bg-red-500/10'
            ]" aria-label="Favorite">
                <Heart :size="18" :fill="favorite ? 'currentColor' : 'none'" />
            </button>

            <!-- Like -->
            <button @click="handleLikeClick" :class="[
                'rounded-full transition-all duration-200 hover:scale-110',
                variant === 'small' ? 'p-1' : 'p-2',
                like ? 'text-blue-500 bg-blue-500/20' : 'text-white/70 hover:text-blue-400 hover:bg-blue-500/10'
            ]" aria-label="Like">
                <ThumbsUp :size="18" :fill="like ? 'currentColor' : 'none'" />
            </button>

            <!-- Dislike -->
            <button @click="handleDislikeClick" :class="[
                'rounded-full transition-all duration-200 hover:scale-110',
                variant === 'small' ? 'p-1' : 'p-2',
                dislike ? 'text-orange-500 bg-orange-500/20' : 'text-white/70 hover:text-orange-400 hover:bg-orange-500/10'
            ]" aria-label="Dislike">
                <ThumbsDown :size="18" :fill="dislike ? 'currentColor' : 'none'" />
            </button>

            <!-- Funny -->
            <button @click="handleFunnyClick" :class="[
                'rounded-full transition-all duration-200 hover:scale-110',
                variant === 'small' ? 'p-1' : 'p-2',
                funny ? 'text-yellow-500 bg-yellow-500/20' : 'text-white/70 hover:text-yellow-400 hover:bg-yellow-500/10'
            ]" aria-label="Funny">
                <Smile :size="18" :fill="funny ? 'currentColor' : 'none'" />
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
            <div :class="[
                'flex items-center text-white/70 gap-1.5',
            ]">
                <Eye :size="18" />
                <span :class="variant === 'small' ? 'text-xs font-medium' : 'text-sm font-medium'">{{ previewedCount
                }}</span>
            </div>

            <!-- Viewed Count -->
            <div :class="[
                'flex items-center text-white/70 gap-1.5',
            ]">
                <EyeOff :size="18" />
                <span :class="variant === 'small' ? 'text-xs font-medium' : 'text-sm font-medium'">{{ viewedCount
                }}</span>
            </div>
        </div>

        <!-- Index/Total -->
        <div v-if="indexDisplay" class="flex items-center">
            <span :class="[
                'font-medium text-white/70',
                variant === 'small' ? 'text-xs' : 'text-sm'
            ]">{{ indexDisplay }}</span>
        </div>
    </div>
</template>
