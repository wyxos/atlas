import { ref } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import StatusToast from '@/components/toasts/StatusToast.vue';
import type { LoadedItemsBulkAction, TabContentItemInteractions } from './useTabContentItemInteractions';

export type LoadedItemsAction = LoadedItemsBulkAction | 'reset-previewed';

type LoadedItemsActionController = Pick<
    TabContentItemInteractions,
    'resetPreviewedState' | 'performLoadedItemsBulkAction'
>;

export function useTabContentLoadedItemsActions(itemInteractions: LoadedItemsActionController) {
    const toast = useToast();
    const activeLoadedItemsAction = ref<LoadedItemsAction | null>(null);
    const loadedItemsToastId = 'loaded-items-toast';

    function showLoadedItemsToast(
        variant: 'success' | 'info' | 'error',
        title: string,
        description?: string,
    ): void {
        toast.dismiss(loadedItemsToastId);
        toast(
            {
                component: StatusToast,
                props: {
                    toastId: loadedItemsToastId,
                    variant,
                    title,
                    description,
                },
            },
            {
                id: loadedItemsToastId,
                closeButton: false,
                closeOnClick: false,
            }
        );
    }

    function getLoadedItemsActionSuccessMessage(action: LoadedItemsAction, count: number): string {
        if (action === 'reset-previewed') {
            return `Reset previewed counts for ${count} loaded item${count === 1 ? '' : 's'}.`;
        }

        if (action === 'love') {
            return `Favorited ${count} loaded item${count === 1 ? '' : 's'}.`;
        }

        if (action === 'like') {
            return `Liked ${count} loaded item${count === 1 ? '' : 's'}.`;
        }

        if (action === 'funny') {
            return `Marked ${count} loaded item${count === 1 ? '' : 's'} as funny.`;
        }

        if (action === 'dislike') {
            return `Disliked ${count} loaded item${count === 1 ? '' : 's'}.`;
        }

        if (action === 'blacklist') {
            return `Blacklisted ${count} loaded item${count === 1 ? '' : 's'}.`;
        }

        return `Incremented preview counts by 4 for ${count} loaded item${count === 1 ? '' : 's'}.`;
    }

    function getLoadedItemsActionZeroMessage(action: LoadedItemsAction): string {
        if (action === 'reset-previewed') {
            return 'No loaded items to reset.';
        }

        if (action === 'blacklist') {
            return 'No loaded items were blacklisted.';
        }

        return 'No loaded items available for this action.';
    }

    function getLoadedItemsActionErrorMessage(action: LoadedItemsAction): string {
        if (action === 'reset-previewed') {
            return 'Failed to reset previewed counts.';
        }

        if (action === 'blacklist') {
            return 'Failed to blacklist loaded items.';
        }

        if (action === 'increment-preview-4') {
            return 'Failed to increment preview counts.';
        }

        return 'Failed to update loaded items.';
    }

    async function runLoadedItemsAction(action: LoadedItemsAction): Promise<void> {
        if (activeLoadedItemsAction.value !== null) {
            return;
        }

        activeLoadedItemsAction.value = action;

        try {
            const affectedCount = action === 'reset-previewed'
                ? await itemInteractions.resetPreviewedState()
                : await itemInteractions.performLoadedItemsBulkAction(action);

            if (affectedCount === 0) {
                showLoadedItemsToast('info', getLoadedItemsActionZeroMessage(action));
                return;
            }

            showLoadedItemsToast(
                'success',
                getLoadedItemsActionSuccessMessage(action, affectedCount),
            );
        } catch (error) {
            console.error(`Failed to run loaded items action "${action}":`, error);
            showLoadedItemsToast('error', getLoadedItemsActionErrorMessage(action));
        } finally {
            activeLoadedItemsAction.value = null;
        }
    }

    return {
        state: {
            activeLoadedItemsAction,
        },
        actions: {
            runLoadedItemsAction,
        },
    };
}
