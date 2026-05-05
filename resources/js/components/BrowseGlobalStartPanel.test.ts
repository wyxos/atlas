import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { queueManager } from '@/composables/useQueue';
import BrowseGlobalStartPanel from './BrowseGlobalStartPanel.vue';

const simpleStub = defineComponent({
    name: 'SimpleStub',
    setup() {
        return () => h('div');
    },
});

function mountPanel() {
    return mount(BrowseGlobalStartPanel, {
        global: {
            stubs: {
                Ban: simpleStub,
                Heart: simpleStub,
                PanelRightClose: simpleStub,
                Plus: simpleStub,
                Smile: simpleStub,
                ThumbsUp: simpleStub,
                Undo2: simpleStub,
                X: simpleStub,
            },
        },
    });
}

describe('BrowseGlobalStartPanel', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        queueManager.collection.reset();
    });

    afterEach(() => {
        queueManager.collection.reset();
        vi.useRealTimers();
    });

    it('renders live queued reaction cards and emits close from the close button', async () => {
        queueManager.collection.add({
            id: 'like-5501',
            duration: 5000,
            onComplete: vi.fn(),
            metadata: { fileId: 5501, reactionType: 'like', thumbnail: 'thumb.jpg' },
        });
        queueManager.collection.add({
            id: 'batch-love-5502-2',
            duration: 5000,
            onComplete: vi.fn(),
            metadata: {
                fileIds: [5502, 5503],
                previews: [{ fileId: 5502, thumbnail: 'thumb-5502.jpg' }, { fileId: 5503 }],
                reactionType: 'love',
            },
        });

        const wrapper = mountPanel();

        expect(wrapper.get('[data-test="browse-global-start-panel-list"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="browse-global-start-panel-count"]').text()).toBe('3');
        expect(wrapper.findAll('[data-test="browse-global-start-panel-card"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Loved 2 files');
        expect(wrapper.text()).toContain('file #5501');

        await wrapper.get('[data-test="browse-global-start-panel-close-button"]').trigger('click');

        expect(wrapper.emitted('close')).toEqual([[]]);
    });

    it('pauses the queue while mounted and resumes immediately when unmounted', async () => {
        const onComplete = vi.fn();
        queueManager.collection.add({
            id: 'funny-5504',
            duration: 1000,
            onComplete,
            metadata: { fileId: 5504, reactionType: 'funny' },
        });

        const wrapper = mountPanel();

        expect(queueManager.freeze.isFrozen.value).toBe(true);
        await vi.advanceTimersByTimeAsync(1500);
        expect(onComplete).not.toHaveBeenCalled();

        wrapper.unmount();

        expect(queueManager.freeze.isFrozen.value).toBe(false);
        await vi.advanceTimersByTimeAsync(1100);
        expect(onComplete).toHaveBeenCalledTimes(1);
    });

    it('undoes a queued card from the panel', async () => {
        const restoreCallback = vi.fn();
        queueManager.collection.add({
            id: 'blacklist-5505',
            duration: 5000,
            onComplete: vi.fn(),
            metadata: { fileId: 5505, reactionType: 'blacklist', restoreCallback },
        });

        const wrapper = mountPanel();

        await wrapper.get('[data-test="browse-global-start-panel-card"] button[aria-label="Remove queued reaction"]').trigger('click');

        expect(queueManager.collection.has('blacklist-5505')).toBe(false);
        expect(restoreCallback).toHaveBeenCalledTimes(1);
    });
});
