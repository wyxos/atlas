<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import * as AudioReactionsController from '@/actions/App/Http/Controllers/AudioReactionsController';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { RecycleScroller } from 'vue-virtual-scroller';
import AudioListItem from '@/components/audio/AudioListItem.vue';
import SectionHeader from '@/components/audio/SectionHeader.vue';
import SearchBar from '@/components/audio/SearchBar.vue';
import { Music, Search, Shuffle as ShuffleIcon, Play as PlayIcon } from 'lucide-vue-next';
import { useAudioListPage } from '@/composables/useAudioListPage';
import { useAudioReactions } from '@/composables/useAudioReactions';
import { useAudioPlayer, type AudioTrack } from '@/stores/audio';
import * as AudioController from '@/actions/App/Http/Controllers/AudioController';
import { computed, onMounted, ref, watch, onUnmounted } from 'vue';
import { useEcho } from '@laravel/echo-vue';
import ContentLayout from '@/layouts/ContentLayout.vue';
import ScrollableLayout from '@/layouts/ScrollableLayout.vue';
import { bus } from '@/lib/bus';

interface AudioItem { id: number }

const props = defineProps<{ 
  files: AudioItem[];
  search: AudioItem[];
  playlistFileIds: number[];
  playlistId?: number;
  reactionType?: string;
  query?: string;
  isSpotifyPlaylist?: boolean;
  containsSpotify?: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'All songs', href: props.playlistId ? `/playlists/${props.playlistId}` : '/' },
];

const pageFiles = ref([...props.files]);
const pageSearch = ref([...props.search]);
const pageOrder = ref([...props.playlistFileIds]);

watch(
  () => props.files,
  (val) => { pageFiles.value = Array.isArray(val) ? [...val] : []; },
);

watch(
  () => props.search,
  (val) => { pageSearch.value = Array.isArray(val) ? [...val] : []; },
);

watch(
  () => props.playlistFileIds,
  (val) => { pageOrder.value = Array.isArray(val) ? [...val] : []; },
);

const reactionManagedTypes = new Set(['favorites', 'liked', 'funny', 'disliked', 'unrated']);

function matchesReaction(type: string, payload: { loved: boolean; liked: boolean; disliked: boolean; funny: boolean }): boolean {
  switch (type) {
    case 'favorites':
      return payload.loved;
    case 'liked':
      return payload.liked;
    case 'funny':
      return payload.funny;
    case 'disliked':
      return payload.disliked;
    case 'unrated':
      return !payload.loved && !payload.liked && !payload.disliked && !payload.funny;
    default:
      return true;
  }
}

function removeFromLists(id: number) {
  pageFiles.value = pageFiles.value.filter((item) => item.id !== id);
  pageOrder.value = pageOrder.value.filter((existing) => existing !== id);
  pageSearch.value = pageSearch.value.filter((item) => item.id !== id);
}

function addToLists(id: number) {
  if (!pageOrder.value.includes(id)) {
    pageOrder.value = [id, ...pageOrder.value];
  }

  if (!pageFiles.value.some((item) => item.id === id)) {
    pageFiles.value = [{ id }, ...pageFiles.value];
  }

  if (pageSearch.value.length > 0 && !pageSearch.value.some((item) => item.id === id)) {
    pageSearch.value = [{ id }, ...pageSearch.value];
  }
}

function handleReactionEvent(payload: { id: number; loved: boolean; liked: boolean; disliked: boolean; funny: boolean }) {
  if (!props.reactionType || !reactionManagedTypes.has(props.reactionType)) {
    return;
  }

  const shouldInclude = matchesReaction(props.reactionType, payload);
  const isPresent = pageOrder.value.includes(payload.id);

  if (shouldInclude && !isPresent) {
    addToLists(payload.id);
  } else if (!shouldInclude && isPresent) {
    removeFromLists(payload.id);
  }
}


const getSearchAction = (q: string) => {
  // If reactionType is provided, use AudioReactionsController
  if (props.reactionType) {
    return q
      ? AudioReactionsController.index(props.reactionType, { query: { query: q } })
      : AudioReactionsController.index(props.reactionType);
  }
  // Placeholder for playlist search - will be implemented with new player
  return { url: '' };
};


