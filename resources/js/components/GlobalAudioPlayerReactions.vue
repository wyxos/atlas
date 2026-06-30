<script setup lang="ts">
import { Ban, Heart, Smile, ThumbsUp } from 'lucide-vue-next';
import type { ReactionType } from '@/types/reaction';

defineProps<{
    canUsePlaybackControls: boolean;
    hasFavorite: boolean;
    hasFunny: boolean;
    hasLike: boolean;
    hasTrack: boolean;
    isBlacklisted: boolean;
    reactionButtonClass: string;
}>();

defineEmits<{
    blacklist: [];
    reaction: [type: ReactionType];
}>();
</script>

<template>
    <button type="button" :class="[reactionButtonClass, hasFavorite ? 'bg-red-500 text-white' : 'text-white enabled:hover:text-red-400']" :disabled="!hasTrack || !canUsePlaybackControls" :aria-pressed="hasFavorite" aria-label="Favorite" @click="$emit('reaction', 'love')">
        <Heart class="size-6 md:size-8" />
    </button>
    <button type="button" :class="[reactionButtonClass, hasLike ? 'bg-smart-blue-500 text-white' : 'text-white enabled:hover:text-smart-blue-400']" :disabled="!hasTrack || !canUsePlaybackControls" :aria-pressed="hasLike" aria-label="Like" @click="$emit('reaction', 'like')">
        <ThumbsUp class="size-6 md:size-8" />
    </button>
    <button type="button" :class="[reactionButtonClass, isBlacklisted ? 'bg-danger-600 text-white' : 'text-white enabled:hover:text-danger-300']" :disabled="!hasTrack || !canUsePlaybackControls || isBlacklisted" :aria-pressed="isBlacklisted" aria-label="Blacklist" @click="$emit('blacklist')">
        <Ban class="size-6 md:size-8" />
    </button>
    <button type="button" :class="[reactionButtonClass, hasFunny ? 'bg-yellow-500 text-white' : 'text-white enabled:hover:text-yellow-400']" :disabled="!hasTrack || !canUsePlaybackControls" :aria-pressed="hasFunny" aria-label="Funny" @click="$emit('reaction', 'funny')">
        <Smile class="size-6 md:size-8" />
    </button>
</template>
