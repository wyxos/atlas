import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TabContentV2FullscreenPageLoadingLock from './TabContentV2FullscreenPageLoadingLock.vue';

describe('TabContentV2FullscreenPageLoadingLock', () => {
    it('explains that fullscreen page loading is locked and unlocks from the message', async () => {
        const unlockPageLoading = vi.fn();
        const wrapper = mount(TabContentV2FullscreenPageLoadingLock, {
            props: {
                canUnlock: true,
                unlockPageLoading,
            },
        });

        expect(wrapper.text()).toContain('More content loading is locked');
        expect(wrapper.text()).toContain('Unlock page loading to keep moving through the remaining feed.');

        await wrapper.get('[data-testid="browse-fullscreen-page-loading-unlock"]').trigger('click');

        expect(unlockPageLoading).toHaveBeenCalledTimes(1);
    });

    it('hides the unlock action when the Vibe handle cannot unlock page loading', () => {
        const wrapper = mount(TabContentV2FullscreenPageLoadingLock, {
            props: {
                canUnlock: false,
                unlockPageLoading: null,
            },
        });

        expect(wrapper.find('[data-testid="browse-fullscreen-page-loading-unlock"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('More content loading is locked');
    });
});
