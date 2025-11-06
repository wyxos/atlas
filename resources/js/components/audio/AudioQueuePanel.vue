<script setup lang="ts">
import { computed, ref } from 'vue';
import { RecycleScroller } from 'vue-virtual-scroller';
import 'vue-virtual-scroller/dist/vue-virtual-scroller.css';
import { X } from 'lucide-vue-next';
import { useAudioFileLoader } from '@/composables/useAudioFileLoader';
import { useAudioPlayer } from '@/stores/audio';
import { Skeleton } from '@/components/ui/skeleton';

const props = defineProps<{ isOpen: boolean }>();
defineEmits<{ (e: 'close'): void }>();

const scrollerRef = ref<InstanceType<typeof RecycleScroller> | null>(null);

const { loadedFiles, loadBatchFileDetails } = useAudioFileLoader();
const { queue, currentTrack, currentIndex, playTrackAtIndex } = useAudioPlayer();

const items = computed(() => queue.value);

function onScrollerUpdate(
  startIndex: number,
  endIndex: number,
  visibleStartIndex: number,
  visibleEndIndex: number,
) {
  const ids: number[] = [];
  const arr = items.value as any[];
  for (let i = visibleStartIndex; i <= visibleEndIndex; i++) {
    if (i >= 0 && i < arr.length) {
      const it = arr[i];
      if (it && !loadedFiles[it.id]) {
        ids.push(it.id);
      }
    }
  }
  if (ids.length) {
    loadBatchFileDetails(ids);
  }
}


function getLoaded(item: any) {
  // Queue items may already have file data spread into them
  if (item.metadata || item.artists || item.covers) {
    return item;
  }
  return loadedFiles[item.id] || item;
}
function isLoaded(item: any): boolean {
  // Check if item already has metadata (was spread when queued)
  if (item.metadata || item.artists || item.covers) {
    return true;
  }
  return !!loadedFiles[item.id];
}

function coverImage(file: any): string | null {
  if (!file) return null;
  if (file.albums && file.albums.length > 0) {
    for (const album of file.albums) {
      if (album.covers && album.covers.length > 0) {
        return album.covers[0].url || album.covers[0].path;
      }
    }
  }
  if (file.covers && file.covers.length > 0) {
    return file.covers[0].url || file.covers[0].path;
  }
  return null;
}

function artistName(file: any): string {
  const artists = file?.artists;
  return artists && artists.length ? artists[0]?.name || 'Unknown Artist' : 'Unknown Artist';
}

function titleText(file: any): string {
  return file?.metadata?.payload?.title || 'Untitled';
}

</script>

<template>
  <div class="fixed inset-0 z-40 pointer-events-none">
    <!-- Overlay -->
    <div
      class="absolute inset-0 bg-black/40 transition-opacity duration-300"
      :class="isOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none'"
      @click="$emit('close')"
    />
    <!-- Panel -->
    <div
      class="absolute right-0 top-0 h-full w-[360px] sm:w-[420px] max-w-[90vw] bg-card border-l border-border shadow-xl transform transition-transform duration-300 pointer-events-auto flex flex-col"
      :class="isOpen ? 'translate-x-0' : 'translate-x-full'"
      role="dialog"
      aria-label="Queue panel"
    >
      <!-- Header -->
      <div class="flex items-center justify-between p-4 border-b border-border">
        <h2 class="text-base font-semibold">Queue</h2>
        <button class="button circular small empty" title="Close Queue" @click="$emit('close')">
          <X :size="16" />
          <span class="sr-only">Close Queue</span>
        </button>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-hidden">
        <div v-if="items.length === 0" class="h-full flex items-center justify-center text-sm text-muted-foreground">
          No tracks in queue
        </div>
        <RecycleScroller
          v-else
          ref="scrollerRef"
          class="h-full"
          :items="items"
          :item-size="74"
          key-field="id"
          :emit-update="true"
          @update="onScrollerUpdate"
          v-slot="{ item, index }"
        >
          <div
            class="px-3 py-2 flex items-center gap-3 border-b border-border cursor-pointer hover:bg-accent/50 transition-colors"
            :class="{
              'bg-primary/10 ring-2 ring-emerald-400/60': currentTrack?.id === item.id,
              'bg-red-500/10 text-red-300 border-red-500/30': !!(getLoaded(item) && getLoaded(item).not_found === true),
            }"
            :data-id="item.id"
            :data-test="(getLoaded(item) && getLoaded(item).not_found) ? 'queue-row-not-found' : null"
            @click="playTrackAtIndex(index, { autoPlay: true })"
          >
            <!-- Index -->
            <div class="w-6 flex items-center justify-end select-none">
              <span class="text-xs text-muted-foreground">{{ index + 1 }}</span>
            </div>

            <!-- Cover -->
            <div class="w-12 h-12 overflow-hidden rounded bg-muted flex items-center justify-center">
              <template v-if="!isLoaded(item)">
                <Skeleton class="h-full w-full" />
              </template>
              <template v-else>
                <img v-if="coverImage(getLoaded(item))" :src="coverImage(getLoaded(item))!" alt="Cover" class="w-full h-full object-cover" />
                <span v-else class="text-xs text-muted-foreground">♪</span>
              </template>
            </div>

            <!-- Text -->
            <div class="min-w-0 flex-1">
              <template v-if="!isLoaded(item)">
                <div class="space-y-1">
                  <Skeleton class="h-3 w-24" />
                  <Skeleton class="h-4 w-40" />
                </div>
              </template>
              <template v-else>
                <div class="truncate text-xs text-muted-foreground">
                  {{ artistName(getLoaded(item)) }}
                </div>
                <div class="truncate text-sm font-medium">
                  {{ titleText(getLoaded(item)) }}
                </div>
              </template>
            </div>
          </div>
        </RecycleScroller>
      </div>
    </div>
  </div>
</template>

<style>
@keyframes quiet { 25% { transform: scaleY(.6); } 50% { transform: scaleY(.4); } 75% { transform: scaleY(.8); } }
@keyframes normal { 25% { transform: scaleY(1); } 50% { transform: scaleY(.4); } 75% { transform: scaleY(.6); } }
@keyframes loud { 25% { transform: scaleY(1); } 50% { transform: scaleY(.4); } 75% { transform: scaleY(1.7); } }
.boxContainer { display: flex; justify-content: space-between; height: 14px; --boxSize: 2px; --gutter: 2px; width: calc((var(--boxSize) + var(--gutter)) * 5); }
.box { transform: scaleY(.4); height: 100%; width: var(--boxSize); background: #12E2DC; animation-duration: 1s; animation-timing-function: ease-in-out; animation-iteration-count: infinite; border-radius: 2px; }
.box1 { animation-name: quiet; }
.box2 { animation-name: normal; }
.box3 { animation-name: quiet; }
.box4 { animation-name: loud; }
.box5 { animation-name: quiet; }
</style>
