import { ref, type Ref } from 'vue';
import { audioDetailFromResponseItem, emptyAudioDetail } from './useAudioDetailMapping';
import type { AudioDetail, AudioDetailsResponse } from '@/types/audio';

const SCROLL_IDLE_MS = 180;

export function useAudioVisibleDetails(isLoading: Ref<boolean>) {
    const visibleIds = ref<number[]>([]);
    const detailsById = ref<Record<number, AudioDetail>>({});
    let activeRequestToken = 0;
    let idleTimeout: ReturnType<typeof setTimeout> | null = null;
    let detailsAbortController: AbortController | null = null;

    async function fetchAudioDetails(ids: number[], force = false, signal?: AbortSignal): Promise<void> {
        const idsToFetch = Array.from(new Set(ids)).filter((id) => force || detailsById.value[id] === undefined);

        if (idsToFetch.length === 0) {
            return;
        }

        const requestOptions = signal ? { signal } : undefined;
        const { data } = await window.axios.post<AudioDetailsResponse>('/api/audio/details', {
            ids: idsToFetch,
        }, requestOptions);

        const nextDetails = { ...detailsById.value };
        const returnedIds = new Set<number>();

        for (const item of data.items) {
            returnedIds.add(item.id);
            nextDetails[item.id] = audioDetailFromResponseItem(item);
        }

        for (const id of idsToFetch) {
            if (!returnedIds.has(id)) {
                nextDetails[id] = emptyAudioDetail();
            }
        }

        detailsById.value = nextDetails;
    }

    async function fetchVisibleDetails(): Promise<void> {
        const ids = Array.from(new Set(visibleIds.value));
        const idsToFetch = ids.filter((id) => detailsById.value[id] === undefined);

        if (idsToFetch.length === 0) {
            return;
        }

        cancelActiveRequest();
        const requestToken = activeRequestToken;
        const controller = new AbortController();
        detailsAbortController = controller;

        try {
            await fetchAudioDetails(idsToFetch, false, controller.signal);

            if (requestToken !== activeRequestToken) {
                return;
            }
        } catch {
            if (!controller.signal.aborted) {
                return;
            }
        } finally {
            if (detailsAbortController === controller) {
                detailsAbortController = null;
            }
        }
    }

    function cancelActiveRequest(): void {
        if (detailsAbortController) {
            detailsAbortController.abort();
            detailsAbortController = null;
        }
        activeRequestToken += 1;
    }

    function queueFetchAfterIdle(): void {
        if (isLoading.value) {
            return;
        }

        if (idleTimeout) {
            clearTimeout(idleTimeout);
        }

        idleTimeout = setTimeout(() => {
            idleTimeout = null;
            void fetchVisibleDetails();
        }, SCROLL_IDLE_MS);
    }

    function handleVisibleItemsChange(items: unknown[]): void {
        visibleIds.value = items
            .map((item) => Number(item))
            .filter((item) => Number.isInteger(item) && item > 0);
        queueFetchAfterIdle();
    }

    function handleVirtualListScroll(): void {
        cancelActiveRequest();
        queueFetchAfterIdle();
    }

    function reset(): void {
        cancelActiveRequest();
        visibleIds.value = [];
        detailsById.value = {};
    }

    function dispose(): void {
        if (idleTimeout) {
            clearTimeout(idleTimeout);
        }
        cancelActiveRequest();
    }

    return {
        cancelActiveRequest,
        detailsById,
        disposeAudioDetails: dispose,
        fetchAudioDetails,
        handleVisibleItemsChange,
        handleVirtualListScroll,
        queueFetchAfterIdle,
        resetAudioDetails: reset,
    };
}
