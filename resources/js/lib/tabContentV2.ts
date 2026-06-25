import type { Ref } from 'vue';
import type {
    VibeResolveParams,
    VibeResolveResult,
    VibeStatus,
    VibeViewerItem,
} from '@wyxos/vibe';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { FeedItem } from '@/composables/useTabs';
import type { BrowsePageToken } from '@/types/browse';
import { blocksDownloadedPreviewFallback } from '@/lib/filePreviewGeneration';
import { appendBrowseServiceFilters } from '@/utils/browseQuery';

export type OverlayMediaType = 'image' | 'video' | 'audio' | 'file';
type VibeResolveParamsWithSignal = VibeResolveParams & { signal?: AbortSignal };
type AtlasVibeViewerItem = VibeViewerItem & {
    healthCheck?: {
        kind: 'playback';
        url: string;
    };
    spotifyUri?: string;
};

type TabContentV2ResolveArgs = {
    form: BrowseFormInstance;
    startPageToken: Ref<BrowsePageToken>;
    totalAvailable?: Ref<number | null>;
    updateTabLabel?: (cursor: BrowsePageToken | string | number | null | undefined) => void;
    filterItems?: (items: FeedItem[]) => FeedItem[];
    toast: {
        error: (message: string, options?: { duration?: number }) => void;
    };
};

const LIBRARY_UNAVAILABLE_MESSAGE = 'Library unavailable';
const LIBRARY_UNAVAILABLE_TOAST_DURATION_MS = 5000;

export function createTabContentV2EmptyStatus(): VibeStatus {
    return {
        activeIndex: 0,
        currentCursor: null,
        errorMessage: null,
        fillCollectedCount: null,
        fillCompletedCalls: 0,
        fillCursor: null,
        fillDelayRemainingMs: null,
        fillLoadedCount: 0,
        fillMode: 'idle',
        fillProgress: null,
        fillTargetCalls: null,
        fillTargetCount: null,
        fillTotalCount: null,
        hasNextPage: false,
        hasPreviousPage: false,
        itemCount: 0,
        itemsRevision: 0,
        loadState: 'loaded',
        nextBoundaryLoadProgress: 0,
        nextCursor: null,
        pageLoadingLocked: false,
        phase: 'idle',
        previousBoundaryLoadProgress: 0,
        previousCursor: null,
        removedCount: 0,
        removedIds: [],
        removedRevision: 0,
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

export function createRemovedItemIdSet(ids: readonly string[]): Set<number> {
    const next = new Set<number>();

    for (const id of ids) {
        const parsed = Number(id);
        if (Number.isFinite(parsed)) {
            next.add(parsed);
        }
    }

    return next;
}

function normalizeTotal(value: unknown): number | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) && value >= 0 ? Math.floor(value) : null;
    }

    if (typeof value === 'string' && value.trim() === '') {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) && parsed >= 0 ? Math.floor(parsed) : null;
}

function normalizeUrl(value: unknown): string | null {
    return normalizeText(value);
}

function normalizeText(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    return trimmed.length > 0 ? trimmed : null;
}

function showBrowseErrorToast(
    toast: TabContentV2ResolveArgs['toast'],
    message: string,
): void {
    if (message === LIBRARY_UNAVAILABLE_MESSAGE) {
        toast.error(message, { duration: LIBRARY_UNAVAILABLE_TOAST_DURATION_MS });
        return;
    }

    toast.error(message);
}

function browseServiceErrorMessage(data: unknown): string | null {
    if (!data || typeof data !== 'object' || !('error' in data)) {
        return null;
    }

    const error = (data as { error?: unknown }).error;
    if (!error || typeof error !== 'object' || !('message' in error)) {
        return null;
    }

    const message = (error as { message?: unknown }).message;

    return typeof message === 'string' && message.trim() !== '' ? message.trim() : null;
}

