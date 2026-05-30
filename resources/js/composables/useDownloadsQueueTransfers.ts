import { onBeforeUnmount, onMounted, ref } from 'vue';
import downloadTransfers from '@/routes/api/download-transfers/index';
import type {
    DownloadQueueDetails,
    DownloadQueueIndexResponse,
    DownloadQueueItem,
    DownloadQueueProgressPayload,
    DownloadQueueQueuedPayload,
    DownloadQueueRemovedPayload,
} from '@/types/downloadQueue';
import { normalizeDownloadQueueProgress } from '@/utils/downloadQueue';

const PER_PAGE = 100;
const SCROLL_IDLE_MS = 180;
const SOCKET_CHANNEL = 'downloads';

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => void;
};

function incomingNullable<T>(value: T | null | undefined, fallback: T | null | undefined): T | null {
    return value === undefined ? (fallback ?? null) : (value ?? null);
}

function mergeDetails(
    current: DownloadQueueDetails | undefined,
    incoming: Partial<DownloadQueueDetails>,
): DownloadQueueDetails {
    return {
        path: incoming.path ?? current?.path ?? null,
        absolute_path: incoming.absolute_path ?? current?.absolute_path ?? null,
        original: incoming.original ?? current?.original ?? null,
        referrer_url: incoming.referrer_url ?? current?.referrer_url ?? null,
        preview: incoming.preview ?? current?.preview ?? null,
        size: incoming.size ?? current?.size ?? null,
        filename: incoming.filename ?? current?.filename ?? null,
    };
}

