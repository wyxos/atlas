<script setup lang="ts">
import { computed, ref } from 'vue';
import { Play, Pause, Music } from 'lucide-vue-next';
import { Skeleton } from '@/components/ui/skeleton';
import FileReactions from '@/components/audio/FileReactions.vue';

const props = withDefaults(defineProps<{
  item: any;
  // 1-based index for display
  index: number;
  // 0-based index for selection/range math
  rowIndex: number;
  loadedFile: any | null;
  isPlaying?: boolean;
  currentFileId?: number | null;
  highlightId?: number | null;
  // Selection visual state
  isSelected?: boolean;
}>(), {
  isPlaying: false,
  currentFileId: null,
});

const emit = defineEmits<{
  (e: 'play', file: any): void;
  (e: 'favorite', item: any, event: Event): void;
  (e: 'like', item: any, event: Event): void;
  (e: 'dislike', item: any, event: Event): void;
  (e: 'laughedAt', item: any, event: Event): void;
  (e: 'rowClick', payload: { item: any; index: number; event: MouseEvent }): void;
}>();

// Computed property to get the cover image with priority: album covers first, then file covers
const coverImage = computed((): string | null => {
  if (!props.loadedFile) return null;

  // First check for album covers
  if (props.loadedFile.albums && props.loadedFile.albums.length > 0) {
    for (const album of props.loadedFile.albums) {
      if (album.covers && album.covers.length > 0) {
        // Use url if available (from temporary URL), fallback to path
        return album.covers[0].url || album.covers[0].path;
      }
    }
  }

  // Fall back to file covers
  if (props.loadedFile.covers && props.loadedFile.covers.length > 0) {
    // Use url if available (from temporary URL), fallback to path
    return props.loadedFile.covers[0].url || props.loadedFile.covers[0].path;
  }

  return null;
});

// Handle play button click
function handlePlay(event: Event): void {
  event.stopPropagation();
  emit('play', props.item);
}

// Hover state for play/pause visibility
const isHovered = ref(false);

function handleRowClick(event: MouseEvent): void {
  // Forward click info with the 0-based index for range selection math
  emit('rowClick', { item: props.item, index: props.rowIndex, event });
}

// Convert duration from e.g 177.99836734693878 to a human-readable format
function convertToDuration(seconds: number): string {
    const totalSeconds = Math.floor(seconds);
    const minutes = Math.floor(totalSeconds / 60);
    const secs = totalSeconds % 60;
    return `${minutes}:${secs < 10 ? '0' : ''}${secs}`;
}

// Forward reaction events from child component
function handleFavorite(event: Event): void {
  emit('favorite', props.loadedFile || props.item, event);
}

function handleLike(event: Event): void {
  emit('like', props.loadedFile || props.item, event);
}

function handleDislike(event: Event): void {
  emit('dislike', props.loadedFile || props.item, event);
}

function handleLaughedAt(event: Event): void {
  emit('laughedAt', props.loadedFile || props.item, event);
}


// Source badge (desktop-only, any source)
const sourceName = computed(() => {
  const src = (props.loadedFile?.source || '').toString().trim();
  if (src) return src;
  const mime = (props.loadedFile?.mime_type || '').toLowerCase();
  if (mime === 'audio/spotify') return 'Spotify';
  if (props.loadedFile?.path) return 'Local';
  return null;
});
function sourceClass(name: string) {
  const n = name.toLowerCase();
  if (n === 'spotify') return 'bg-emerald-500/10 text-emerald-400 border-emerald-400/25';
  if (n === 'civitai') return 'bg-indigo-500/10 text-indigo-400 border-indigo-400/25';
  if (n === 'local') return 'bg-slate-500/10 text-slate-300 border-slate-500/25';
  return 'bg-muted text-muted-foreground border-border/50';
}
</script>

