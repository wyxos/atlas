import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import FileViewerSheetContainerSection from './FileViewerSheetContainerSection.vue';
import type { FileContainer } from '@/types/file';

function makeContainer(overrides: Partial<FileContainer> = {}): FileContainer {
    return {
        id: 261,
        type: 'User',
        source: 'CivitAI',
        source_id: 'Tommu',
        blacklisted: false,
        blacklisted_at: null,
        action_type: null,
        blacklist_previewed_count_mode: 'preserve',
        file_stats: {
            unreacted: 0,
            blacklisted: 1,
            positive: 0,
        },
        ...overrides,
    };
}

describe('FileViewerSheetContainerSection', () => {
    it('offers explicit add and remove blacklist actions for manageable containers', async () => {
        const available = makeContainer();
        const blacklisted = makeContainer({
            id: 262,
            source_id: null,
            blacklisted: true,
            blacklisted_at: '2026-07-11T00:00:00Z',
            action_type: 'blacklist',
        });
        const wrapper = mount(FileViewerSheetContainerSection, {
            props: {
                containers: [available, blacklisted],
                canManageContainerBlacklist: vi.fn(() => true),
            },
        });

        expect(wrapper.text()).toContain('Add to blacklist');
        expect(wrapper.text()).toContain('Remove from blacklist');

        await wrapper.get('[data-test="file-container-blacklist-action-261"]').trigger('click');
        await wrapper.get('[data-test="file-container-blacklist-action-262"]').trigger('click');

        expect(wrapper.emitted('manage')).toEqual([[available], [blacklisted]]);
    });

    it('hides blacklist controls for containers the provider cannot manage', () => {
        const wrapper = mount(FileViewerSheetContainerSection, {
            props: {
                containers: [makeContainer()],
                canManageContainerBlacklist: () => false,
            },
        });

        expect(wrapper.find('[data-test="file-container-blacklist-action-261"]').exists()).toBe(false);
    });
});
