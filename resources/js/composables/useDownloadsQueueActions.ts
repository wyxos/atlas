import { computed, ref, type ComputedRef, type Ref } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import downloadTransfers from '@/routes/api/download-transfers/index';
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
    selectedPausableIds: ComputedRef<number[]>;
    selectedResumableIds: ComputedRef<number[]>;
    selectedCancelableIds: ComputedRef<number[]>;
    selectedRestartableIds: ComputedRef<number[]>;
    resumableFailedIds: ComputedRef<number[]>;
    restartableFailedIds: ComputedRef<number[]>;
    completedIds: ComputedRef<number[]>;
    removeDownloads: (ids: number[]) => void;
    setSelection: (ids: Set<number>) => void;
}) {
    const toast = useToast();
    const actionBusy = ref<Record<number, boolean>>({});
    const batchIsPausing = ref(false);
    const batchIsResuming = ref(false);
    const batchIsCanceling = ref(false);
    const batchIsRestarting = ref(false);
    const batchIsResumingFailed = ref(false);
    const batchIsRestartingFailed = ref(false);
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

    type RemoveResponse = {
        ids?: number[];
        count?: number;
        queued?: boolean;
    };

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
            let removeNow = true;
            let removedIds = ids;

            if (removeMode.value === 'completed') {
                const { data } = await window.axios.post<RemoveResponse>(downloadTransfers.destroyCompleted.url(), {
                    also_from_disk: removeAlsoFromDisk.value,
                });
                removeNow = data.queued !== true;
                removedIds = data.ids ?? ids;
            } else if (ids.length === 1 && removeAlsoFromDisk.value) {
                const { data } = await window.axios.delete<RemoveResponse>(downloadTransfers.destroyDisk.url(ids[0]));
                removedIds = data.ids ?? ids;
            } else if (ids.length === 1) {
                const { data } = await window.axios.delete<RemoveResponse>(downloadTransfers.destroy.url(ids[0]));
                removedIds = data.ids ?? ids;
            } else {
                const { data } = await window.axios.post<RemoveResponse>(downloadTransfers.destroyBatch.url(), {
                    ids,
                    also_from_disk: removeAlsoFromDisk.value,
                });
                removeNow = data.queued !== true;
                removedIds = data.ids ?? ids;
            }

            const nextSelection = new Set(params.selectedIds.value);
            (removeNow ? removedIds : ids).forEach((id) => nextSelection.delete(id));
            params.setSelection(nextSelection);

            if (removeNow) {
                params.removeDownloads(removedIds);
            } else {
                toast.info('Removing downloads in the background. Items will disappear as cleanup completes.', {
                    id: 'downloads-removal-queued',
                });
            }
        } finally {
            clearRemoveDialog();
        }
    }

    async function postIndividually(ids: number[], makeRequest: (id: number) => Promise<unknown>): Promise<void> {
        await Promise.allSettled(ids.map((id) => makeRequest(id)));
    }

    async function pauseSelection(): Promise<void> {
        if (batchIsPausing.value) {
            return;
        }

        const ids = params.selectedPausableIds.value;

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

    async function resumeSelection(): Promise<void> {
        if (batchIsResuming.value) {
            return;
        }

        const ids = params.selectedResumableIds.value;

        if (!ids.length) {
            return;
        }

        batchIsResuming.value = true;

        try {
            await postIndividually(ids, (id) => window.axios.post(downloadTransfers.resume.url(id)));
        } finally {
            batchIsResuming.value = false;
        }
    }

    async function cancelSelection(): Promise<void> {
        if (batchIsCanceling.value) {
            return;
        }

        const ids = params.selectedCancelableIds.value;

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

    async function restartSelection(): Promise<void> {
        if (batchIsRestarting.value) {
            return;
        }

        const ids = params.selectedRestartableIds.value;

        if (!ids.length) {
            return;
        }

        batchIsRestarting.value = true;

        try {
            await postIndividually(ids, (id) => window.axios.post(downloadTransfers.restart.url(id)));
        } finally {
            batchIsRestarting.value = false;
        }
    }

    async function resumeFailedDownloads(): Promise<void> {
        if (batchIsResumingFailed.value) {
            return;
        }

        const ids = params.resumableFailedIds.value;

        if (!ids.length) {
            return;
        }

        batchIsResumingFailed.value = true;

        try {
            await postIndividually(ids, (id) => window.axios.post(downloadTransfers.resume.url(id)));
        } finally {
            batchIsResumingFailed.value = false;
        }
    }

    async function restartFailedDownloads(): Promise<void> {
        if (batchIsRestartingFailed.value) {
            return;
        }

        const ids = params.restartableFailedIds.value;

        if (!ids.length) {
            return;
        }

        batchIsRestartingFailed.value = true;

        try {
            await postIndividually(ids, (id) => window.axios.post(downloadTransfers.restart.url(id)));
        } finally {
            batchIsRestartingFailed.value = false;
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
        batchIsResuming,
        batchIsCanceling,
        batchIsRestarting,
        batchIsResumingFailed,
        batchIsRestartingFailed,
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
    };
}