<template>
    <div class="file px-4 py-1 flex justify-between items-center rounded border-b border-border transition-all duration-300 relative hover:bg-accent/50 select-none"
    :class="{
      'bg-primary/10': currentFileId === item.id,
      'ring-2 ring-emerald-400/60': highlightId === item.id,
      'bg-accent/30 ring-1 ring-primary/40': !!isSelected,
      'bg-red-500/10 text-red-300 border-red-500/30': !!(loadedFile && loadedFile.not_found === true),
    }"
    :data-test="(loadedFile && loadedFile.not_found) ? 'audio-row-not-found' : null"
    @mouseenter="isHovered = true"
    @mouseleave="isHovered = false"
    @dblclick.stop="emit('play', item)"
    @click="handleRowClick"
  >
    <!-- Accessibility marker for tests when scrolled to current -->
    <span v-if="highlightId === item.id" class="sr-only">Scrolled to current</span>
    <div class="flex gap-4 items-center flex-1" :data-id="item.id">
      <!-- Play button and index -->
      <button
        class="hidden cursor-pointer w-10 mr-2 md:flex items-center justify-center"
        @click.stop="handlePlay($event)"
        :title="isPlaying && currentFileId === item.id ? 'Pause' : 'Play'"
        :aria-label="isPlaying && currentFileId === item.id ? 'Pause' : 'Play'"
        :data-testid="`audio-item-${item.id}-play`"
      >
        <template v-if="isHovered">
          <Pause v-if="isPlaying && currentFileId === item.id" :size="20" />
          <Play v-else :size="20" />
        </template>
        <template v-else>
          <template v-if="isPlaying && currentFileId === item.id">
            <div class="boxContainer">
              <div class="box box1"></div>
              <div class="box box2"></div>
              <div class="box box3"></div>
              <div class="box box4"></div>
              <div class="box box5"></div>
            </div>
          </template>
          <template v-else>
            <span class="text-sm font-medium text-muted-foreground">{{ index }}</span>
          </template>
        </template>
        <span class="sr-only">{{ isPlaying && currentFileId === item.id ? 'Pause' : 'Play' }}</span>
      </button>

      <!-- Cover image -->
      <div
        class="w-16 h-16 flex-shrink-0 overflow-hidden rounded relative transition-all duration-300"
      >
        <!-- Loading skeleton for cover -->
        <Skeleton v-if="!loadedFile" class="w-full h-full" />
        <!-- Actual cover image when loaded -->
        <template v-else>
          <img
            v-if="coverImage"
            :src="coverImage"
            alt="Cover"
            class="w-full h-full object-cover select-none"
            draggable="false"
          />
          <div v-else class="w-full h-full bg-muted flex items-center justify-center text-muted-foreground">
            <Music :size="40" />
          </div>
        </template>
      </div>

      <!-- Track info -->
      <div class="flex flex-col w-160">
        <!-- Loading skeleton for artist name -->
        <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-2" />
        <span
          v-else
          class="text-xs font-semibold truncate mb-2"
          :class="{ 'text-emerald-500': currentFileId === item.id }"
        >{{
          loadedFile.artists && loadedFile.artists.length > 0
            ? loadedFile.artists[0]?.name
            : 'Unknown Artist'
        }}</span>

        <!-- Loading skeleton for title -->
        <Skeleton v-if="!loadedFile" class="h-5 w-32" />
        <span
          v-else
          class="text-sm font-medium truncate"
          :class="{ 'text-emerald-500': currentFileId === item.id }"
        >{{ loadedFile?.metadata?.payload?.title || 'Untitled' }}</span>
      </div>

      <!-- Album name + source badge (hidden on mobile) -->
      <div class="hidden md:flex w-160 items-center gap-2">
        <Skeleton v-if="!loadedFile" class="h-4 w-24 mb-1" />
        <span class="text-sm truncate block" v-else>
          {{ loadedFile?.albums?.[0]?.name || 'Unknown Album' }}
        </span>
        <span v-if="loadedFile && sourceName" class="hidden md:inline-block text-[10px] px-1.5 py-0.5 rounded border uppercase tracking-wide"
              :class="sourceClass(sourceName as string)"
              :title="sourceName as string"
              data-test="badge-source">
          {{ sourceName }}
        </span>
      </div>

      <!-- Duration -->
      <div class="hidden md:block">
        <Skeleton v-if="!loadedFile" class="h-4 w-16 mb-1" />
        <span class="text-xs" v-else>
          {{ loadedFile?.metadata?.payload?.duration ? convertToDuration(loadedFile.metadata.payload.duration) : '--:--' }}
        </span>
      </div>
    </div>

    <!-- Action buttons container -->
    <div class="absolute top-0 left-full md:static h-full items-center flex p-4">
      <template v-if="loadedFile">
        <FileReactions
          :file="loadedFile || item"
          :size="20"
          @favorite="handleFavorite"
          @like="handleLike"
          @dislike="handleDislike"
          @laughed-at="handleLaughedAt"
        />
      </template>
      <div v-else class="flex items-center gap-2">
        <Skeleton class="h-9 w-9 rounded-md" />
        <Skeleton class="h-9 w-9 rounded-md" />
        <Skeleton class="h-9 w-9 rounded-md" />
        <Skeleton class="h-9 w-9 rounded-md" />
      </div>
    </div>
    <span v-if="highlightId === item.id" class="sr-only">Scrolled to current</span>
  </div>
</template>

<style>
@keyframes quiet {
  25% { transform: scaleY(.6); }
  50% { transform: scaleY(.4); }
  75% { transform: scaleY(.8); }
}
@keyframes normal {
  25% { transform: scaleY(1); }
  50% { transform: scaleY(.4); }
  75% { transform: scaleY(.6); }
}
@keyframes loud {
  25% { transform: scaleY(1); }
  50% { transform: scaleY(.4); }
  75% { transform: scaleY(1.7); }
}
.boxContainer {
  display: flex;
  justify-content: space-between;
  height: 20px;
  --boxSize: 4px;
  --gutter: 3px;
  width: calc((var(--boxSize) + var(--gutter)) * 5);
}
.box {
  transform: scaleY(.4);
  height: 100%;
  width: var(--boxSize);
  background: #12E2DC;
  animation-duration: 1s;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
  border-radius: 3px;
}
.box1 { animation-name: quiet; }
.box2 { animation-name: normal; }
.box3 { animation-name: quiet; }
.box4 { animation-name: loud; }
.box5 { animation-name: quiet; }
</style>
