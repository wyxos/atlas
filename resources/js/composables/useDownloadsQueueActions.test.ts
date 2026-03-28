import { computed, ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useDownloadsQueueActions } from './useDownloadsQueueActions';

const mockToast = {
    info: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
    dismiss: vi.fn(),
};

vi.mock('@/components/ui/toast/use-toast', () => ({
    useToast: () => mockToast,
}));

describe('useDownloadsQueueActions', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        window.axios = {
            get: vi.fn(),
            post: vi.fn(),
            delete: vi.fn(),
        } as typeof window.axios;
    });

    it('uses one bulk request when removing multiple downloads from disk', async () => {
        window.axios.post = vi.fn().mockResolvedValue({
            data: {
                ids: [1, 2],
                count: 2,
                queued: false,
            },
        });

        const selectedIds = ref(new Set<number>([1, 2]));
        const removeDownloads = vi.fn();
        const setSelection = vi.fn();
        const actions = useDownloadsQueueActions({
            selectedIds,
            selectedIdsList: computed(() => [1, 2]),
            selectedPausableIds: computed(() => []),
            selectedResumableIds: computed(() => []),
            selectedCancelableIds: computed(() => []),
            selectedRestartableIds: computed(() => []),
            resumableFailedIds: computed(() => []),
            restartableFailedIds: computed(() => []),
            completedIds: computed(() => []),
            removeDownloads,
            setSelection,
        });

        actions.openRemoveDialog('all', [1, 2]);
        actions.removeAlsoFromDisk.value = true;

        await actions.confirmRemove();

        expect(window.axios.post).toHaveBeenCalledTimes(1);
        expect(window.axios.post).toHaveBeenCalledWith('/api/download-transfers/bulk-delete', {
            ids: [1, 2],
            also_from_disk: true,
            also_delete_record: false,
        });
        expect(window.axios.delete).not.toHaveBeenCalled();
        expect(removeDownloads).toHaveBeenCalledWith([1, 2]);
        expect(setSelection).toHaveBeenCalledWith(new Set<number>());
    });

    it('includes the file-record deletion flag when requested for bulk delete', async () => {
        window.axios.post = vi.fn().mockResolvedValue({
            data: {
                ids: [1, 2],
                count: 2,
                queued: false,
            },
        });

        const selectedIds = ref(new Set<number>([1, 2]));
        const actions = useDownloadsQueueActions({
            selectedIds,
            selectedIdsList: computed(() => [1, 2]),
            selectedPausableIds: computed(() => []),
            selectedResumableIds: computed(() => []),
            selectedCancelableIds: computed(() => []),
            selectedRestartableIds: computed(() => []),
            resumableFailedIds: computed(() => []),
            restartableFailedIds: computed(() => []),
            completedIds: computed(() => []),
            removeDownloads: vi.fn(),
            setSelection: vi.fn(),
        });

        actions.openRemoveDialog('all', [1, 2]);
        actions.removeAlsoFromDisk.value = true;
        actions.removeAlsoDeleteRecord.value = true;

        await actions.confirmRemove();

        expect(window.axios.post).toHaveBeenCalledWith('/api/download-transfers/bulk-delete', {
            ids: [1, 2],
            also_from_disk: true,
            also_delete_record: true,
        });
    });

    it('waits for reverb updates when bulk removal is queued', async () => {
        window.axios.post = vi.fn().mockResolvedValue({
            data: {
                count: 2,
                queued: true,
            },
        });

        const selectedIds = ref(new Set<number>([1, 2]));
        const removeDownloads = vi.fn();
        const setSelection = vi.fn();
        const actions = useDownloadsQueueActions({
            selectedIds,
            selectedIdsList: computed(() => [1, 2]),
            selectedPausableIds: computed(() => []),
            selectedResumableIds: computed(() => []),
            selectedCancelableIds: computed(() => []),
            selectedRestartableIds: computed(() => []),
            resumableFailedIds: computed(() => []),
            restartableFailedIds: computed(() => []),
            completedIds: computed(() => []),
            removeDownloads,
            setSelection,
        });

        actions.openRemoveDialog('all', [1, 2]);

        await actions.confirmRemove();

        expect(removeDownloads).not.toHaveBeenCalled();
        expect(mockToast.info).toHaveBeenCalledWith(
            'Removing downloads in the background. Items will disappear as cleanup completes.',
            { id: 'downloads-removal-queued' },
        );
    });

    it('uses server-returned ids for single delete-from-disk responses', async () => {
        window.axios.delete = vi.fn().mockResolvedValue({
            data: {
                ids: [1, 2],
                count: 2,
                queued: false,
            },
        });

        const selectedIds = ref(new Set<number>([1, 2]));
        const removeDownloads = vi.fn();
        const setSelection = vi.fn();
        const actions = useDownloadsQueueActions({
            selectedIds,
            selectedIdsList: computed(() => [1, 2]),
            selectedPausableIds: computed(() => []),
            selectedResumableIds: computed(() => []),
            selectedCancelableIds: computed(() => []),
            selectedRestartableIds: computed(() => []),
            resumableFailedIds: computed(() => []),
            restartableFailedIds: computed(() => []),
            completedIds: computed(() => []),
            removeDownloads,
            setSelection,
        });

        actions.openRemoveDialog('single', [1]);
        actions.removeAlsoFromDisk.value = true;

        await actions.confirmRemove();

        expect(window.axios.delete).toHaveBeenCalledWith('/api/download-transfers/1/disk', {
            data: {
                also_delete_record: false,
            },
        });
        expect(removeDownloads).toHaveBeenCalledWith([1, 2]);
        expect(setSelection).toHaveBeenCalledWith(new Set<number>());
    });

    it('resets file-record deletion when disk deletion is turned off', async () => {
        const selectedIds = ref(new Set<number>([1]));
        const actions = useDownloadsQueueActions({
            selectedIds,
            selectedIdsList: computed(() => [1]),
            selectedPausableIds: computed(() => []),
            selectedResumableIds: computed(() => []),
            selectedCancelableIds: computed(() => []),
            selectedRestartableIds: computed(() => []),
            resumableFailedIds: computed(() => []),
            restartableFailedIds: computed(() => []),
            completedIds: computed(() => []),
            removeDownloads: vi.fn(),
            setSelection: vi.fn(),
        });

        actions.openRemoveDialog('single', [1]);
        actions.removeAlsoFromDisk.value = true;
        actions.removeAlsoDeleteRecord.value = true;
        actions.removeAlsoFromDisk.value = false;
        expect(actions.removeAlsoDeleteRecord.value).toBe(false);
    });
});
