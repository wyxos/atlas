import { describe, expect, it } from 'vitest';
import type { BrowseFormData } from '@/composables/useBrowseForm';
import { buildBrowseTabLabel, formatTabLabel } from './browseTabLabel';

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

describe('browseTabLabel', () => {
    it('formats a tab label with an optional container label', () => {
        expect(formatTabLabel('CivitAI Images', 'CURSOR_1', 'User atlasUser')).toBe('CivitAI Images: User atlasUser - CURSOR_1');
        expect(formatTabLabel('Wallhaven', 1)).toBe('Wallhaven - 1');
    });

    it('builds a label for online browse state with a known service and container filter', () => {
        expect(buildBrowseTabLabel({
            formData: createBrowseFormData({
                service: 'civit-ai-images',
                serviceFilters: {
                    username: 'atlasUser',
                },
            }),
            pageToken: 'CURSOR_1',
            availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        })).toBe('CivitAI Images: User atlasUser - CURSOR_1');
    });

    it('builds a label for local browse state with preset label context', () => {
        expect(buildBrowseTabLabel({
            formData: createBrowseFormData({
                feed: 'local',
                serviceFilters: {
                    local_preset: 'inbox_fresh',
                },
            }),
            pageToken: 3,
            availableServices: [],
            localService: { key: 'local', label: 'Local' },
        })).toBe('Local - Inbox (Fresh) - 3');
    });

    it('returns null when online browse has no selected service yet', () => {
        expect(buildBrowseTabLabel({
            formData: createBrowseFormData(),
            pageToken: 1,
            availableServices: [{ key: 'civit-ai-images', label: 'CivitAI Images' }],
        })).toBeNull();
    });
});
