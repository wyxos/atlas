import { nextTick, type Ref } from 'vue';
import axios from 'axios';
import * as BrowseController from '@/actions/App/Http/Controllers/BrowseController';
import { undoManager } from '@/lib/undo';

export type BatchScope = { key: string; value: string | number };
export type BatchAction = 'favorite' | 'like' | 'funny' | 'dislike';

export function createBatchReact(deps: {
  items: Ref<any[]>;
  scroller: Ref<any>;
  dialogOpen?: Ref<boolean>;
  dialogItem?: Ref<any>;
  scheduleRefresh?: () => void;
  refreshOnEmpty?: boolean; // If true, refresh current page when all items removed (Photos behavior)
}) {
  const { items, scroller, dialogOpen, dialogItem, scheduleRefresh, refreshOnEmpty = false } = deps;

  function pickIdsForScope(scope: BatchScope): number[] {
    const list = items.value || [];
    return Array.from(
      new Set(
        list
          .filter((it: any) => ((it?.containers || []) as any[]).some((c: any) => c?.key === scope.key && c?.value == scope.value))
          .map((it: any) => it.id),
      ),
    );
  }

  return async function batchReact(action: BatchAction, scope: BatchScope) {
    const current = dialogItem?.value as any;
    try {
      const ids = pickIdsForScope(scope);
      if (!ids.length) return;

      // Collect snapshots and indices before removal
      const listReference = (items.value || []) as any[];
      const previousList = listReference.slice();
      const previousIndexMap: Record<number, number> = {};
      for (let i = 0; i < previousList.length; i++) previousIndexMap[previousList[i]?.id] = i;
      const currentPreviousIndex = typeof current?.id === 'number' ? (previousIndexMap[current.id] ?? -1) : -1;
      const removedIdSet = new Set<number>(ids);

      const snapshotEntries = listReference
        .map((item: any, index: number) => ({ item, index }))
        .filter(({ item }) => ids.includes(item.id))
        .map(({ item, index }) => ({
          id: item.id,
          index,
          prevType: item.loved ? 'love' : item.liked ? 'like' : item.disliked ? 'dislike' : item.funny ? 'funny' : null,
          snapshot: { ...item },
        }));

      // Optimistic UI: remove matched items from Masonry/grid
      const scrollerInstance = (scroller as any).value;
      const itemsToRemove = snapshotEntries.map((snapshotEntry) => snapshotEntry.snapshot);
      await scrollerInstance.removeMany(itemsToRemove);
      await nextTick();

      // If the currently viewed item was removed, advance to the next available item after the removal window
      if (dialogOpen?.value && current?.id && removedIdSet.has(current.id)) {
        // Find next non-removed item after the previous index based on the previous list
        let nextOldItem: any | null = null;
        for (let i = Math.max(0, currentPreviousIndex + 1); i < previousList.length; i++) {
          const candidate = previousList[i];
          if (candidate && !removedIdSet.has(candidate.id)) {
            nextOldItem = candidate;
            break;
          }
        }
        if (nextOldItem) {
          // Select it by id in the updated list
          const foundInUpdated = (items.value || []).find((item: any) => item?.id === nextOldItem!.id) || null;
          if (foundInUpdated) {
            (dialogItem as any).value = foundInUpdated;
          } else {
            // If not found (rare), try to load next and pick first
            try {
              if (scrollerInstance?.loadNext) {
                await scrollerInstance.loadNext();
                await nextTick();
              }
            } catch {}
            (dialogItem as any).value = (items.value || [])[0] || null;
            if (!(dialogItem as any).value) {
              (dialogOpen as any).value = false;
            }
          }
        } else {
          // No next item in previous list
          await nextTick();
          const remainingItems = items.value || [];
          
          // If refreshOnEmpty is true (Photos behavior) and all items were removed,
          // wait for Vibe's automatic refreshCurrentPage to complete
          if (refreshOnEmpty && remainingItems.length === 0) {
            // Wait for Vibe's automatic refresh to complete
            await new Promise(resolve => setTimeout(resolve, 150));
            await nextTick();
          } else {
            // Browse behavior or items still remain: try to load next page
            try {
              if (scrollerInstance?.loadNext) {
                await scrollerInstance.loadNext();
                await nextTick();
              }
            } catch {}
          }
          
          const first = (items.value || [])[0] || null;
          if (first) {
            (dialogItem as any).value = first;
          } else {
            (dialogOpen as any).value = false;
            (dialogItem as any).value = null;
          }
        }
      }

      const type = action === 'favorite' ? 'love' : (action as 'like' | 'funny' | 'dislike');
      const actionDescriptor = (BrowseController as any).batchReact({});
      await axios.post(actionDescriptor.url, { ids, type });

      // Undo support
      undoManager.push({
        label: `${type === 'dislike' ? 'Blacklisted' : type.charAt(0).toUpperCase() + type.slice(1)} ${ids.length} item${
          ids.length > 1 ? 's' : ''
        }`,
        previews: snapshotEntries
          .slice(0, 4)
          .map((snapshotEntry) => snapshotEntry.snapshot?.preview || snapshotEntry.snapshot?.thumbnail_url || '')
          .filter(Boolean),
        previewTitles: snapshotEntries
          .slice(0, 4)
          .map((snapshotEntry) => snapshotEntry.snapshot?.title || '')
          .filter(Boolean),
        applyUI: () => {},
        revertUI: () => {
          const list = (items.value || []) as any[];
          snapshotEntries
            .sort((a, b) => a.index - b.index)
            .forEach((snapshotEntry) => {
              const index = Math.max(0, Math.min(snapshotEntry.index, list.length));
              list.splice(index, 0, snapshotEntry.snapshot);
            });
          // Coalesced refresh (prefer provided scheduler; fallback to direct refresh)
          try {
            if (typeof scheduleRefresh === 'function') {
              scheduleRefresh();
            } else {
              (scroller as any).value?.layout?.();
            }
          } catch {}
        },
        do: async () => Promise.resolve(),
        undo: async () => {
          try {
            if (type === 'dislike') {
              const unblacklistAction = (BrowseController as any).batchUnblacklist({});
              await axios.post(unblacklistAction.url, { ids });
            }
            for (const snapshotEntry of snapshotEntries) {
              const reactAction = (BrowseController as any).react({ file: snapshotEntry.id });
              if (snapshotEntry.prevType === null) {
                await axios.post(reactAction.url, { type, state: false });
              } else {
                await axios.post(reactAction.url, { type: snapshotEntry.prevType, state: true });
              }
            }
          } catch {}
        },
      });
    } catch {
      // ignore
    }
  };
}
