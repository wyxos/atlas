import { computed, ref, watch, type Ref } from 'vue';
import type {
    DownloadQueueFilterStatus,
    DownloadQueueItem,
    DownloadQueueSortDirection,
    DownloadQueueSortKey,
} from '@/types/downloadQueue';
import {
    canCancelDownloadQueueItem,
    canPauseDownloadQueueItem,
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
    compareDownloadQueueItems,
} from '@/utils/downloadQueue';
import { DEFAULT_DOWNLOAD_QUEUE_SORT } from '@/types/downloadQueue';

export function useDownloadsQueueTableState(params: {
    downloads: Ref<DownloadQueueItem[]>;
}) {
    const selectedStatus = ref<DownloadQueueFilterStatus>('all');
    const sortKey = ref<DownloadQueueSortKey | null>(null);
    const sortDirection = ref<DownloadQueueSortDirection>('asc');
    const lastNonCompletedSort = ref<{
        key: DownloadQueueSortKey | null;
        direction: DownloadQueueSortDirection;
    } | null>(null);
    const selectedIds = ref<Set<number>>(new Set());
    const lastSelectedIndex = ref<number | null>(null);

    const filteredItems = computed(() =>
        selectedStatus.value === 'all'
            ? params.downloads.value
            : params.downloads.value.filter((item) => item.status === selectedStatus.value),
    );

    const statusCounts = computed(() =>
        params.downloads.value.reduce((acc, item) => {
            acc[item.status] = (acc[item.status] ?? 0) + 1;
            return acc;
        }, {} as Record<string, number>),
    );

    const sortedItems = computed(() => {
        const key = sortKey.value ?? DEFAULT_DOWNLOAD_QUEUE_SORT.key;
        const direction = sortKey.value ? sortDirection.value : DEFAULT_DOWNLOAD_QUEUE_SORT.direction;
        return filteredItems.value.slice().sort((a, b) => compareDownloadQueueItems(a, b, key, direction));
    });

    const filteredIds = computed(() => filteredItems.value.map((item) => item.id));
    const selectedCount = computed(() => selectedIds.value.size);
    const selectedInFilterCount = computed(() =>
        filteredIds.value.filter((id) => selectedIds.value.has(id)).length,
    );
    const selectedIdsList = computed(() => Array.from(selectedIds.value));
    const selectedItems = computed(() =>
        params.downloads.value.filter((item) => selectedIds.value.has(item.id)),
    );
    const lastSelectedId = computed(() =>
        lastSelectedIndex.value === null ? null : (sortedItems.value[lastSelectedIndex.value]?.id ?? null),
    );
    const selectedPausableIds = computed(() =>
        selectedItems.value.filter((item) => canPauseDownloadQueueItem(item)).map((item) => item.id),
    );
    const selectedResumableIds = computed(() =>
        selectedItems.value.filter((item) => canResumeDownloadQueueItem(item)).map((item) => item.id),
    );
    const selectedCancelableIds = computed(() =>
        selectedItems.value.filter((item) => canCancelDownloadQueueItem(item)).map((item) => item.id),
    );
    const selectedRestartableIds = computed(() =>
        selectedItems.value.filter((item) => canRestartDownloadQueueItem(item)).map((item) => item.id),
    );
    const resumableFailedIds = computed(() =>
        params.downloads.value
            .filter((item) => item.status === 'failed' && item.can_resume)
            .map((item) => item.id),
    );
    const restartableFailedIds = computed(() =>
        params.downloads.value
            .filter((item) => item.status === 'failed' && item.can_restart)
            .map((item) => item.id),
    );
    const completedIds = computed(() =>
        params.downloads.value.filter((item) => item.status === 'completed').map((item) => item.id),
    );
    const allFilteredSelected = computed(() =>
        filteredIds.value.length > 0 && selectedInFilterCount.value === filteredIds.value.length,
    );
    const someFilteredSelected = computed(() =>
        selectedInFilterCount.value > 0 && !allFilteredSelected.value,
    );

    function getSortDirection(key: DownloadQueueSortKey): DownloadQueueSortDirection | null {
        if (sortKey.value === null) {
            return key === DEFAULT_DOWNLOAD_QUEUE_SORT.key ? DEFAULT_DOWNLOAD_QUEUE_SORT.direction : null;
        }

        return sortKey.value === key ? sortDirection.value : null;
    }

    function toggleSort(key: DownloadQueueSortKey): void {
        if (sortKey.value !== key) {
            sortKey.value = key;
            sortDirection.value = 'asc';
            return;
        }

        if (sortDirection.value === 'asc') {
            sortDirection.value = 'desc';
            return;
        }

        sortKey.value = null;
        sortDirection.value = DEFAULT_DOWNLOAD_QUEUE_SORT.direction;
    }

    function setSelection(next: Set<number>): void {
        selectedIds.value = next;
    }

    function isSelected(id: number): boolean {
        return selectedIds.value.has(id);
    }

    function isLastSelected(id: number): boolean {
        if (lastSelectedIndex.value === null) {
            return false;
        }

        return sortedItems.value[lastSelectedIndex.value]?.id === id;
    }

    function selectRange(rangeIds: number[], additive: boolean): void {
        const next = additive ? new Set(selectedIds.value) : new Set<number>();
        rangeIds.forEach((id) => next.add(id));
        setSelection(next);
    }

    function selectSingle(id: number, additive: boolean): void {
        const next = additive ? new Set(selectedIds.value) : new Set<number>();

        if (additive && next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        setSelection(next);
    }

    function handleSelection(id: number, event?: Pick<MouseEvent, 'ctrlKey' | 'metaKey' | 'shiftKey'>): void {
        const allIds = sortedItems.value.map((item) => item.id);
        const index = allIds.indexOf(id);

        if (index === -1) {
            return;
        }

        const isCtrl = !!event && (event.ctrlKey || event.metaKey);
        const isShift = !!event && event.shiftKey;

        if (isShift && lastSelectedIndex.value !== null) {
            const start = Math.min(lastSelectedIndex.value, index);
            const end = Math.max(lastSelectedIndex.value, index);
            selectRange(allIds.slice(start, end + 1), isCtrl);
        } else {
            selectSingle(id, isCtrl);
        }

        lastSelectedIndex.value = index;
    }

    function toggleItemSelection(id: number): void {
        const next = new Set(selectedIds.value);

        if (next.has(id)) {
            next.delete(id);
        } else {
            next.add(id);
        }

        setSelection(next);
        lastSelectedIndex.value = sortedItems.value.findIndex((item) => item.id === id);
    }

    function toggleSelectAll(checked: boolean): void {
        const next = new Set(selectedIds.value);

        if (checked) {
            filteredIds.value.forEach((id) => next.add(id));
        } else {
            filteredIds.value.forEach((id) => next.delete(id));
        }

        setSelection(next);
    }

    watch(selectedStatus, (next, prev) => {
        if (next === 'completed') {
            lastNonCompletedSort.value = { key: sortKey.value, direction: sortDirection.value };
            sortKey.value = 'completedAt';
            sortDirection.value = 'desc';
        } else if (prev === 'completed' && lastNonCompletedSort.value) {
            sortKey.value = lastNonCompletedSort.value.key;
            sortDirection.value = lastNonCompletedSort.value.direction;
            lastNonCompletedSort.value = null;
        }
    });

    watch(params.downloads, () => {
        const validIds = new Set(params.downloads.value.map((item) => item.id));
        const next = new Set([...selectedIds.value].filter((id) => validIds.has(id)));

        if (next.size !== selectedIds.value.size) {
            setSelection(next);
        }
    });

    return {
        selectedStatus,
        filteredItems,
        statusCounts,
        sortKey,
        sortDirection,
        toggleSort,
        getSortDirection,
        filteredIds,
        sortedItems,
        selectedIds,
        selectedCount,
        selectedInFilterCount,
        selectedIdsList,
        selectedPausableIds,
        selectedResumableIds,
        selectedCancelableIds,
        selectedRestartableIds,
        lastSelectedId,
        resumableFailedIds,
        restartableFailedIds,
        completedIds,
        allFilteredSelected,
        someFilteredSelected,
        setSelection,
        isSelected,
        isLastSelected,
        handleSelection,
        toggleItemSelection,
        toggleSelectAll,
    };
}
