import { onMounted, onUnmounted, triggerRef, type Ref, type ShallowRef } from 'vue';
import type { MasonryInstance } from '@wyxos/vibe';
import type { FeedItem, TabData } from './useTabs';

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => void;
};

type FileMarkedNotFoundPayload = {
    fileId?: number;
    tabIds?: unknown;
};

type PreviewFailureResponse = {
    fileId?: number;
    tabIds?: unknown;
};

type UseTabContentNotFoundReconciliationOptions = {
    items: ShallowRef<FeedItem[]>;
    tab: Ref<TabData | null>;
    masonry: Ref<MasonryInstance | null>;
    hoveredItemId: Ref<number | null>;
    cancelAutoDislikeCountdown: (fileId: number) => void;
    clearHover: () => void;
};

type MasonryRemoveTarget = Parameters<MasonryInstance['remove']>[0];

const NOT_FOUND_REMOVAL_DELAY_MS = 5000;

export function useTabContentNotFoundReconciliation(options: UseTabContentNotFoundReconciliationOptions) {
    const previewFailureReportInFlightIds = new Set<number>();
    const notFoundRemovalTimers = new Map<number, number>();

    let echoChannelName: string | null = null;

    function isCivitAiMediaUrl(value: unknown): boolean {
        if (typeof value !== 'string' || value.trim() === '') {
            return false;
        }

        try {
            return new URL(value, window.location.origin).hostname.toLowerCase() === 'image.civitai.com';
        } catch {
            return false;
        }
    }

    function resolveCurrentUserId(): number | null {
        const content = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
        const parsed = Number(content);

        return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
    }

    function shouldReportPreviewFailure(item: FeedItem): boolean {
        if (typeof item.id !== 'number' || item.notFound) {
            return false;
        }

        return isCivitAiMediaUrl(item.preview ?? item.src)
            || isCivitAiMediaUrl(item.original)
            || isCivitAiMediaUrl(item.url);
    }

    function clearNotFoundRemovalTimers(): void {
        for (const timerId of notFoundRemovalTimers.values()) {
            clearTimeout(timerId);
        }

        notFoundRemovalTimers.clear();
    }

    function scheduleNotFoundRemoval(fileId: number): void {
        const existingTimerId = notFoundRemovalTimers.get(fileId);

        if (existingTimerId !== undefined) {
            clearTimeout(existingTimerId);
        }

        const timerId = window.setTimeout(() => {
            notFoundRemovalTimers.delete(fileId);

            const item = options.items.value.find((candidate) => candidate.id === fileId);
            if (!item) {
                return;
            }

            if (options.hoveredItemId.value === fileId) {
                options.clearHover();
            }

            if (options.masonry.value) {
                void Promise.resolve(
                    options.masonry.value.remove(item as unknown as MasonryRemoveTarget)
                ).catch(() => {
                    options.items.value = options.items.value.filter((candidate) => candidate.id !== fileId);
                });
            } else {
                options.items.value = options.items.value.filter((candidate) => candidate.id !== fileId);
            }
        }, NOT_FOUND_REMOVAL_DELAY_MS);

        notFoundRemovalTimers.set(fileId, timerId);
    }

    function normalizeTabIds(value: unknown): number[] {
        if (!Array.isArray(value)) {
            return [];
        }

        return value
            .map((tabId) => Number(tabId))
            .filter((tabId) => Number.isInteger(tabId) && tabId > 0);
    }

    function reconcileCurrentTabNotFound(fileId: number, tabIds: number[]): void {
        const currentTabId = options.tab.value?.id ?? null;

        if (
            currentTabId === null
            || !tabIds.includes(currentTabId)
        ) {
            return;
        }

        const item = options.items.value.find((candidate) => candidate.id === fileId);
        if (!item) {
            return;
        }

        options.cancelAutoDislikeCountdown(fileId);
        item.notFound = true;
        item.will_auto_dislike = false;
        triggerRef(options.items);
        scheduleNotFoundRemoval(fileId);
    }

    function handleFileMarkedNotFound(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const { fileId, tabIds } = payload as FileMarkedNotFoundPayload;
        if (typeof fileId !== 'number') {
            return;
        }

        reconcileCurrentTabNotFound(fileId, normalizeTabIds(tabIds));
    }

    function handlePreviewFailureResponse(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const { fileId, tabIds } = payload as PreviewFailureResponse;
        if (typeof fileId !== 'number') {
            return;
        }

        reconcileCurrentTabNotFound(fileId, normalizeTabIds(tabIds));
    }

    function reportItem(item: FeedItem): void {
        if (!shouldReportPreviewFailure(item) || previewFailureReportInFlightIds.has(item.id)) {
            return;
        }

        previewFailureReportInFlightIds.add(item.id);

        void window.axios.post(`/api/files/${item.id}/preview-failure`)
            .then(({ data }) => {
                handlePreviewFailureResponse(data);
            })
            .catch(() => {})
            .finally(() => {
                previewFailureReportInFlightIds.delete(item.id);
            });
    }

    function startEchoListeners(): void {
        const userId = resolveCurrentUserId();
        const echo = window.Echo as undefined | {
            private: (channel: string) => EchoChannel;
        };

        if (!echo || userId === null) {
            return;
        }

        echoChannelName = `App.Models.User.${userId}`;
        echo.private(echoChannelName).listen('.FileMarkedNotFound', handleFileMarkedNotFound);
    }

    function stopEchoListeners(): void {
        if (!echoChannelName) {
            return;
        }

        const echo = window.Echo as undefined | {
            leave: (channel: string) => void;
        };

        if (echo) {
            echo.leave(echoChannelName);
        }

        echoChannelName = null;
    }

    function onBatchFailures(payloads: Array<{ item: FeedItem; error: unknown }>): void {
        for (const { item } of payloads) {
            reportItem(item);
        }
    }

    onMounted(() => {
        startEchoListeners();
    });

    onUnmounted(() => {
        clearNotFoundRemovalTimers();
        stopEchoListeners();
    });

    return {
        onBatchFailures,
        reportItem,
    };
}
