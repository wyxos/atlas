import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DownloadsQueueToolbar from './DownloadsQueueToolbar.vue';

describe('DownloadsQueueToolbar', () => {
    it('renders separate resume and restart failed actions', async () => {
        const wrapper = mount(DownloadsQueueToolbar, {
            props: {
                filters: ['all'],
                selectedStatus: 'all',
                downloadsCount: 6,
                filteredCount: 6,
                statusCounts: {},
                selectedCount: 0,
                selectedInFilterCount: 0,
                resumableFailedCount: 2,
                restartableFailedCount: 3,
                completedCount: 1,
                batchIsPausing: false,
                batchIsCanceling: false,
                batchIsResumingFailed: false,
                batchIsRestartingFailed: false,
                removeIsDeleting: false,
            },
        });

        const buttons = wrapper.findAll('button');
        const resumeButton = buttons.find((button) => button.text().includes('Resume failed (2)'));
        const restartButton = buttons.find((button) => button.text().includes('Restart failed (3)'));

        if (!resumeButton || !restartButton) {
            throw new Error('Failed action buttons were not rendered.');
        }

        await resumeButton.trigger('click');
        await restartButton.trigger('click');

        expect(wrapper.emitted('resumeFailed')).toHaveLength(1);
        expect(wrapper.emitted('restartFailed')).toHaveLength(1);
    });
});
