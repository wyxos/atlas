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
        startPageToken: (params.page ?? 1) as PageToken,
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
