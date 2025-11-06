import { ref, computed, onMounted, onUnmounted } from 'vue';
import { RecycleScroller } from 'vue-virtual-scroller';
import debounce from 'lodash/debounce';
import { router } from '@inertiajs/vue3';
import { useAudioFileLoader } from '@/composables/useAudioFileLoader';
import { bus } from '@/lib/bus';

export interface UseAudioListPageOptions {
  files: () => { id: number }[];
  search: () => { id: number }[];
  idOrder: () => number[];
  initialQuery?: string;
  getSearchAction: (q: string) => { url: string };
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

  const recycleScrollerRef = ref<InstanceType<typeof RecycleScroller>>();
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

  onMounted(() => {
    bus.on('file:reaction', handleReactionEvent);
  });

  onUnmounted(() => {
    bus.off('file:reaction', handleReactionEvent);
  });

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
    recycleScrollerRef,
    // actions
    updateSearch,
    onScrollerUpdate,
  };
}

