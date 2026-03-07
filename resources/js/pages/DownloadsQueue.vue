<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import DownloadsQueueRemoveDialog from '@/components/DownloadsQueueRemoveDialog.vue';
import DownloadsQueueTable from '@/components/DownloadsQueueTable.vue';
import DownloadsQueueToolbar from '@/components/DownloadsQueueToolbar.vue';
import PageLayout from '@/components/PageLayout.vue';
import { useDownloadsQueueActions } from '@/composables/useDownloadsQueueActions';
import { useDownloadsQueueTableState } from '@/composables/useDownloadsQueueTableState';
import { useDownloadsQueueTransfers } from '@/composables/useDownloadsQueueTransfers';
import {
    DOWNLOAD_QUEUE_FILTERS,
    type DownloadQueueFilterStatus,
    type DownloadQueueItem,
} from '@/types/downloadQueue';

const ITEM_HEIGHT = 64;

const queueTableRef = ref<{ resetScroll: () => void } | null>(null);

const transferState = useDownloadsQueueTransfers();
const {
    downloads,
    detailsById,
    isInitialLoading,
    removeDownloads,
    cancelActiveRequest,
    scheduleVisibleDetailsFetch,
    setVisibleItems,
    handleVirtualListScroll,
} = transferState;

const tableState = useDownloadsQueueTableState({
    downloads,
});
const {
    selectedStatus,
    filteredItems,
    statusCounts,
    sortKey,
    sortDirection,
    toggleSort,
    getSortDirection,
    filteredIds,
    selectedIds,
    selectedCount,
    selectedInFilterCount,
    selectedIdsList,
    lastSelectedId,
    failedIds,
    completedIds,
    allFilteredSelected,
    someFilteredSelected,
    setSelection,
    handleSelection,
    toggleItemSelection,
    toggleSelectAll,
    sortedItems,
} = tableState;

const actionState = useDownloadsQueueActions({
    selectedIds,
    selectedIdsList,
    failedIds,
    completedIds,
    removeDownloads,
    setSelection,
});
const {
    actionBusy,
    batchIsPausing,
    batchIsCanceling,
    batchIsRetryingFailed,
    removeDialogOpen,
    removeIsDeleting,
    removeTitle,
    removeDescription,
    removeAlsoFromDisk,
    openRemoveDialog,
    confirmRemove,
    pauseSelection,
    cancelSelection,
    retryFailedDownloads,
    removeCompletedDownloads,
    pauseDownload,
    resumeDownload,
    cancelDownload,
    restartDownload,
    deleteDownload,
} = actionState;

const sortDirections = computed(() => ({
    progress: getSortDirection('progress'),
    createdAt: getSortDirection('createdAt'),
    queuedAt: getSortDirection('queuedAt'),
    startedAt: getSortDirection('startedAt'),
    completedAt: getSortDirection('completedAt'),
}));

function handleStatusSelect(status: DownloadQueueFilterStatus): void {
    selectedStatus.value = status;
}

function handleVisibleItemsChange(items: DownloadQueueItem[]): void {
    setVisibleItems(items);
}

function handleRowSelect(item: DownloadQueueItem, event: MouseEvent): void {
    handleSelection(item.id, event);
}

function handleRowToggleSelection(item: DownloadQueueItem): void {
    toggleItemSelection(item.id);
}

function handleRemoveSelection(): void {
    openRemoveDialog('selection', selectedIdsList.value);
}

function handleRemoveFiltered(): void {
    openRemoveDialog('all', filteredIds.value);
}

watch(selectedStatus, () => {
    queueTableRef.value?.resetScroll();
    cancelActiveRequest();
    scheduleVisibleDetailsFetch();
});

watch([sortKey, sortDirection], () => {
    cancelActiveRequest();
    scheduleVisibleDetailsFetch();
});
</script>

<template>
    <PageLayout>
        <div class="flex h-full w-full min-h-0 flex-col">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">
                        Downloads Queue
                    </h4>
                    <p class="text-blue-slate-300">
                        Manage queued downloads.
                    </p>
                </div>
            </div>

            <DownloadsQueueToolbar
                :filters="DOWNLOAD_QUEUE_FILTERS"
                :selected-status="selectedStatus"
                :downloads-count="downloads.length"
                :filtered-count="filteredItems.length"
                :status-counts="statusCounts"
                :selected-count="selectedCount"
                :selected-in-filter-count="selectedInFilterCount"
                :failed-count="failedIds.length"
                :completed-count="completedIds.length"
                :batch-is-pausing="batchIsPausing"
                :batch-is-canceling="batchIsCanceling"
                :batch-is-retrying-failed="batchIsRetryingFailed"
                :remove-is-deleting="removeIsDeleting"
                @select-status="handleStatusSelect"
                @retry-failed="retryFailedDownloads"
                @remove-completed="removeCompletedDownloads"
                @pause-selection="pauseSelection"
                @cancel-selection="cancelSelection"
                @remove-selection="handleRemoveSelection"
                @remove-filtered="handleRemoveFiltered"
            />

            <DownloadsQueueTable
                ref="queueTableRef"
                :items="sortedItems"
                :details-by-id="detailsById"
                :is-initial-loading="isInitialLoading"
                :item-height="ITEM_HEIGHT"
                :selected-ids="selectedIds"
                :last-selected-id="lastSelectedId"
                :action-busy="actionBusy"
                :all-filtered-selected="allFilteredSelected"
                :some-filtered-selected="someFilteredSelected"
                :sort-directions="sortDirections"
                @sort="toggleSort"
                @toggle-select-all="toggleSelectAll"
                @visible-items-change="handleVisibleItemsChange"
                @scroll="handleVirtualListScroll"
                @row-select="handleRowSelect"
                @row-toggle-selection="handleRowToggleSelection"
                @pause="pauseDownload"
                @resume="resumeDownload"
                @cancel="cancelDownload"
                @restart="restartDownload"
                @delete="deleteDownload"
            />
        </div>

        <DownloadsQueueRemoveDialog
            :open="removeDialogOpen"
            :title="removeTitle"
            :description="removeDescription"
            :is-deleting="removeIsDeleting"
            :also-from-disk="removeAlsoFromDisk"
            @update:open="removeDialogOpen = $event"
            @update:also-from-disk="removeAlsoFromDisk = $event"
            @confirm="confirmRemove"
        />
    </PageLayout>
</template>
