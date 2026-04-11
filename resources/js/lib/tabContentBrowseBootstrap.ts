import type { BrowseFormData } from '@/composables/useBrowseForm';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { BrowsePageToken } from '@/types/browse';
import type { ServiceOption } from './browseCatalog';

type RestorableTabData = TabData & {
    items?: FeedItem[];
};

export type RestoredBrowseSession = {
    activeIndex: number;
    cursor: BrowsePageToken;
    nextCursor: BrowsePageToken | null;
    items: FeedItem[];
    startPageToken: BrowsePageToken;
    previousCursor: BrowsePageToken | null;
};

function normalizeRestoredPageToken(value: unknown): BrowsePageToken | null {
    if (typeof value === 'number') {
        return Number.isFinite(value) ? value : null;
    }

    if (typeof value === 'string' && value.trim().length > 0) {
        return value;
    }

    return null;
}

function resolveRestoredStartPageToken(params: Record<string, unknown>, items: FeedItem[]): BrowsePageToken {
    const page = (params.page ?? 1) as BrowsePageToken;
    const feed = params.feed === 'local' ? 'local' : 'online';
    const service = typeof params.service === 'string' ? params.service : null;
    const usesCursorToken = typeof page === 'string' && page.includes('|');

    // CivitAI stores some next cursors in `page` as `offset|timestamp`. If we do not also
    // have restored items, reusing that token can reopen into an empty result set after filters change.
    if (feed === 'online' && service === 'civit-ai-images' && items.length === 0 && usesCursorToken) {
        return 1;
    }

    return page;
}

function resolveRestoredActiveIndex(items: FeedItem[], pageToken: BrowsePageToken): number {
    const pageValue = typeof pageToken === 'number'
        ? pageToken
        : (typeof pageToken === 'string' && pageToken.trim().length > 0 && Number.isFinite(Number(pageToken))
            ? Number(pageToken)
            : null);

    if (pageValue === null) {
        return 0;
    }

    const firstPageItemIndex = items.findIndex((item) => item.page === pageValue);

    return firstPageItemIndex >= 0 ? firstPageItemIndex : 0;
}

export function extractRestoredBrowseSession(tab?: RestorableTabData | null): RestoredBrowseSession | null {
    if (!tab) {
        return null;
    }

    const params = (tab.params ?? {}) as Record<string, unknown>;
    const items = Array.isArray(tab.items) ? tab.items : [];
    const page = resolveRestoredStartPageToken(params, items);

    if (items.length === 0 && Object.keys(params).length === 0) {
        return null;
    }

    return {
        activeIndex: resolveRestoredActiveIndex(items, page),
        cursor: page,
        nextCursor: normalizeRestoredPageToken(params.next),
        items,
        startPageToken: resolveRestoredStartPageToken(params, items),
        previousCursor: normalizeRestoredPageToken(params.previous),
    };
}

export function resolveLegacyBrowseService(
    formData: BrowseFormData,
    tab: TabData | null,
    availableServices: ServiceOption[],
): string | null {
    if (formData.feed !== 'online' || formData.service) {
        return null;
    }

    const legacyCandidate = tab?.params?.source;

    if (typeof legacyCandidate !== 'string' || legacyCandidate.length === 0) {
        return null;
    }

    return availableServices.some((service) => service.key === legacyCandidate)
        ? legacyCandidate
        : null;
}
