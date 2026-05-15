import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ModerationFeedRemovalBackfillSettings from './ModerationFeedRemovalBackfillSettings.vue';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

Object.defineProperty(window, 'axios', {
    value: mockAxios,
    writable: true,
});

const previewedRun = {
    id: 8,
    status: 'previewed',
    phase: 'ready',
    chunk_size: 500,
    active_rule_count: 2,
    scanned_count: 255204,
    skipped_no_prompt_count: 18174,
    matched_count: 19801,
    updated_count: 0,
    rules_match_current: true,
    can_apply: true,
    started_at: '2026-05-15T10:00:00+00:00',
    finished_at: '2026-05-15T10:02:00+00:00',
    applied_at: null,
    error: null,
    created_at: '2026-05-15T10:00:00+00:00',
    updated_at: '2026-05-15T10:02:00+00:00',
};

beforeEach(() => {
    vi.clearAllMocks();
    mockAxios.get.mockResolvedValue({
        data: {
            active_rule_count: 2,
            items: [previewedRun],
        },
    });
});

describe('ModerationFeedRemovalBackfillSettings', () => {
    it('renders only aggregate moderation maintenance report data', async () => {
        const wrapper = mount(ModerationFeedRemovalBackfillSettings);

        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/settings/moderation-feed-removal-runs');
        expect(wrapper.text()).toContain('255,204');
        expect(wrapper.text()).toContain('19,801');
        expect(wrapper.text()).toContain('Active rules');
        expect(wrapper.text()).not.toContain('rules_hash');
        expect(wrapper.text()).not.toContain('private-rule-term');
        expect(wrapper.text()).not.toContain('sensitive prompt');

        wrapper.unmount();
    });

    it('queues a preview with the selected chunk size', async () => {
        mockAxios.post.mockResolvedValue({
            data: {
                run: {
                    ...previewedRun,
                    id: 9,
                    status: 'pending',
                    phase: 'queued',
                    matched_count: 0,
                    can_apply: false,
                },
            },
        });

        const wrapper = mount(ModerationFeedRemovalBackfillSettings);
        await flushPromises();

        await wrapper.get('[data-test="feed-removal-chunk-size"]').setValue('250');
        await wrapper.get('[data-test="feed-removal-preview-button"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/settings/moderation-feed-removal-runs/preview', {
            chunk_size: 250,
        });
        expect(wrapper.text()).toContain('Preview queued.');

        wrapper.unmount();
    });
});