const {
  searchQuery,
  isSearching,
  filteredItems,
  loadedFiles,
  recycleScrollerRef,
  updateSearch,
  onScrollerUpdate,
} = useAudioListPage({
  files: () => pageFiles.value,
  search: () => pageSearch.value,
  idOrder: () => pageOrder.value,
  initialQuery: props.query,
  getSearchAction,
  onReactionEvent: handleReactionEvent,
});

// Active search state: consider a search "active" when query exists and is non-empty
const isSearchActive = computed(() => !!(props.query && props.query.trim().length > 0));

const { toggleFavorite, likeItem, dislikeItem, laughedAtItem } = useAudioReactions(loadedFiles);

// Audio player
const {
  currentTrack,
  queue,
  currentIndex,
  isPlaying,
  currentTime,
  duration,
  volume,
  setQueueAndPlay,
  setVolume,
} = useAudioPlayer();

// Handle play from AudioListItem
async function handlePlay(track: any): Promise<void> {
  // Build queue from all filtered items
  const queueItems: AudioTrack[] = filteredItems.value.map((item) => {
    const file = loadedFiles[item.id];
    const streamUrl = AudioController.stream({ file: item.id }).url;
    
    // Debug: log the generated URL
    if (item.id === track.id) {
      console.log('Stream URL for track:', streamUrl, 'File ID:', item.id);
    }
    
    // Ensure url is set correctly - it must be the stream URL, not the file path
    const queueItem: AudioTrack = {
      ...(file || {}),
      id: item.id,
      url: streamUrl, // Set url last to ensure it's not overridden
    };
    
    // Debug: verify url is set correctly
    if (item.id === track.id) {
      console.log('Queue item URL:', queueItem.url, 'Queue item path:', queueItem.path);
    }
    
    return queueItem;
  });

  // Find the index of the clicked track
  const startIndex = queueItems.findIndex((item) => item.id === track.id);
  
  if (startIndex >= 0) {
    await setQueueAndPlay(queueItems, startIndex, { autoPlay: true });
  }
}

// Selection state
const selectedIds = ref<Set<number>>(new Set());
const lastSelectedIndex = ref<number | null>(null);
const selectedCount = computed(() => selectedIds.value.size);

function clearSelection() {
  selectedIds.value = new Set();
  lastSelectedIndex.value = null;
}

watch(() => props.playlistId, () => {
  clearSelection();
});

onMounted(async () => {
  // Placeholder for autoplay/autoshuffle logic when player is rebuilt
});

onUnmounted(() => {});

function handleRowClick(payload: { item: any; index: number; event: MouseEvent }) {
  const { item, index, event } = payload;
  const id = item.id as number;
  const ctrl = event.ctrlKey || event.metaKey;
  const shift = event.shiftKey;

  if (shift) {
    // If no anchor yet, treat as single-select and set anchor
    if (lastSelectedIndex.value == null) {
      selectedIds.value = new Set([id]);
      lastSelectedIndex.value = index;
      return;
    }
    const start = Math.min(lastSelectedIndex.value, index);
    const end = Math.max(lastSelectedIndex.value, index);
    const rangeIds: number[] = [];
    const items = filteredItems.value as any[];
    for (let i = start; i <= end; i++) {
      const it = items[i];
      if (it && typeof it.id === 'number') rangeIds.push(it.id);
    }
    selectedIds.value = new Set(rangeIds);
    lastSelectedIndex.value = index; // update anchor to latest click
    return;
  }

  if (ctrl) {
    const copy = new Set(selectedIds.value);
    if (copy.has(id)) copy.delete(id); else copy.add(id);
    selectedIds.value = copy;
    lastSelectedIndex.value = index;
    return;
  }

  // Single click: select only this row
  selectedIds.value = new Set([id]);
  lastSelectedIndex.value = index;
}

