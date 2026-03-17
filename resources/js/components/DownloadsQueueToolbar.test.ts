import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import DownloadsQueueToolbar from './DownloadsQueueToolbar.vue';

const dropdownMenuStubs = {
    DropdownMenu: { template: '<div><slot /></div>' },
    DropdownMenuTrigger: { template: '<div><slot /></div>' },
    DropdownMenuContent: { template: '<div><slot /></div>' },
    DropdownMenuLabel: { template: '<div><slot /></div>' },
    DropdownMenuSeparator: { template: '<div />' },
    DropdownMenuItem: {
        props: ['disabled'],
        emits: ['select'],
        template: '<button :disabled="disabled" @click="$emit(\'select\')"><slot /></button>',
    },
};

describe('DownloadsQueueToolbar', () => {
    it('groups non-selected batch actions into one menu', async () => {
        const wrapper = mount(DownloadsQueueToolbar, {
            props: {
                filters: ['all'],
                selectedStatus: 'all',
                downloadsCount: 6,
                filteredCount: 6,
                statusCounts: {},
                selectedCount: 0,
                selectedInFilterCount: 0,
                selectedPausableCount: 0,
                selectedResumableCount: 0,
                selectedCancelableCount: 0,
                selectedRestartableCount: 0,
                resumableFailedCount: 2,
                restartableFailedCount: 3,
                completedCount: 1,
                batchIsPausing: false,
                batchIsResuming: false,
                batchIsCanceling: false,
                batchIsRestarting: false,
                batchIsResumingFailed: false,
                batchIsRestartingFailed: false,
                removeIsDeleting: false,
            },
            global: {
                stubs: dropdownMenuStubs,
            },
        });

        const buttons = wrapper.findAll('button');
        const trigger = buttons.find((button) => button.text().includes('All actions'));
        const resumeButton = buttons.find((button) => button.text().includes('Resume failed (2)'));
        const restartButton = buttons.find((button) => button.text().includes('Restart failed (3)'));
        const removeCompletedButton = buttons.find((button) => button.text().includes('Remove completed (1)'));
        const removeFilteredButton = buttons.find((button) => button.text().includes('Remove filtered (6)'));

        expect(trigger?.exists()).toBe(true);

        if (!resumeButton || !restartButton || !removeCompletedButton || !removeFilteredButton) {
            throw new Error('All-actions menu items were not rendered.');
        }

        await resumeButton.trigger('click');
        await restartButton.trigger('click');
        await removeCompletedButton.trigger('click');
        await removeFilteredButton.trigger('click');

        expect(wrapper.emitted('resumeFailed')).toHaveLength(1);
        expect(wrapper.emitted('restartFailed')).toHaveLength(1);
        expect(wrapper.emitted('removeCompleted')).toHaveLength(1);
        expect(wrapper.emitted('removeFiltered')).toHaveLength(1);
    });

    it('renders separate all and selected menus when both scopes are available', async () => {
        const wrapper = mount(DownloadsQueueToolbar, {
            props: {
                filters: ['all'],
                selectedStatus: 'all',
                downloadsCount: 6,
                filteredCount: 6,
                statusCounts: {},
                selectedCount: 3,
                selectedInFilterCount: 3,
                selectedPausableCount: 2,
                selectedResumableCount: 1,
                selectedCancelableCount: 3,
                selectedRestartableCount: 1,
                resumableFailedCount: 0,
                restartableFailedCount: 0,
                completedCount: 0,
                batchIsPausing: false,
                batchIsResuming: false,
                batchIsCanceling: false,
                batchIsRestarting: false,
                batchIsResumingFailed: false,
                batchIsRestartingFailed: false,
                removeIsDeleting: false,
            },
            global: {
                stubs: dropdownMenuStubs,
            },
        });

        const buttons = wrapper.findAll('button');
        const allTrigger = buttons.find((button) => button.text().includes('All actions'));
        const selectedTrigger = buttons.find((button) => button.text().includes('Selected actions (3)'));
        const pauseButton = buttons.find((button) => button.text().includes('Pause selected (2)'));
        const resumeButton = buttons.find((button) => button.text().includes('Resume selected (1)'));
        const cancelButton = buttons.find((button) => button.text().includes('Cancel selected (3)'));
        const restartButton = buttons.find((button) => button.text().includes('Restart selected (1)'));
        const removeButton = buttons.find((button) => button.text().includes('Remove selection'));

        expect(allTrigger?.exists()).toBe(true);
        expect(selectedTrigger?.exists()).toBe(true);

        if (!pauseButton || !resumeButton || !cancelButton || !restartButton || !removeButton) {
            throw new Error('Selected-action menu items were not rendered.');
        }

        await pauseButton.trigger('click');
        await resumeButton.trigger('click');
        await cancelButton.trigger('click');
        await restartButton.trigger('click');
        await removeButton.trigger('click');

        expect(wrapper.emitted('pauseSelection')).toHaveLength(1);
        expect(wrapper.emitted('resumeSelection')).toHaveLength(1);
        expect(wrapper.emitted('cancelSelection')).toHaveLength(1);
        expect(wrapper.emitted('restartSelection')).toHaveLength(1);
        expect(wrapper.emitted('removeSelection')).toHaveLength(1);
    });
});