export function useDownloadsQueueTransfers() {
    const downloads = ref<DownloadQueueItem[]>([]);
    const detailsById = ref<Record<number, DownloadQueueDetails>>({});
    const isInitialLoading = ref(true);
    const loadError = ref<string | null>(null);
    const loadedPages = ref(0);
    const totalPages = ref(0);
    const totalDownloads = ref(0);
    const visibleItems = ref<DownloadQueueItem[]>([]);

    let activeListRequestToken = 0;
    let activeRequestToken = 0;
    let idleTimeout: ReturnType<typeof setTimeout> | null = null;
    let detailsAbortController: AbortController | null = null;
    let echoChannel: EchoChannel | null = null;

    function updateDownload(id: number, updater: (item: DownloadQueueItem) => DownloadQueueItem): void {
        const index = downloads.value.findIndex((item) => item.id === id);

        if (index === -1) {
            return;
        }

        const next = downloads.value.slice();
        next[index] = updater(next[index]);
        downloads.value = next;
    }

    function upsertDownload(item: DownloadQueueItem): void {
        const index = downloads.value.findIndex((row) => row.id === item.id);

        if (index === -1) {
            downloads.value = [item, ...downloads.value];
            totalDownloads.value += 1;
            return;
        }

        const next = downloads.value.slice();
        next[index] = { ...next[index], ...item };
        downloads.value = next;
    }

    function removeDownloads(ids: number[]): void {
        if (!ids.length) {
            return;
        }

        const removedIds = new Set(ids);
        const nextDownloads = downloads.value.filter((item) => !removedIds.has(item.id));
        const removedCount = downloads.value.length - nextDownloads.length;

        downloads.value = nextDownloads;
        totalDownloads.value = Math.max(0, totalDownloads.value - removedCount);
        visibleItems.value = visibleItems.value.filter((item) => !removedIds.has(item.id));
        detailsById.value = Object.fromEntries(
            Object.entries(detailsById.value).filter(([key]) => !removedIds.has(Number(key))),
        );
    }

    function applyQueuedPayload(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const value = payload as DownloadQueueQueuedPayload;
        const id = value.id ?? value.downloadTransferId;

        if (!id) {
            return;
        }

        const existing = downloads.value.find((item) => item.id === id);
        const item: DownloadQueueItem = {
            id,
            status: value.status,
            created_at: incomingNullable(value.created_at, existing?.created_at),
            queued_at: incomingNullable(value.queued_at, existing?.queued_at),
            started_at: incomingNullable(value.started_at, existing?.started_at),
            finished_at: incomingNullable(value.finished_at, existing?.finished_at),
            failed_at: incomingNullable(value.failed_at, existing?.failed_at),
            percent: value.percent ?? 0,
            error: incomingNullable(value.error, existing?.error),
            download_via: incomingNullable(value.download_via, existing?.download_via),
            referrer_url: incomingNullable(value.referrer_url, existing?.referrer_url),
            search_text: incomingNullable(value.search_text, existing?.search_text),
            can_resume: value.can_resume ?? existing?.can_resume ?? false,
            can_restart: value.can_restart ?? existing?.can_restart ?? false,
        };

        upsertDownload(item);
        detailsById.value = {
            ...detailsById.value,
            [id]: mergeDetails(detailsById.value[id], value),
        };
    }

    function applyProgressPayload(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const value = payload as DownloadQueueProgressPayload;
        const id = value.downloadTransferId;

        if (!id) {
            return;
        }

        updateDownload(id, (current) => ({
            ...current,
            status: value.status,
            percent: normalizeDownloadQueueProgress(value.percent ?? current.percent ?? 0),
            created_at: incomingNullable(value.created_at, current.created_at),
            queued_at: incomingNullable(value.queued_at, current.queued_at),
            started_at: incomingNullable(value.started_at, current.started_at),
            finished_at: incomingNullable(value.finished_at, current.finished_at),
            failed_at: incomingNullable(value.failed_at, current.failed_at),
            error: incomingNullable(value.error, current.error),
            download_via: incomingNullable(value.download_via, current.download_via),
            referrer_url: incomingNullable(value.referrer_url, current.referrer_url),
            search_text: incomingNullable(value.search_text, current.search_text),
            can_resume: value.can_resume ?? current.can_resume ?? false,
            can_restart: value.can_restart ?? current.can_restart ?? false,
        }));

        if (
            value.path === undefined
            && value.absolute_path === undefined
            && value.original === undefined
            && value.referrer_url === undefined
            && value.preview === undefined
            && value.size === undefined
            && value.filename === undefined
        ) {
            return;
        }

        detailsById.value = {
            ...detailsById.value,
            [id]: mergeDetails(detailsById.value[id], value),
        };
    }

    function applyRemovedPayload(payload: unknown): void {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const value = payload as DownloadQueueRemovedPayload;
        if (!Array.isArray(value.ids) || value.ids.length === 0) {
            return;
        }

        removeDownloads(value.ids);
    }

    function startEchoListeners(): void {
        const echo = window.Echo as undefined | {
            private: (channel: string) => EchoChannel;
        };

        if (!echo) {
            return;
        }

        echoChannel = echo.private(SOCKET_CHANNEL);
        echoChannel.listen('.DownloadTransferCreated', applyQueuedPayload);
        echoChannel.listen('.DownloadTransferQueued', applyQueuedPayload);
        echoChannel.listen('.DownloadTransferProgressUpdated', applyProgressPayload);
        echoChannel.listen('.DownloadTransfersRemoved', applyRemovedPayload);
    }

    function stopEchoListeners(): void {
        const echo = window.Echo as undefined | {
            leave: (channel: string) => void;
        };

        if (!echo) {
            return;
        }

        echo.leave(SOCKET_CHANNEL);
        echoChannel = null;
    }

    function cancelActiveRequest(): void {
        if (detailsAbortController) {
            detailsAbortController.abort();
            detailsAbortController = null;
        }

        activeRequestToken += 1;
    }

    async function fetchVisibleDetails(): Promise<void> {
        const itemsToFetch = visibleItems.value;

        if (!itemsToFetch.length) {
            return;
        }

        cancelActiveRequest();
        const requestToken = activeRequestToken;
        const controller = new AbortController();
        detailsAbortController = controller;

        try {
            const { data } = await window.axios.post<{
                items: Array<DownloadQueueDetails & { id: number }>;
            }>(downloadTransfers.details.url(), {
                ids: itemsToFetch.map((item) => item.id),
            }, {
                signal: controller.signal,
            });

            if (requestToken !== activeRequestToken) {
                return;
            }

            detailsById.value = data.items.reduce((acc, item) => {
                acc[item.id] = mergeDetails(acc[item.id], item);
                return acc;
            }, { ...detailsById.value } as Record<number, DownloadQueueDetails>);
        } catch {
            if (controller.signal.aborted) {
                return;
            }
        } finally {
            if (detailsAbortController === controller) {
                detailsAbortController = null;
            }
        }
    }

    function scheduleVisibleDetailsFetch(): void {
        if (idleTimeout) {
            clearTimeout(idleTimeout);
        }

        idleTimeout = setTimeout(() => {
            idleTimeout = null;
            void fetchVisibleDetails();
        }, SCROLL_IDLE_MS);
    }

    async function fetchDownloadsPage(afterId: number, maxId: number | null): Promise<DownloadQueueIndexResponse> {
        const params = {
            after_id: afterId,
            per_page: PER_PAGE,
            ...(maxId !== null ? { max_id: maxId } : {}),
        };
        const { data } = await window.axios.get<DownloadQueueIndexResponse>(downloadTransfers.index.url(), {
            params,
        });

        return data;
    }

    function setVisibleItems(items: DownloadQueueItem[]): void {
        visibleItems.value = items;
        scheduleVisibleDetailsFetch();
    }

    function handleVirtualListScroll(): void {
        cancelActiveRequest();
        scheduleVisibleDetailsFetch();
    }

    async function loadDownloads(): Promise<void> {
        const requestToken = ++activeListRequestToken;

        isInitialLoading.value = true;
        loadError.value = null;
        loadedPages.value = 0;
        totalPages.value = 0;
        totalDownloads.value = 0;
        downloads.value = [];
        detailsById.value = {};
        visibleItems.value = [];
        cancelActiveRequest();

        try {
            let afterId = 0;
            let maxId: number | null = null;
            let hasMore = true;

            while (hasMore) {
                const nextPage = await fetchDownloadsPage(afterId, maxId);
                if (requestToken !== activeListRequestToken) {
                    return;
                }

                downloads.value = [
                    ...downloads.value,
                    ...(Array.isArray(nextPage.items) ? nextPage.items : []),
                ];

                if (!nextPage.cursor || !nextPage.pagination) {
                    totalDownloads.value = downloads.value.length;
                    totalPages.value = downloads.value.length > 0 ? 1 : 0;
                    loadedPages.value = totalPages.value;
                    hasMore = false;
                    continue;
                }

                if (maxId === null) {
                    maxId = nextPage.cursor.max_id;
                    totalDownloads.value = nextPage.pagination.total ?? 0;
                    totalPages.value = nextPage.pagination.total_pages ?? (totalDownloads.value > 0 ? 1 : 0);
                }

                if (totalPages.value > 0) {
                    loadedPages.value = Math.min(totalPages.value, loadedPages.value + 1);
                }

                afterId = nextPage.cursor.next_after_id ?? afterId;
                hasMore = nextPage.cursor.has_more && nextPage.cursor.next_after_id !== null;
            }
        } catch (error) {
            console.error('Failed to load downloads:', error);
            loadError.value = 'Failed to load downloads.';
        } finally {
            if (requestToken === activeListRequestToken) {
                isInitialLoading.value = false;
                scheduleVisibleDetailsFetch();
            }
        }
    }

    onMounted(async () => {
        await loadDownloads();
        startEchoListeners();
    });

    onBeforeUnmount(() => {
        activeListRequestToken += 1;

        if (idleTimeout) {
            clearTimeout(idleTimeout);
        }

        cancelActiveRequest();
        stopEchoListeners();
    });

    return {
        downloads,
        detailsById,
        isInitialLoading,
        loadError,
        loadedPages,
        totalPages,
        totalDownloads,
        visibleItems,
        loadDownloads,
        removeDownloads,
        cancelActiveRequest,
        scheduleVisibleDetailsFetch,
        setVisibleItems,
        handleVirtualListScroll,
    };
}
