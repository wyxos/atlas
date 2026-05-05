import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BrowseGlobalStartPanel from './BrowseGlobalStartPanel.vue';

const simpleStub = defineComponent({
    name: 'SimpleStub',
    setup() {
        return () => h('div');
    },
});

describe('BrowseGlobalStartPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders static reaction-like cards and emits close from the close button', async () => {
        const wrapper = mount(BrowseGlobalStartPanel, {
            global: {
                stubs: {
                    Ban: simpleStub,
                    Heart: simpleStub,
                    PanelRightClose: simpleStub,
                    Smile: simpleStub,
                    ThumbsUp: simpleStub,
                    Undo2: simpleStub,
                    X: simpleStub,
                },
            },
        });

        await nextTick();

        expect(wrapper.get('[data-test="browse-global-start-panel-list"]').exists()).toBe(true);
        expect(wrapper.findAll('[data-test="browse-global-start-panel-card"]')).toHaveLength(100);
        expect(wrapper.findAll('[data-test="browse-global-start-panel-card"]')[0].text()).toContain('#5500');

        await vi.advanceTimersByTimeAsync(700);

        expect(wrapper.findAll('[data-test="browse-global-start-panel-card"]')).toHaveLength(101);
        expect(wrapper.findAll('[data-test="browse-global-start-panel-card"]')[0].text()).toContain('#5501');

        await wrapper.get('[data-test="browse-global-start-panel-close-button"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[]]);
    });
});