export function resolveOverlayMediaType(item: FeedItem): OverlayMediaType {
    if (item.media_kind === 'audio') {
        if (isSpotifyFeedAudio(item)) {
            return 'image';
        }

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

function isNonEmptyText(value: unknown): value is string {
    return typeof value === 'string' && value.trim().length > 0;
}

export function isSpotifyFeedAudio(item: FeedItem): boolean {
    if (item.media_kind !== 'audio') {
        return false;
    }

    const source = typeof item.source === 'string' ? item.source.trim().toLowerCase() : '';
    const mimeType = typeof item.mime_type === 'string' ? item.mime_type.trim().toLowerCase() : '';

    return source === 'spotify'
        || mimeType === 'audio/spotify'
        || isNonEmptyText(item.spotify_uri);
}

export function mapFeedItemToVibeItem(item: FeedItem): AtlasVibeViewerItem {
    const previewUrl = normalizeUrl(item.preview ?? item.src ?? null);
    const isSpotifyAudio = isSpotifyFeedAudio(item);
    const shouldHoldOriginalUrl = !previewUrl && blocksDownloadedPreviewFallback(item);
    const fullUrl = shouldHoldOriginalUrl
        ? ''
        : isSpotifyAudio
        ? previewUrl ?? normalizeUrl(item.src ?? item.thumbnail ?? null) ?? ''
        : normalizeUrl(item.originalUrl ?? item.original ?? item.url ?? item.preview ?? item.src ?? null) ?? '';
    const type = item.media_kind === 'audio'
        ? (isSpotifyAudio ? 'image' : 'audio')
        : item.media_kind === 'file'
            ? 'other'
            : (item.type === 'video' || item.media_kind === 'video' ? 'video' : 'image');
    const previewMediaType = type === 'video' ? 'video' : 'image';
    const previewAsset = previewUrl ? ({
        url: previewUrl,
        width: item.width,
        height: item.height,
        mediaType: previewMediaType,
    } as VibeViewerItem['preview']) : undefined;
    const healthCheck = (type === 'audio' || type === 'other') && fullUrl !== '' && fullUrl !== previewUrl
        ? {
            url: fullUrl,
            kind: 'playback' as const,
        }
        : undefined;

    return {
        id: String(item.id),
        type,
        title: normalizeText(item.title) ?? normalizeText(item.filename) ?? undefined,
        url: fullUrl,
        preview: previewAsset,
        healthCheck,
        width: item.width,
        height: item.height,
        feedItem: item,
        fileId: item.id,
        spotifyUri: isNonEmptyText(item.spotify_uri) ? item.spotify_uri.trim() : undefined,
        page: item.page,
        key: item.key,
    };
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

            const serviceErrorMessage = browseServiceErrorMessage(data);
            if (serviceErrorMessage) {
                throw new Error(serviceErrorMessage);
            }

            const total = normalizeTotal(data.total);

            if (args.totalAvailable) {
                args.totalAvailable.value = total;
            }

            const receivedItems = Array.isArray(data.items) ? data.items as FeedItem[] : [];
            const nextItems = typeof args.filterItems === 'function'
                ? args.filterItems(receivedItems)
                : receivedItems;
            const nextCursor = normalizeCursor(data.nextPage ?? null);
            const previousCursor = normalizeCursor(data.previousPage ?? null);

            args.updateTabLabel?.(nextCursor ?? requestedCursor);

            return {
                items: nextItems.map(mapFeedItemToVibeItem),
                nextPage: nextCursor,
                previousPage: previousCursor ?? undefined,
                total,
            };
        } catch (error) {
            const err = error as { message?: unknown; response?: { data?: { message?: unknown } } };
            const message =
                (typeof err?.response?.data?.message === 'string' ? err.response.data.message : null)
                || (typeof err?.message === 'string' ? err.message : null)
                || 'Browse request failed.';
            const trimmed = message.length > 280 ? `${message.slice(0, 280)}…` : message;

            showBrowseErrorToast(args.toast, trimmed);
            console.error('Browse request failed', { query, error });

            if (args.totalAvailable) {
                args.totalAvailable.value = null;
            }

            throw new Error(trimmed);
        }
    };
}
