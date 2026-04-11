import type { Ref } from 'vue';
import type {
    VibeResolveParams,
    VibeResolveResult,
    VibeStatus,
    VibeViewerItem,
} from '@wyxos/vibe';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { FeedItem } from '@/composables/useTabs';
import type { BrowsePageToken } from '@/types/browse';
import { appendBrowseServiceFilters } from '@/utils/browseQuery';

export type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';
type VibeResolveParamsWithSignal = VibeResolveParams & { signal?: AbortSignal };

type TabContentV2ResolveArgs = {
    form: BrowseFormInstance;
    startPageToken: Ref<BrowsePageToken>;
    updateActiveTab: (items: FeedItem[]) => void;
    updateTabLabel?: (cursor: BrowsePageToken | string | number | null | undefined) => void;
    items: Ref<FeedItem[]>;
    itemsBuckets: Ref<Array<{
        cursor: string | null;
        items: FeedItem[];
        nextCursor: string | null;
        previousCursor: string | null;
    }>>;
    availableServices: Ref<ServiceOption[]>;
    filterItems?: (items: FeedItem[]) => FeedItem[];
    localService: Ref<ServiceOption | null | undefined>;
    toast: {
        error: (message: string) => void;
    };
};

export function createTabContentV2EmptyStatus(): VibeStatus {
    return {
        activeIndex: 0,
        currentCursor: null,
        errorMessage: null,
        fillCollectedCount: null,
        fillDelayRemainingMs: null,
        fillTargetCount: null,
        hasNextPage: false,
        hasPreviousPage: false,
        isAutoMode: true,
        itemCount: 0,
        loadState: 'loaded',
        mode: 'dynamic',
        nextCursor: null,
        phase: 'idle',
        previousCursor: null,
        removedCount: 0,
        surfaceMode: 'list',
    };
}

export function normalizeCursor(value: BrowsePageToken | string | number | null | undefined): string | null {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? String(value) : null;
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed.length > 0 ? trimmed : null;
    }

    return null;
}

function normalizeUrl(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : null;
}

export function resolveOverlayMediaType(item: FeedItem): OverlayMediaType {
    if (item.media_kind === 'audio') {
        return 'audio';
    }

    if (item.media_kind === 'video' || item.type === 'video') {
        return 'video';
    }

    if (item.media_kind === 'file') {
        return 'file';
    }

    return 'image';
}

export function mapFeedItemToVibeItem(item: FeedItem): VibeViewerItem {
    const previewUrl = normalizeUrl(item.preview ?? item.src ?? null);
    const fullUrl = normalizeUrl(item.originalUrl ?? item.original ?? item.url ?? item.preview ?? item.src ?? null) ?? '';
    const type = item.media_kind === 'audio'
        ? 'audio'
        : item.media_kind === 'file'
            ? 'other'
            : (item.type === 'video' || item.media_kind === 'video' ? 'video' : 'image');

    return {
        id: String(item.id),
        type,
        title: typeof item.title === 'string' ? item.title : undefined,
        url: fullUrl,
        preview: previewUrl ? {
            url: previewUrl,
            width: item.width,
            height: item.height,
        } : undefined,
        width: item.width,
        height: item.height,
        feedItem: item,
        fileId: item.id,
        page: item.page,
        key: item.key,
    };
}

function updateBuckets(
    itemsBuckets: TabContentV2ResolveArgs['itemsBuckets'],
    items: TabContentV2ResolveArgs['items'],
    cursor: string | null,
    nextItems: FeedItem[],
    nextCursor: string | null,
    previousCursor: string | null,
): void {
    const nextBucket = {
        cursor,
        items: nextItems,
        nextCursor,
        previousCursor,
    };
    const currentBuckets = [...itemsBuckets.value];
    const existingIndex = currentBuckets.findIndex((bucket) => bucket.cursor === cursor);

    if (existingIndex >= 0) {
        currentBuckets.splice(existingIndex, 1, nextBucket);
    } else if (currentBuckets.length === 0) {
        currentBuckets.push(nextBucket);
    } else if (currentBuckets[0]?.previousCursor === cursor) {
        currentBuckets.unshift(nextBucket);
    } else if (currentBuckets[currentBuckets.length - 1]?.nextCursor === cursor) {
        currentBuckets.push(nextBucket);
    } else {
        currentBuckets.push(nextBucket);
    }

    itemsBuckets.value = currentBuckets;
    items.value = currentBuckets.flatMap((bucket) => bucket.items);
}

export function createTabContentV2Resolve(args: TabContentV2ResolveArgs) {
    return async function resolve(params: VibeResolveParamsWithSignal): Promise<VibeResolveResult> {
        const formData = args.form.getData();
        const requestedCursor = normalizeCursor(params.cursor ?? args.startPageToken.value);
        const query: Record<string, unknown> = {
            feed: formData.feed,
            tab_id: formData.tab_id,
            page: requestedCursor ?? 1,
            limit: params.pageSize,
        };

        if (formData.feed === 'online') {
            query.service = formData.service;
        } else {
            query.source = formData.source;
        }

        appendBrowseServiceFilters(query, formData.serviceFilters);
        args.updateTabLabel?.(requestedCursor);

        try {
            const { data } = await window.axios.get(browseIndex.url({ query }), {
                signal: params.signal,
            });

            const receivedItems = Array.isArray(data.items) ? data.items as FeedItem[] : [];
            const nextItems = typeof args.filterItems === 'function'
                ? args.filterItems(receivedItems)
                : receivedItems;
            const nextCursor = normalizeCursor(data.nextPage ?? null);
            const previousCursor = normalizeCursor(data.previousPage ?? null);

            updateBuckets(args.itemsBuckets, args.items, requestedCursor, nextItems, nextCursor, previousCursor);
            args.updateTabLabel?.(nextCursor ?? requestedCursor);
            args.updateActiveTab(args.items.value.filter((item) => item !== undefined));

            return {
                items: nextItems.map(mapFeedItemToVibeItem),
                nextPage: nextCursor,
                previousPage: previousCursor ?? undefined,
            };
        } catch (error) {
            const err = error as { message?: unknown; response?: { data?: { message?: unknown } } };
            const message =
                (typeof err?.response?.data?.message === 'string' ? err.response.data.message : null)
                || (typeof err?.message === 'string' ? err.message : null)
                || 'Browse request failed.';
            const trimmed = message.length > 280 ? `${message.slice(0, 280)}…` : message;

            args.toast.error(trimmed);
            console.error('Browse request failed', { query, error });

            return {
                items: [],
                nextPage: null,
            };
        }
    };
}
