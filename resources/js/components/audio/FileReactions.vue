<script setup lang="ts">
import { computed } from 'vue';
import { Heart, ThumbsUp, ThumbsDown, Laugh } from 'lucide-vue-next';

const props = defineProps<{
  file: any;
  size?: number; // lucide size
}>();

const emit = defineEmits<{
  (e: 'favorite', file: any, event: Event): void;
  (e: 'like', file: any, event: Event): void;
  (e: 'dislike', file: any, event: Event): void;
  (e: 'laughed-at', file: any, event: Event): void;
}>();

const loved = computed(() => !!props.file?.loved);
const liked = computed(() => !!props.file?.liked);
const disliked = computed(() => !!props.file?.disliked);
const funny = computed(() => !!props.file?.funny);

const iconSize = computed(() => props.size ?? 20);

function onFav(event: Event) {
  event.stopPropagation();
  emit('favorite', props.file, event);
}
function onLike(event: Event) {
  event.stopPropagation();
  emit('like', props.file, event);
}
function onDislike(event: Event) {
  event.stopPropagation();
  emit('dislike', props.file, event);
}
function onFunny(event: Event) {
  event.stopPropagation();
  emit('laughed-at', props.file, event);
}
</script>

<template>
  <div class="flex items-center gap-2">
    <button
      @click="onFav"
      class="group p-2 rounded-md transition-all"
      :class="loved ? 'bg-red-500/30 hover:bg-red-500' : 'hover:bg-red-500'"
      title="Love"
      aria-label="Love"
    >
      <Heart :size="iconSize" :class="[ loved ? 'text-red-500' : 'text-muted-foreground', 'group-hover:text-white transition-colors' ]" />
      <span class="sr-only">Love</span>
    </button>

    <button
      @click="onLike"
      class="group p-2 rounded-md transition-all"
      :class="liked ? 'bg-blue-500/30 hover:bg-blue-500' : 'hover:bg-blue-500'"
      title="Like"
      aria-label="Like"
    >
      <ThumbsUp :size="iconSize" :class="[ liked ? 'text-blue-500' : 'text-muted-foreground', 'group-hover:text-white transition-colors' ]" />
      <span class="sr-only">Like</span>
    </button>

    <button
      @click="onDislike"
      class="group p-2 rounded-md transition-all"
      :class="disliked ? 'bg-gray-500/30 hover:bg-gray-500' : 'hover:bg-gray-500'"
      title="Dislike"
      aria-label="Dislike"
    >
      <ThumbsDown :size="iconSize" :class="[ disliked ? 'text-gray-500' : 'text-muted-foreground', 'group-hover:text-white transition-colors' ]" />
      <span class="sr-only">Dislike</span>
    </button>

    <button
      @click="onFunny"
      class="group p-2 rounded-md transition-all"
      :class="funny ? 'bg-yellow-500/30 hover:bg-yellow-500' : 'hover:bg-yellow-500'"
      title="Funny"
      aria-label="Funny"
    >
      <Laugh :size="iconSize" :class="[ funny ? 'text-yellow-500' : 'text-muted-foreground', 'group-hover:text-white transition-colors' ]" />
      <span class="sr-only">Funny</span>
    </button>
  </div>
</template>
