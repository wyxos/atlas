import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { RecycleScroller } from 'vue-virtual-scroller';
import debounce from 'lodash/debounce';
import { router } from '@inertiajs/vue3';
import { useAudioFileLoader } from '@/composables/useAudioFileLoader';
import { bus } from '@/lib/bus';
import { audioStore, audioActions } from '@/stores/audio';

export interface UseAudioListPageOptions {
  files: () => { id: number }[];
  search: () => { id: number }[];
  idOrder: () => number[];
  initialQuery?: string;
  getSearchAction: (q: string) => { url: string };
  beforePlaySelected?: (file: any) => Promise<void> | void;
  // Optional: the playlist id this page represents; used to tag the queue source
  playlistId?: () => number | undefined;
  // Optional: whether this playlist is Spotify-only (mark items with _engine='spotify' before queueing)
  playlistIsSpotify?: () => boolean | undefined;
  // Optional: notify listeners of reaction changes on this page
  onReactionEvent?: (payload: ReactionEventPayload) => void;
}

export interface ReactionEventPayload {
  id: number;
  loved: boolean;
  liked: boolean;
  disliked: boolean;
  funny: boolean;
}

export function useAudioListPage(options: UseAudioListPageOptions) {
  const searchQuery = ref(options.initialQuery || '');
  const isSearching = ref(false);

  const filteredItems = computed(() => {
    const s = options.search();
    if (Array.isArray(s) && s.length > 0) return s;
    return options.files();
  });

  const { loadedFiles, loadBatchFileDetails } = useAudioFileLoader();

  const isPlaying = computed(() => audioStore.isPlaying);
  const currentFileId = computed<number | null>(() => (audioStore.currentTrack ? audioStore.currentTrack.id : null));

  const recycleScrollerRef = ref<InstanceType<typeof RecycleScroller>>();
  const flashItemId = ref<number | null>(null);
  let scrollTimeout: number | null = null;

  const debouncedSearch = debounce((q: string) => {
    isSearching.value = true;
    let action: { url: string } | undefined;
    try {
      action = options.getSearchAction(q.trim());
    } catch (e) {
      console.error('getSearchAction failed', e);
      isSearching.value = false;
      return;
    }
    if (!action || !action.url) {
      isSearching.value = false;
      return;
    }
    const params: Record<string, any> = {};
    const qv = q.trim();
    if (qv.length > 0) params.query = qv; else params.query = '';
    router.get(action.url, params, {
      preserveState: true,
      only: ['search', 'query'],
      replace: true,
      onFinish: () => { isSearching.value = false; },
      onError: () => { isSearching.value = false; },
    });
  }, 300);

  function updateSearch(newQuery: string) {
    debouncedSearch(newQuery);
  }

  const handleReactionEvent = (payload?: ReactionEventPayload) => {
    if (!payload) {
      return;
    }

    const { id, loved, liked, disliked, funny } = payload;
    if (loadedFiles[id]) {
      loadedFiles[id].loved = loved;
      loadedFiles[id].liked = liked;
      loadedFiles[id].disliked = disliked;
      loadedFiles[id].funny = funny;
    }

    if (options.onReactionEvent) {
      options.onReactionEvent({ id, loved, liked, disliked, funny });
    }
  };

  const handleScrollToCurrent = async (payload?: { id: number }) => {
    if (!payload) {
      return;
    }

    const { id } = payload;
    const items = filteredItems.value;
    const index = items.findIndex((it) => it.id === id);
    if (index < 0) return;
    await nextTick();
    const scroller: any = recycleScrollerRef.value as any;
    if (scroller && typeof scroller.scrollToItem === 'function') scroller.scrollToItem(index);
    flashItemId.value = id;
    setTimeout(() => { if (flashItemId.value === id) flashItemId.value = null; }, 1500);
  };

  onMounted(() => {
    bus.on('file:reaction', handleReactionEvent);
    bus.on('player:scroll-to-current', handleScrollToCurrent);
  });

  onUnmounted(() => {
    bus.off('file:reaction', handleReactionEvent);
    bus.off('player:scroll-to-current', handleScrollToCurrent);
  });

  async function playAudio(file: any) {
    const currentId = audioStore.currentTrack ? audioStore.currentTrack.id : null;
    if (currentId === file.id) {
      if (audioStore.isPlaying) audioActions.pause(); else audioActions.play();
      return;
    }
    if (options.beforePlaySelected) {
      await options.beforePlaySelected(file);
    }
    const playlist = (options.idOrder() || []).map((id) => {
      const base: any = loadedFiles[id] || { id };
      return options.playlistIsSpotify && options.playlistIsSpotify() ? { ...base, _engine: 'spotify' } : base;
    });
    // Tag queue with the playlist it originated from (when available)
    if (typeof options.playlistId === 'function') {
      const pid = options.playlistId();
      audioStore.queuePlaylistId = typeof pid === 'number' ? pid : null;
    } else {
      audioStore.queuePlaylistId = null;
    }
    audioActions.setQueueAndPlay(playlist, file.id);
  }

  function onScrollerUpdate(
    startIndex: number,
    endIndex: number,
    visibleStartIndex: number,
    visibleEndIndex: number,
  ) {
    if (scrollTimeout !== null) { window.clearTimeout(scrollTimeout); }
    scrollTimeout = window.setTimeout(() => {
      const items = filteredItems.value;
      const fileIdsToLoad: number[] = [];
      for (let i = visibleStartIndex; i <= visibleEndIndex; i++) {
        if (i >= 0 && i < items.length) {
          const item = items[i];
          if (item && !loadedFiles[item.id]) fileIdsToLoad.push(item.id);
        }
      }
      if (fileIdsToLoad.length > 0) loadBatchFileDetails(fileIdsToLoad);
    }, 500);
  }

  return {
    // state
    searchQuery,
    isSearching,
    filteredItems,
    loadedFiles,
    isPlaying,
    currentFileId,
    recycleScrollerRef,
    flashItemId,
    // actions
    updateSearch,
    playAudio,
    onScrollerUpdate,
  };
}

