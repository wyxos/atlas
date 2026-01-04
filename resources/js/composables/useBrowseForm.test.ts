import { describe, expect, it } from 'vitest';
import { useBrowseForm } from './useBrowseForm';
import type { TabData } from './useTabs';

describe('useBrowseForm - defaults merging', () => {
    it('fills null cached service filters from defaults', () => {
        const form = useBrowseForm();
        form.reset();

        const tab: TabData = {
            id: 1,
            label: 'Browse 1',
            position: 0,
            isActive: true,
            params: {
                service: 'civit-ai-images',
                feed: 'online',
                source: 'all',
                // Simulate a restored tab that has seeded a null (this is what breaks radio preselect)
                type: null,
            },
        };

        form.syncFromTab(tab);

        form.setService('civit-ai-images', {
            sort: 'Newest',
            type: 'all',
            period: 'AllTime',
            nsfw: false,
        });

        expect(form.data.service).toBe('civit-ai-images');
        expect(form.data.serviceFilters.type).toBe('all');
    });

    it('does not override non-null cached values with defaults', () => {
        const form = useBrowseForm();
        form.reset();

        const tab: TabData = {
            id: 1,
            label: 'Browse 1',
            position: 0,
            isActive: true,
            params: {
                service: 'civit-ai-images',
                feed: 'online',
                source: 'all',
                type: 'video',
            },
        };

        form.syncFromTab(tab);

        form.setService('civit-ai-images', {
            type: 'all',
        });

        expect(form.data.serviceFilters.type).toBe('video');
    });
});
