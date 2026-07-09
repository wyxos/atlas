import { onMounted, onUnmounted, ref, watch, type ComputedRef } from 'vue';
import { show as getFile } from '@/actions/App/Http/Controllers/FilesController';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => EchoChannel;
};

type EchoClient = {
    private: (channel: string) => EchoChannel;
    leave: (channel: string) => void;
};

const CHANNEL = 'file-previews';

function shouldQueuePreviewRegeneration(item: FeedItem): boolean {
    const status = item.preview_generation?.status ?? null;
    const canRetry = item.preview_generation?.can_retry ?? false;

    return item.downloaded === true
        && !item.src
        && !item.preview
        && !item.thumbnail
        && canRetry === true
        && (status === 'failed' || status === 'missing');
}

function getPayloadFileId(payload: unknown): number | null {
    if (!payload || typeof payload !== 'object') {
        return null;
    }

    const value = (payload as { fileId?: unknown; file_id?: unknown }).fileId
        ?? (payload as { file_id?: unknown }).file_id;
    const parsed = Number(value);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

export function useFilePreviewRegeneration(args: {
    sessionItems: ComputedRef<FeedItem[]>;
    setFileData: (file: File) => void;
}) {
    const queuedFileIds = ref<Set<number>>(new Set());
    const autoRequestedFileIds = new Set<number>();

    function hasSessionItem(fileId: number): boolean {
        return args.sessionItems.value.some((item) => item.id === fileId);
    }

    function setQueued(fileId: number, queued: boolean): void {
        const next = new Set(queuedFileIds.value);
        if (queued) {
            next.add(fileId);
        } else {
            next.delete(fileId);
        }
        queuedFileIds.value = next;
    }

    async function refreshFile(fileId: number): Promise<void> {
        if (!hasSessionItem(fileId)) {
            return;
        }

        const { data } = await window.axios.get<{ file?: File }>(getFile.url(fileId));
        if (data.file && hasSessionItem(fileId)) {
            args.setFileData(data.file);
        }
    }

    async function queuePreviewRegeneration(item: FeedItem, options: { automatic?: boolean } = {}): Promise<void> {
        if (!shouldQueuePreviewRegeneration(item) || queuedFileIds.value.has(item.id)) {
            return;
        }

        if (options.automatic && autoRequestedFileIds.has(item.id)) {
            return;
        }

        if (options.automatic) {
            autoRequestedFileIds.add(item.id);
        }

        setQueued(item.id, true);

        try {
            const { data } = await window.axios.post<{
                queued?: boolean;
                action?: 'preview_queued' | 'redownload_queued' | 'unavailable';
                file?: File;
            }>(`/api/files/${item.id}/preview-assets`);
            if (data.file) {
                args.setFileData(data.file);
            }
        } catch (error) {
            item.preview_generation = {
                status: 'failed',
                can_retry: true,
                message: 'Could not queue preview regeneration.',
            };
            console.error('Failed to queue preview regeneration:', error);
        } finally {
            setQueued(item.id, false);
        }
    }

    function queueVisiblePreviewRegenerations(): void {
        for (const item of args.sessionItems.value) {
            if (shouldQueuePreviewRegeneration(item)) {
                void queuePreviewRegeneration(item, { automatic: true });
            }
        }
    }

    function startEchoListener(): void {
        const echo = window.Echo as EchoClient | undefined;
        if (!echo) {
            return;
        }

        echo.private(CHANNEL).listen('.FilePreviewAssetsUpdated', (payload: unknown) => {
            const fileId = getPayloadFileId(payload);
            if (fileId !== null) {
                void refreshFile(fileId);
            }
        });
    }

    function stopEchoListener(): void {
        const echo = window.Echo as EchoClient | undefined;
        echo?.leave(CHANNEL);
    }

    watch(
        () => args.sessionItems.value.map((item) => `${item.id}:${item.preview_generation?.status ?? ''}:${item.src ?? ''}`).join('|'),
        queueVisiblePreviewRegenerations,
        { immediate: true },
    );

    onMounted(startEchoListener);
    onUnmounted(stopEchoListener);

    return {
        isPreviewRegenerationQueued: (item: FeedItem): boolean => queuedFileIds.value.has(item.id),
        queuePreviewRegeneration,
    };
}
