import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import { renderReactionBadge } from './reaction-badge-view';
import type { MediaElement } from './media-utils';
import type { BadgeReactionType } from './reaction-check-queue';
import { useReactionBadge } from './use-reaction-badge';

export const AtlasReactionBadge = defineComponent({
    name: 'AtlasReactionBadge',
    props: {
        media: {
            type: Object as PropType<MediaElement>,
            required: true,
        },
        onShortcutReady: {
            type: Function as PropType<((handler: ((type: BadgeReactionType) => void) | null) => void) | undefined>,
            required: false,
            default: undefined,
        },
    },
    setup(props) {
        const badge = useReactionBadge(props);

        return () => renderReactionBadge(
            {
                isChecking: badge.isChecking.value,
                controlsDisabled: badge.controlsDisabled.value,
                activeReaction: badge.activeReaction.value,
                hoveredReaction: badge.hoveredReaction.value,
                submittingReactionType: badge.submittingReactionType.value,
                closeTabAfterQueueMode: badge.closeTabAfterQueueMode.value,
                closeTabAfterQueueSaving: badge.isSavingCloseTabAfterQueuePreference.value,
                mediaResolution: badge.mediaResolution.value,
                openTabCount: badge.openTabCount.value,
                timestampText: badge.timestampText.value,
                progressDisplayValue: badge.progressState.value.progressDisplayValue,
                progressColor: badge.progressState.value.progressColor,
                transferStatus: badge.transferStatus.value,
                showReactAllItemsInPost: badge.showReactAllItemsInPost.value,
                reactAllItemsInPost: badge.reactAllItemsInPost.value,
            },
            {
                onReactionClick: (reactionType) => {
                    void badge.handleReactionClick(reactionType);
                },
                onReactionHover: (reactionType) => {
                    badge.hoveredReaction.value = reactionType;
                },
                onCloseTabAfterQueueToggle: () => {
                    void badge.cycleCloseTabAfterQueuePreference();
                },
                onReactAllItemsInPostToggle: () => {
                    void badge.handleReactAllItemsInPostToggle();
                },
            },
        );
    },
});
