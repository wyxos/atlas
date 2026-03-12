import { computed, ref, type ComputedRef, type Ref } from 'vue';
import downloadTransfers from '@/routes/api/download-transfers';
import type {
    DownloadQueueItem,
    DownloadQueueRemoveMode,
} from '@/types/downloadQueue';
import {
    canCancelDownloadQueueItem,
    canPauseDownloadQueueItem,
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
} from '@/utils/downloadQueue';

export function useDownloadsQueueActions(params: {
    selectedIds: Ref<Set<number>>;
    selectedIdsList: ComputedRef<number[]>;
    failedIds: ComputedRef<number[]>;
    completedIds: ComputedRef<number[]>;
    removeDownloads: (ids: number[]) => void;
    setSelection: (ids: Set<number>) => void;
}) {
    const actionBusy = ref<Record<number, boolean>>({});
    const batchIsPausing = ref(false);
    const batchIsCanceling = ref(false);
    const batchIsRetryingFailed = ref(false);
    const removeDialogOpen = ref(false);
    const removeTargetIds = ref<number[]>([]);
    const removeIsDeleting = ref(false);
    const removeMode = ref<DownloadQueueRemoveMode>(null);
    const removeAlsoFromDisk = ref(false);

    const removeCount = computed(() => removeTargetIds.value.length);
    const removeTitle = computed(() => (removeMode.value === 'single' ? 'Remove download' : 'Remove downloads'));
    const removeLabel = computed(() => {
        if (removeMode.value === 'single') return 'this download';
        if (removeCount.value === 1) return '1 download';
        return `${removeCount.value} downloads`;
    });
    const removeDescription = computed(
        () => `Are you sure you want to remove ${removeLabel.value}? This action cannot be undone.`,
    );

    function setActionBusy(id: number, value: boolean): void {
        actionBusy.value = { ...actionBusy.value, [id]: value };
    }

    function isActionBusy(id: number): boolean {
        return actionBusy.value[id] ?? false;
    }

    function openRemoveDialog(mode: NonNullable<DownloadQueueRemoveMode>, ids: number[]): void {
        if (!ids.length) {
            return;
        }

        removeMode.value = mode;
        removeTargetIds.value = ids;
        removeAlsoFromDisk.value = false;
        removeDialogOpen.value = true;
    }

    function clearRemoveDialog(): void {
        removeIsDeleting.value = false;
        removeDialogOpen.value = false;
        removeTargetIds.value = [];
        removeMode.value = null;
        removeAlsoFromDisk.value = false;
    }

    async function confirmRemove(): Promise<void> {
        if (removeIsDeleting.value) {
            return;
        }

        removeIsDeleting.value = true;
        const ids = removeTargetIds.value;

        try {
            if (removeMode.value === 'completed') {
                await window.axios.post(downloadTransfers.destroyCompleted.url(), {
                    also_from_disk: removeAlsoFromDisk.value,
                });
            } else if (removeAlsoFromDisk.value) {
                await Promise.all(ids.map((id) =>
                    window.axios.delete(downloadTransfers.destroyDisk.url(id)),
                ));
            } else if (ids.length === 1) {
                await window.axios.delete(downloadTransfers.destroy.url(ids[0]));
            } else {
                await window.axios.post(downloadTransfers.destroyBatch.url(), { ids });
            }

            params.removeDownloads(ids);
            const nextSelection = new Set(params.selectedIds.value);
            ids.forEach((id) => nextSelection.delete(id));
            params.setSelection(nextSelection);
        } finally {
            clearRemoveDialog();
        }
    }

    async function pauseSelection(): Promise<void> {
        if (batchIsPausing.value) {
            return;
        }

        const ids = params.selectedIdsList.value;

        if (!ids.length) {
            return;
        }

        batchIsPausing.value = true;

        try {
            await window.axios.post(downloadTransfers.pauseBatch.url(), { ids });
        } finally {
            batchIsPausing.value = false;
        }
    }

    async function cancelSelection(): Promise<void> {
        if (batchIsCanceling.value) {
            return;
        }

        const ids = params.selectedIdsList.value;

        if (!ids.length) {
            return;
        }

        batchIsCanceling.value = true;

        try {
            await window.axios.post(downloadTransfers.cancelBatch.url(), { ids });
        } finally {
            batchIsCanceling.value = false;
        }
    }

    async function retryFailedDownloads(): Promise<void> {
        if (batchIsRetryingFailed.value) {
            return;
        }

        const ids = params.failedIds.value;

        if (!ids.length) {
            return;
        }

        batchIsRetryingFailed.value = true;

        try {
            await Promise.allSettled(
                ids.map((id) => window.axios.post(downloadTransfers.restart.url(id))),
            );
        } finally {
            batchIsRetryingFailed.value = false;
        }
    }

    function removeCompletedDownloads(): void {
        openRemoveDialog('completed', params.completedIds.value);
    }

    async function pauseDownload(item: DownloadQueueItem): Promise<void> {
        if (!canPauseDownloadQueueItem(item) || isActionBusy(item.id)) {
            return;
        }

        setActionBusy(item.id, true);

        try {
            await window.axios.post(downloadTransfers.pause.url(item.id));
        } finally {
            setActionBusy(item.id, false);
        }
    }

    async function resumeDownload(item: DownloadQueueItem): Promise<void> {
        if (!canResumeDownloadQueueItem(item) || isActionBusy(item.id)) {
            return;
        }

        setActionBusy(item.id, true);

        try {
            await window.axios.post(downloadTransfers.resume.url(item.id));
        } finally {
            setActionBusy(item.id, false);
        }
    }

    async function cancelDownload(item: DownloadQueueItem): Promise<void> {
        if (!canCancelDownloadQueueItem(item) || isActionBusy(item.id)) {
            return;
        }

        setActionBusy(item.id, true);

        try {
            await window.axios.post(downloadTransfers.cancel.url(item.id));
        } finally {
            setActionBusy(item.id, false);
        }
    }

    async function restartDownload(item: DownloadQueueItem): Promise<void> {
        if (!canRestartDownloadQueueItem(item) || isActionBusy(item.id)) {
            return;
        }

        setActionBusy(item.id, true);

        try {
            await window.axios.post(downloadTransfers.restart.url(item.id));
        } finally {
            setActionBusy(item.id, false);
        }
    }

    function deleteDownload(item: DownloadQueueItem): void {
        openRemoveDialog('single', [item.id]);
    }

    return {
        actionBusy,
        batchIsPausing,
        batchIsCanceling,
        batchIsRetryingFailed,
        removeDialogOpen,
        removeTargetIds,
        removeIsDeleting,
        removeMode,
        removeAlsoFromDisk,
        removeCount,
        removeTitle,
        removeLabel,
        removeDescription,
        isActionBusy,
        openRemoveDialog,
        clearRemoveDialog,
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
    };
}
