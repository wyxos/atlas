<script setup lang="ts">
import { } from 'vue';
import { Play, Pause, Heart, ThumbsUp, ThumbsDown } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
  item: any;
  loadedFile: any | null;
  isPlaying: boolean;
  currentFileId: number | null;
  isSwipedOpen: boolean;
}>();

const emit = defineEmits<{
  (e: 'play', file: any): void;
  (e: 'touchStart', event: TouchEvent | MouseEvent): void;
  (e: 'touchMove', event: TouchEvent | MouseEvent): void;
  (e: 'touchEnd', item: any): void;
  (e: 'favorite', item: any, event: Event): void;
  (e: 'like', item: any, event: Event): void;
  (e: 'dislike', item: any, event: Event): void;
}>();

function excerpt(text: string, length = 25): string {
  if (!text) return '';
  return text.length > length ? text.substring(0, length) + '...' : text;
}

// Handle play button click
function handlePlay(event: Event): void {
  event.stopPropagation();
  emit('play', props.item);
}

// Handle favorite button click
function handleFavorite(event: Event): void {
  event.stopPropagation();
  emit('favorite', props.item, event);
}

// Handle like button click
function handleLike(event: Event): void {
  event.stopPropagation();
  emit('like', props.item, event);
}

// Handle dislike button click
function handleDislike(event: Event): void {
  event.stopPropagation();
  emit('dislike', props.item, event);
}

// Navigate to audio details page
function navigateToDetails(): void {
  router.get(route('audio.show', { file: props.item.id }));
}
</script>

<template>
  <div
    class="file px-4 py-1 flex justify-between items-center rounded border-b-2 border-blue-200 transition-transform duration-300 relative"
    :class="{
      'bg-blue-500': currentFileId === item.id,
      'transform -translate-x-32': isSwipedOpen
    }"
    @touchstart="emit('touchStart', $event)"
    @touchmove="emit('touchMove', $event)"
    @touchend="emit('touchEnd', item)"
    @mousedown="emit('touchStart', $event)"
    @mousemove="emit('touchMove', $event)"
    @mouseup="emit('touchEnd', item)"
    @mouseleave="$event.buttons && emit('touchEnd', item)"
    @click="navigateToDetails"
  >
    <div class="flex gap-2 items-center">
      <div class="w-16 h-16 flex-shrink-0 overflow-hidden rounded relative">
        <!-- Loading skeleton for cover -->
        <Skeleton v-if="!loadedFile" class="w-full h-full" />
        <!-- Actual cover image when loaded -->
        <template v-else>
          <img
            v-if="loadedFile.covers && loadedFile.covers.length > 0"
            :src="`/storage/${loadedFile.covers[0].path}`"
            alt="Cover"
            class="w-full h-full object-cover"
          />
          <div v-else class="w-full h-full bg-blue-300 flex items-center justify-center text-blue-800">
            <span class="text-xs">No Cover</span>
          </div>
        </template>
        <button
          class="cursor-pointer opacity-0 bg-black/50 hover:opacity-100 flex items-center justify-center absolute h-full w-full left-0 top-0"
          @click.stop="handlePlay($event)"
        >
          <Play v-if="!isPlaying || currentFileId !== item.id" :size="20" />
          <Pause v-else :size="20" />
        </button>
      </div>
      <div class="flex flex-col">
        <!-- Loading skeleton for artist name -->
        <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-1" />
        <span v-else class="text-xs font-semibold">{{
          loadedFile.artists && loadedFile.artists.length > 0
            ? excerpt(loadedFile.artists[0].name)
            : 'Unknown Artist'
        }}</span>

        <!-- Loading skeleton for title -->
        <Skeleton v-if="!loadedFile" class="h-4 w-32" />
        <span v-else>{{ excerpt(loadedFile?.metadata?.payload?.title) || 'Untitled' }}</span>
      </div>
    </div>

    <!-- Action buttons container -->
    <div class="absolute top-0 left-full h-full items-center flex gap-4 p-4">
      <button
        class=""
        @click.stop="handleFavorite($event)"
      >
        <Heart :size="20" />
      </button>
      <button
        class=""
        @click.stop="handleLike($event)"
      >
        <ThumbsUp :size="20" />
      </button>
      <button
        class=""
        @click.stop="handleDislike($event)"
      >
        <ThumbsDown :size="20" />
      </button>
    </div>
  </div>
</template>