// Listen for playlist membership changes and refresh when current playlist is affected
const page = usePage();
const authUser = (page.props as any)?.auth?.user;
if (authUser?.id) {
  const channel = `App.Models.User.${authUser.id}`;
  if (props.playlistId) {
    useEcho(channel, '.playlist.membership.changed', (e: { file_id: number; previous_playlist_id: number | null; new_playlist_id: number | null }) => {
      const affectsThisPage = props.playlistId === e.previous_playlist_id || props.playlistId === e.new_playlist_id;
      if (affectsThisPage) {
        router.reload({ only: ['files', 'playlistFileIds', 'search', 'query'], preserveState: true, preserveScroll: true });
      }
    });
  }

  useEcho(channel, '.file.reaction.updated', (e: { file_id: number; loved: boolean; liked: boolean; disliked: boolean; funny: boolean }) => {
    bus.emit('file:reaction', {
      id: e.file_id,
      loved: !!e.loved,
      liked: !!e.liked,
      disliked: !!e.disliked,
      funny: !!e.funny,
    });
  });
}
</script>

<template>
  <Head title="All songs" />
  <AppLayout :breadcrumbs="breadcrumbs">
    <ContentLayout>
        <SectionHeader title="Audio Library" :count="filteredItems.length" :icon="Music" />
        <div class="flex items-center justify-between gap-2 mb-2">
            <SearchBar v-model="searchQuery" :loading="isSearching" placeholder="Search for audio files..." @update:modelValue="updateSearch" />
            <div class="flex items-center gap-3">
                <div v-if="selectedCount > 0" class="text-xs text-muted-foreground">
                    {{ selectedCount }} selected
                </div>
                <button class="group p-2 rounded-md hover:bg-primary disabled:opacity-50 border border-white" :disabled="pageOrder.length === 0" title="Play" data-test="playlist-play-all">
                    <PlayIcon :size="40" class="text-muted-foreground group-hover:text-white" />
                </button>
                <button class="group p-2 rounded-md hover:bg-primary disabled:opacity-50 border border-white" :disabled="pageOrder.length === 0" title="Shuffle" data-test="playlist-shuffle-all">
                    <ShuffleIcon :size="40" class="text-muted-foreground group-hover:text-white" />
                </button>
            </div>
        </div>

        <!-- Search results empty-state: show when a search is active and returned zero results -->
        <div v-if="isSearchActive && (Array.isArray($props.search) ? $props.search.length === 0 : true)" class="flex flex-1 flex-col items-center justify-center py-12">
            <Search :size="48" class="text-muted-foreground mb-4" />
            <p class="text-lg font-medium text-muted-foreground">No results found</p>
            <p class="text-sm text-muted-foreground">Try a different search term</p>
        </div>

        <!-- Virtual scroller with audio list: show when not actively searching, or when search has results -->
        <ScrollableLayout v-else>
            <RecycleScroller
                ref="recycleScrollerRef"
                class="h-full"
                data-testid="audio-scroller"
                :items="filteredItems"
                :item-size="74"
                key-field="id"
                :emit-update="true"
                @update="onScrollerUpdate"
                v-slot="{ item, index }"
            >
                <AudioListItem
                    :key="item.id"
                    :item="item"
                    :index="(Number(index) || 0) + 1"
                    :row-index="Number(index) || 0"
                    :loaded-file="loadedFiles[item.id]"
                    :is-playing="isPlaying && currentTrack?.id === item.id"
                    :current-file-id="currentTrack?.id ?? null"
                    :is-selected="selectedIds.has(item.id)"
                    @rowClick="handleRowClick"
                    @play="handlePlay"
                    @favorite="toggleFavorite"
                    @like="likeItem"
                    @dislike="dislikeItem"
                    @laughed-at="laughedAtItem"
                />
            </RecycleScroller>
        </ScrollableLayout>

        <!-- Library empty-state: no files and no active search -->
        <div v-if="!isSearchActive && filteredItems.length === 0" class="flex flex-col items-center justify-center py-12">
            <Music :size="48" class="text-muted-foreground mb-4" />
            <p class="text-lg font-medium text-muted-foreground">No audio files found</p>
            <p class="text-sm text-muted-foreground">Add files to your library to get started</p>
        </div>
    </ContentLayout>
  </AppLayout>
</template>

