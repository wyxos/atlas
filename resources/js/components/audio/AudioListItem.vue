<script setup lang="ts">
import { computed } from 'vue';
import { Play, Pause, Heart, ThumbsUp, ThumbsDown } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import { router } from '@inertiajs/vue3';

const props = defineProps<{
  item: any;
  index: number;
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

// Computed property to get the cover image with priority: album covers first, then file covers
const coverImage = computed((): string | null => {
  if (!props.loadedFile) return null;

  // First check for album covers
  if (props.loadedFile.albums && props.loadedFile.albums.length > 0) {
    for (const album of props.loadedFile.albums) {
      if (album.covers && album.covers.length > 0) {
        return album.covers[0].path;
      }
    }
  }

  // Fall back to file covers
  if (props.loadedFile.covers && props.loadedFile.covers.length > 0) {
    return props.loadedFile.covers[0].path;
  }

  return null;
});

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

// Convert duration from e.g 177.99836734693878 to a human-readable format
function convertToDuration(seconds: number): string {
    const totalSeconds = Math.floor(seconds);
    const minutes = Math.floor(totalSeconds / 60);
    const secs = totalSeconds % 60;
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
}

</script>

<template>
  <div
    class="file px-4 py-1 flex justify-between items-center rounded border-b border-border transition-all duration-300 relative hover:bg-accent/50"
    :class="{
      'bg-primary text-primary-foreground': currentFileId === item.id,
      'transform -translate-x-32': isSwipedOpen
    }"
    @touchstart="emit('touchStart', $event)"
    @touchmove="emit('touchMove', $event)"
    @touchend="emit('touchEnd', item)"
    @mousedown="emit('touchStart', $event)"
    @mousemove="emit('touchMove', $event)"
    @mouseup="emit('touchEnd', item)"
    @mouseleave="$event.buttons && emit('touchEnd', item)"
  >
    <div class="flex gap-2 items-center group">
        <button
            class="cursor-pointer  text-white w-10 mr-6 flex items-center justify-center"
            @click.stop="handlePlay($event)"
        >

            <span v-if="currentFileId !== item.id"
                  @click.stop="handlePlay($event)">{{ index}}</span>

            <template>
                <Play v-if="!isPlaying || currentFileId !== item.id" :size="20" />
                <Pause v-else :size="20" />
            </template>
        </button>
      <div class="w-16 h-16 flex-shrink-0 overflow-hidden rounded relative">
        <!-- Loading skeleton for cover -->
        <Skeleton v-if="!loadedFile" class="w-full h-full" />
        <!-- Actual cover image when loaded -->
        <template v-else>
          <img
            v-if="coverImage"
            :src="`/atlas/${coverImage}`"
            alt="Cover"
            class="w-full h-full object-cover"
          />
          <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
            <span class="text-xs">No Cover</span>
          </div>
        </template>
      </div>
      <div class="flex flex-col w-100">
        <!-- Loading skeleton for artist name -->
        <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-1" />
        <span v-else class="text-xs font-semibold">{{
          loadedFile.artists && loadedFile.artists.length > 0
            ? loadedFile.artists[0].name
            : 'Unknown Artist'
        }}</span>

        <!-- Loading skeleton for title -->
        <Skeleton v-if="!loadedFile" class="h-4 w-32" />
        <span v-else>{{ loadedFile?.metadata?.payload?.title || 'Untitled' }}</span>
      </div>

        <div class="hidden md:block ml-auto w-100">
            <span class="text-xs text-muted-foreground">
                {{ loadedFile?.albums[0].name || 'Unknown Album' }}
            </span>
        </div>

        <div class="hidden md:block">
            <span class="text-xs text-muted-foreground">
                {{ loadedFile?.metadata?.payload?.duration ? convertToDuration(loadedFile.metadata.payload.duration) : 'Unknown Duration' }}
            </span>
        </div>
    </div>

    <!-- Action buttons container -->
    <div class="absolute top-0 left-full h-full items-center flex gap-4 p-4">
      <button
        class="text-foreground hover:text-destructive transition-colors p-1 rounded"
        :class="{ 'text-red-500 bg-red-500/20': loadedFile?.loved }"
        @click.stop="handleFavorite($event)"
      >
        <Heart :size="20" :fill="loadedFile?.loved ? 'currentColor' : 'none'" />
      </button>
      <button
        class="text-foreground hover:text-secondary transition-colors p-1 rounded"
        :class="{ 'text-blue-500 bg-blue-500/20': loadedFile?.liked }"
        @click.stop="handleLike($event)"
      >
        <ThumbsUp :size="20" :fill="loadedFile?.liked ? 'currentColor' : 'none'" />
      </button>
      <button
        class="text-foreground hover:text-destructive transition-colors p-1 rounded"
        :class="{ 'text-gray-500 bg-gray-500/20': loadedFile?.disliked }"
        @click.stop="handleDislike($event)"
      >
        <ThumbsDown :size="20" :fill="loadedFile?.disliked ? 'currentColor' : 'none'" />
      </button>
    </div>
  </div>
</template>
