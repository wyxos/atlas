import type { Ref } from 'vue';
import type { BadgeReactionType } from './reaction-check-queue';
import { requestCloseCurrentTab } from './reaction-badge-tab-runtime';

type CloseTabRequestState = {
    clearSubmittingUnlessPending: () => void;
    isPending: () => boolean;
    request: () => void;
};

export function createCloseTabRequestState(
    isActive: () => boolean,
    isDownloadLocked: Ref<boolean>,
    submittingReactionType: Ref<BadgeReactionType | null>,
    submittingBlacklist: Ref<boolean>,
): CloseTabRequestState {
    let pending = false;

    function clearSubmittingState(): void {
        if (!isDownloadLocked.value) {
            submittingReactionType.value = null;
        }

        submittingBlacklist.value = false;
    }

    return {
        clearSubmittingUnlessPending: () => {
            if (!pending) {
                clearSubmittingState();
            }
        },
        isPending: () => pending,
        request: () => {
            pending = true;
            void Promise.resolve(requestCloseCurrentTab()).finally(() => {
                pending = false;
                if (isActive()) {
                    clearSubmittingState();
                }
            });
        },
    };
}
