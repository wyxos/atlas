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
const searchQuery = ref('');

const transferState = useDownloadsQueueTransfers();
const {
    downloads,
    detailsById,
    isInitialLoading,
    loadError,
    loadedPages,
    totalPages,
    totalDownloads,
    removeDownloads,
    cancelActiveRequest,
    scheduleVisibleDetailsFetch,
    setVisibleItems,
    handleVirtualListScroll,
} = transferState;

const tableState = useDownloadsQueueTableState({
    downloads,
    searchQuery,
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
    handleSelection,
    toggleItemSelection,
    toggleSelectAll,
    sortedItems,
} = tableState;

const actionState = useDownloadsQueueActions({
    selectedIds,
    selectedIdsList,
    selectedPausableIds,
    selectedResumableIds,
    selectedCancelableIds,
    selectedRestartableIds,
    resumableFailedIds,
    restartableFailedIds,
    completedIds,
    removeDownloads,
    setSelection,
});
const {
    actionBusy,
    batchIsPausing,
    batchIsResuming,
    batchIsCanceling,
    batchIsRestarting,
    batchIsResumingFailed,
    batchIsRestartingFailed,
    removeDialogOpen,
    removeIsDeleting,
    removeTitle,
    removeDescription,
    removeAlsoFromDisk,
    removeAlsoDeleteRecord,
    openRemoveDialog,
    confirmRemove,
    pauseSelection,
    resumeSelection,
    cancelSelection,
    restartSelection,
    resumeFailedDownloads,
    restartFailedDownloads,
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

const loadProgressPercent = computed(() => {
    if (totalPages.value === 0) {
        return 100;
    }

    return Math.round((loadedPages.value / totalPages.value) * 100);
});

const showLoadProgressPanel = computed(() =>
    isInitialLoading.value || (totalPages.value > 0 && loadedPages.value < totalPages.value),
);

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

watch([selectedStatus, searchQuery], () => {
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
                :search-query="searchQuery"
                :downloads-count="downloads.length"
                :filtered-count="filteredItems.length"
                :status-counts="statusCounts"
                :selected-count="selectedCount"
                :selected-in-filter-count="selectedInFilterCount"
                :selected-pausable-count="selectedPausableIds.length"
                :selected-resumable-count="selectedResumableIds.length"
                :selected-cancelable-count="selectedCancelableIds.length"
                :selected-restartable-count="selectedRestartableIds.length"
                :resumable-failed-count="resumableFailedIds.length"
                :restartable-failed-count="restartableFailedIds.length"
                :completed-count="completedIds.length"
                :batch-is-pausing="batchIsPausing"
                :batch-is-resuming="batchIsResuming"
                :batch-is-canceling="batchIsCanceling"
                :batch-is-restarting="batchIsRestarting"
                :batch-is-resuming-failed="batchIsResumingFailed"
                :batch-is-restarting-failed="batchIsRestartingFailed"
                :remove-is-deleting="removeIsDeleting"
                @select-status="handleStatusSelect"
                @update:search-query="searchQuery = $event"
                @resume-failed="resumeFailedDownloads"
                @restart-failed="restartFailedDownloads"
                @remove-completed="removeCompletedDownloads"
                @pause-selection="pauseSelection"
                @resume-selection="resumeSelection"
                @cancel-selection="cancelSelection"
                @restart-selection="restartSelection"
                @remove-selection="handleRemoveSelection"
                @remove-filtered="handleRemoveFiltered"
            />

            <div
                v-if="showLoadProgressPanel"
                class="mb-4 overflow-hidden rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700 p-4"
                data-test="downloads-progress-panel"
            >
                <div class="mb-2 flex items-center justify-between text-sm text-twilight-indigo-100">
                    <span>Pages: {{ loadedPages }} / {{ totalPages }}</span>
                    <span>{{ loadProgressPercent }}%</span>
                </div>
                <div class="h-2 w-full rounded-full bg-twilight-indigo-600">
                    <div
                        class="h-2 rounded-full bg-smart-blue-400 transition-[width] duration-200"
                        :style="{ width: `${loadProgressPercent}%` }"
                    />
                </div>
                <div class="mt-2 text-xs text-blue-slate-300">
                    Downloads loaded: {{ downloads.length }} / {{ totalDownloads }}
                    <span v-if="isInitialLoading" class="ml-2">Loading...</span>
                </div>
            </div>

            <div v-if="loadError" class="rounded-lg border border-danger-500 bg-prussian-blue-700 p-4 text-danger-200">
                {{ loadError }}
            </div>

            <DownloadsQueueTable
                v-else
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
            :also-delete-record="removeAlsoDeleteRecord"
            @update:open="removeDialogOpen = $event"
            @update:also-from-disk="removeAlsoFromDisk = $event"
            @update:also-delete-record="removeAlsoDeleteRecord = $event"
            @confirm="confirmRemove"
        />
    </PageLayout>
</template>
