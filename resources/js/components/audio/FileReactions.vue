<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Laugh } from 'lucide-vue-next';

interface Props {
  file: any;
  iconSize?: number;
  variant?: 'list' | 'player';
  showLabels?: boolean;
}

interface Emits {
  (e: 'favorite', file: any, event: Event): void;
  (e: 'like', file: any, event: Event): void;
  (e: 'dislike', file: any, event: Event): void;
  (e: 'laughedAt', file: any, event: Event): void;
}

const props = withDefaults(defineProps<Props>(), {
  iconSize: 20,
  variant: 'list',
  showLabels: false
});

const emit = defineEmits<Emits>();

// Computed properties for reaction states
const isLoved = computed(() => !!props.file?.loved);
const isLiked = computed(() => !!props.file?.liked);
const isDisliked = computed(() => !!props.file?.disliked);
const isLaughedAt = computed(() => !!props.file?.funny);

// Event handlers
function handleFavorite(event: Event): void {
  event.stopPropagation();
  emit('favorite', props.file, event);
}

function handleLike(event: Event): void {
  event.stopPropagation();
  emit('like', props.file, event);
}

function handleDislike(event: Event): void {
  event.stopPropagation();
  emit('dislike', props.file, event);
}

function handleLaughedAt(event: Event): void {
  event.stopPropagation();
  emit('laughedAt', props.file, event);
}

</script>

<template>
  <div class="flex gap-4 items-center">
    <!-- Love Button -->
    <button
      :class="[
        'transition-all p-2 rounded-md',
        isLoved
          ? 'bg-red-500/30 hover:bg-red-500/40'
          : 'hover:bg-red-500'
      ]"
      @click="handleFavorite"
      :title="showLabels ? 'Love' : undefined"
    >
      <Heart
        :size="iconSize"
        :class="isLoved ? 'text-red-500' : 'text-white'"
      />
    </button>

    <!-- Like Button -->
    <button
      :class="[
        'transition-all p-2 rounded-md',
        isLiked
          ? 'bg-blue-500/30 hover:bg-blue-500/40'
          : 'hover:bg-blue-500'
      ]"
      @click="handleLike"
      :title="showLabels ? 'Like' : undefined"
    >
      <ThumbsUp
        :size="iconSize"
        :class="isLiked ? 'text-blue-500' : 'text-white'"
      />
    </button>

    <!-- Dislike Button -->
    <button
      :class="[
        'transition-all p-2 rounded-md',
        isDisliked
          ? 'bg-gray-500/30 hover:bg-gray-500/40'
          : 'hover:bg-gray-500'
      ]"
      @click="handleDislike"
      :title="showLabels ? 'Dislike' : undefined"
    >
      <ThumbsDown
        :size="iconSize"
        :class="isDisliked ? 'text-gray-500' : 'text-white'"
      />
    </button>

    <!-- Funny Button -->
    <button
      :class="[
        'transition-all p-2 rounded-md',
        isLaughedAt
          ? 'bg-yellow-500/30 hover:bg-yellow-500/40'
          : 'hover:bg-yellow-500'
      ]"
      @click="handleLaughedAt"
      :title="showLabels ? 'Funny' : undefined"
    >
      <Laugh
        :size="iconSize"
        :class="isLaughedAt ? 'text-yellow-500' : 'text-white'"
      />
    </button>
  </div>
</template>
