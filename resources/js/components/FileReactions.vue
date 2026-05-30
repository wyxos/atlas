<script setup lang="ts">
import { computed } from 'vue';
import { Ban, Heart, ThumbsUp, Smile, Eye, EyeOff, Hash, Infinity as InfinityIcon, Loader2, Unlink } from 'lucide-vue-next';
import { FEED_REMOVED_PREVIEW_COUNT } from '@/lib/feedModeration';
import type { ReactionType } from '@/types/reaction';

interface Props {
    fileId?: number;
    reaction?: { type: string } | null;
    blacklistedAt?: string | null;
    previewedCount?: number;
    viewedCount?: number;
    currentIndex?: number;
    totalItems?: number;
    variant?: 'default' | 'small';
    mode?: 'default' | 'reaction-only';
    showBlacklist?: boolean;
    showRemove?: boolean;
    removing?: boolean;
    surface?: 'default' | 'none';
    iconSize?: number;
}

const props = withDefaults(defineProps<Props>(), {
    fileId: undefined,
    reaction: null,
    blacklistedAt: null,
    previewedCount: 0,
    viewedCount: 0,
    currentIndex: undefined,
    totalItems: undefined,
    variant: 'default',
    mode: 'default',
    showBlacklist: false,
    showRemove: false,
    removing: false,
    surface: 'default',
    iconSize: 18,
});

const emit = defineEmits<{
    reaction: [type: ReactionType];
    blacklist: [];
    remove: [];
}>();

// Computed properties for each reaction type
const favorite = computed(() => props.reaction?.type === 'love');
const like = computed(() => props.reaction?.type === 'like');
const funny = computed(() => props.reaction?.type === 'funny');
const blacklisted = computed(() => Boolean(props.blacklistedAt));

// Handle reaction click
function handleReactionClick(type: ReactionType): void {
    if (!props.fileId) {
        return;
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

function handleFunnyClick(): void {
    handleReactionClick('funny');
}

function handleBlacklistClick(): void {
    if (!props.fileId || blacklisted.value) {
        return;
    }

    emit('blacklist');
}

function handleRemoveClick(): void {
    if (!props.fileId || props.removing) {
        return;
    }

    emit('remove');
}

// Computed properties for variant checks
const isSmall = computed(() => props.variant === 'small');
const isReactionOnly = computed(() => props.mode === 'reaction-only');
const shouldShowBlacklist = computed(() => !isReactionOnly.value || props.showBlacklist);
const shouldShowRemove = computed(() => !isReactionOnly.value && props.showRemove);
const hasDefaultSurface = computed(() => props.surface === 'default');

// Computed properties for styling classes
const containerClasses = computed(() => [
    'flex items-center justify-center',
    hasDefaultSurface.value ? 'rounded-lg bg-black/60 backdrop-blur-sm' : '',
    isSmall.value
        ? (hasDefaultSurface.value ? 'gap-2 px-2 py-1' : 'gap-3')
        : (hasDefaultSurface.value ? 'gap-4 px-4 py-2' : 'gap-4')
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
const hasTerminalPreviewCount = computed(() => Number(props.previewedCount) >= FEED_REMOVED_PREVIEW_COUNT);
</script>

<template>
    <div data-test="file-reactions" @click.stop @dblclick.stop :class="containerClasses">
        <!-- Reaction Icons -->
        <div class="flex items-center gap-2">
            <!-- Favorite -->
            <button @click="handleFavoriteClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                favorite ? 'bg-red-500 text-white' : 'text-white hover:text-red-400'
            ]" aria-label="Favorite">
                <Heart :size="iconSize" />
            </button>

            <!-- Like -->
            <button @click="handleLikeClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                like ? 'bg-smart-blue-500 text-white' : 'text-white hover:text-smart-blue-400'
            ]" aria-label="Like">
                <ThumbsUp :size="iconSize" />
            </button>

            <!-- Blacklist -->
            <button
                v-if="shouldShowBlacklist"
                @click="handleBlacklistClick"
                :disabled="blacklisted"
                :aria-pressed="blacklisted"
                :class="[
                    'rounded transition-colors',
                    isSmall ? 'p-1' : 'p-2',
                    blacklisted ? 'cursor-default bg-danger-600 text-white' : 'text-white hover:text-danger-300'
                ]"
                aria-label="Blacklist"
            >
                <Ban :size="iconSize" />
            </button>

            <!-- Funny -->
            <button @click="handleFunnyClick" :class="[
                'rounded transition-colors',
                isSmall ? 'p-1' : 'p-2',
                funny ? 'bg-yellow-500 text-white' : 'text-white hover:text-yellow-400'
            ]" aria-label="Funny">
                <Smile :size="iconSize" />
            </button>

            <!-- Remove from Tab -->
            <button
                v-if="shouldShowRemove"
                @click="handleRemoveClick"
                :disabled="removing"
                :class="[
                    'rounded transition-colors',
                    isSmall ? 'p-1' : 'p-2',
                    removing ? 'cursor-wait text-danger-200 opacity-80' : 'text-white hover:text-danger-300'
                ]"
                aria-label="Remove from tab"
                title="Remove from tab"
                data-test="file-reactions-remove"
            >
                <Loader2 v-if="removing" :size="iconSize" class="animate-spin" />
                <Unlink v-else :size="iconSize" />
            </button>
        </div>

        <!-- Separator -->
        <div v-if="!isReactionOnly" :class="['w-px bg-white/20', separatorHeight]" />

        <!-- Count Icons -->
        <div v-if="!isReactionOnly" class="flex items-center gap-2">
            <!-- Previewed Count -->
            <div class="flex items-center text-white gap-1.5">
                <InfinityIcon
                    v-if="hasTerminalPreviewCount"
                    :size="18"
                    aria-label="Preview count removed from feed"
                />
                <span v-else :class="[textSize, 'font-medium']">{{ previewedCount }}</span>
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
