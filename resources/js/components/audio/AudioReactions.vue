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

// Dynamic classes based on variant
const getButtonClass = (reactionType: string, isActive: boolean) => {
  if (props.variant === 'player') {
    const baseClass = 'button circular small empty';
    switch (reactionType) {
      case 'favorite':
        return `${baseClass} ${isActive ? 'destructive' : ''}`;
      case 'like':
        return `${baseClass} ${isActive ? 'active' : ''}`;
      case 'dislike':
        return `${baseClass} ${isActive ? 'disabled' : ''}`;
      case 'laugh':
        return `${baseClass} ${isActive ? 'text-yellow-500 bg-yellow-500/20' : ''}`;
      default:
        return baseClass;
    }
  } else {
    // List variant (original AudioListItem styling)
    const baseClass = 'text-foreground transition-colors p-1 rounded';
    switch (reactionType) {
      case 'favorite':
        return `${baseClass} hover:text-destructive ${isActive ? 'text-red-500 bg-red-500/20' : ''}`;
      case 'like':
        return `${baseClass} hover:text-secondary ${isActive ? 'text-blue-500 bg-blue-500/20' : ''}`;
      case 'dislike':
        return `${baseClass} hover:text-destructive ${isActive ? 'text-gray-500 bg-gray-500/20' : ''}`;
      case 'laugh':
        return `${baseClass} hover:text-yellow-500 ${isActive ? 'text-yellow-500 bg-yellow-500/20' : ''}`;
      default:
        return baseClass;
    }
  }
};
</script>

<template>
  <div class="flex gap-4 items-center">
    <button
      :class="getButtonClass('favorite', isLoved)"
      @click="handleFavorite"
      :title="showLabels ? 'Love' : undefined"
    >
      <Heart :size="iconSize" :fill="isLoved ? 'currentColor' : 'none'" />
    </button>

    <button
      :class="getButtonClass('like', isLiked)"
      @click="handleLike"
      :title="showLabels ? 'Like' : undefined"
    >
      <ThumbsUp :size="iconSize" :fill="isLiked ? 'currentColor' : 'none'" />
    </button>

    <button
      :class="getButtonClass('dislike', isDisliked)"
      @click="handleDislike"
      :title="showLabels ? 'Dislike' : undefined"
    >
      <ThumbsDown :size="iconSize" :fill="isDisliked ? 'currentColor' : 'none'" />
    </button>

    <button
      :class="getButtonClass('laugh', isLaughedAt)"
      @click="handleLaughedAt"
      :title="showLabels ? 'Funny' : undefined"
    >
      <Laugh :size="iconSize" :fill="isLaughedAt ? 'currentColor' : 'none'" />
    </button>
  </div>
</template>
