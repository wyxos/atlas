import { describe, expect, it } from 'vitest';
import type { BrowseFormData } from '@/composables/useBrowseForm';
import type { TabData } from '@/composables/useTabs';
import { extractRestoredBrowseSession, resolveLegacyBrowseService } from './tabContentBrowseBootstrap';

function createTab(overrides: Partial<TabData> & { items?: unknown[] } = {}) {
    return {
        id: 1,
        label: 'Browse',
        params: {},
        position: 0,
        isActive: true,
        ...overrides,
    };
}

function createBrowseFormData(overrides: Partial<BrowseFormData> = {}): BrowseFormData {
    return {
        service: '',
        limit: '20',
        page: 1,
        feed: 'online',
        source: 'all',
        tab_id: null,
        serviceFilters: {},
        ...overrides,
    };
}

describe('tabContentBrowseBootstrap', () => {
    it('extracts a restored browse session from saved items and page token', () => {
        const session = extractRestoredBrowseSession(createTab({
            params: {
                service: 'test-service',
                page: 'CURSOR_NEXT',
                next: 'CURSOR_AFTER',
                previous: 'CURSOR_BEFORE',
            },
            items: [{ id: 1, page: 17 }, { id: 2, page: 17 }],
        }));

        expect(session).toEqual({
            activeIndex: 0,
            cursor: 'CURSOR_NEXT',
            items: [{ id: 1, page: 17 }, { id: 2, page: 17 }],
            nextCursor: 'CURSOR_AFTER',
            previousCursor: 'CURSOR_BEFORE',
            startPageToken: 'CURSOR_NEXT',
        });
    });

    it('treats saved params without items as a restorable session', () => {
        const session = extractRestoredBrowseSession(createTab({
            params: {
                service: 'test-service',
            },
        }));

        expect(session).toEqual({
            activeIndex: 0,
            cursor: 1,
            items: [],
            nextCursor: null,
            previousCursor: null,
            startPageToken: 1,
        });
    });

    it('keeps the saved CivitAI cursor when restored items are empty', () => {
        const session = extractRestoredBrowseSession(createTab({
            params: {
                service: 'civit-ai-images',
                page: '20|1773762966318',
            },
        }));

        expect(session).toEqual({
            activeIndex: 0,
            cursor: '20|1773762966318',
            items: [],
            nextCursor: null,
            previousCursor: null,
            startPageToken: '20|1773762966318',
        });
    });

    it('keeps the saved CivitAI cursor when restored items exist', () => {
        const session = extractRestoredBrowseSession(createTab({
            params: {
                service: 'civit-ai-images',
                page: '20|1773762966318',
            },
            items: [{ id: 1, page: 20 }],
        }));

        expect(session).toEqual({
            activeIndex: 0,
            cursor: '20|1773762966318',
            items: [{ id: 1, page: 20 }],
            nextCursor: null,
            previousCursor: null,
            startPageToken: '20|1773762966318',
        });
    });

    it('returns null when there is no saved tab state', () => {
        expect(extractRestoredBrowseSession(createTab())).toBeNull();
        expect(extractRestoredBrowseSession(null)).toBeNull();
    });

    it('resolves a legacy online service from tab source when it is still known', () => {
        const serviceKey = resolveLegacyBrowseService(
            createBrowseFormData(),
            createTab({
                params: {
                    source: 'legacy-service',
                },
            }) as TabData,
            [
                { key: 'legacy-service', label: 'Legacy Service' },
                { key: 'other-service', label: 'Other Service' },
            ],
        );

        expect(serviceKey).toBe('legacy-service');
    });

    it('ignores legacy source fallback for local mode, selected services, or unknown services', () => {
        expect(resolveLegacyBrowseService(
            createBrowseFormData({ feed: 'local' }),
            createTab({
                params: { source: 'legacy-service' },
            }) as TabData,
            [{ key: 'legacy-service', label: 'Legacy Service' }],
        )).toBeNull();

        expect(resolveLegacyBrowseService(
            createBrowseFormData({ service: 'already-selected' }),
            createTab({
                params: { source: 'legacy-service' },
            }) as TabData,
            [{ key: 'legacy-service', label: 'Legacy Service' }],
        )).toBeNull();

        expect(resolveLegacyBrowseService(
            createBrowseFormData(),
            createTab({
                params: { source: 'missing-service' },
            }) as TabData,
            [{ key: 'legacy-service', label: 'Legacy Service' }],
        )).toBeNull();
    });
});
