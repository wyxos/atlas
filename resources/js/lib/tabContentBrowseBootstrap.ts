import type { PageToken } from '@wyxos/vibe';
import type { BrowseFormData } from '@/composables/useBrowseForm';
import type { FeedItem, TabData } from '@/composables/useTabs';
import type { ServiceOption } from './browseCatalog';

type RestorableTabData = TabData & {
    items?: FeedItem[];
};

export type RestoredBrowseSession = {
    items: FeedItem[];
    startPageToken: PageToken;
};

function resolveRestoredStartPageToken(params: Record<string, unknown>, items: FeedItem[]): PageToken {
    const page = (params.page ?? 1) as PageToken;
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

export function extractRestoredBrowseSession(tab?: RestorableTabData | null): RestoredBrowseSession | null {
    if (!tab) {
        return null;
    }

    const params = (tab.params ?? {}) as Record<string, unknown>;
    const items = Array.isArray(tab.items) ? tab.items : [];

    if (items.length === 0 && Object.keys(params).length === 0) {
        return null;
    }

    return {
        items,
        startPageToken: resolveRestoredStartPageToken(params, items),
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
