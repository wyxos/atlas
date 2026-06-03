import type { PropType } from 'vue';
import { defineComponent } from 'vue';
import { renderReactionBadge } from './reaction-badge-view';
import type { MediaElement } from './media-utils';
import { type BadgeSubmitType, type ReactionBadgeRefreshOptions, useReactionBadge } from './use-reaction-badge';

export const AtlasReactionBadge = defineComponent({
    name: 'AtlasReactionBadge',
    props: {
        media: {
            type: Object as PropType<MediaElement>,
            required: true,
        },
        onShortcutReady: {
            type: Function as PropType<((handler: ((type: BadgeSubmitType) => void) | null) => void) | undefined>,
            required: false,
            default: undefined,
        },
        onRefreshReady: {
            type: Function as PropType<((handler: ((options?: ReactionBadgeRefreshOptions) => void) | null) => void) | undefined>,
            required: false,
            default: undefined,
        },
        initialRefreshOptions: {
            type: Object as PropType<ReactionBadgeRefreshOptions | undefined>,
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
                isBlacklisted: badge.isBlacklisted.value,
                hoveredReaction: badge.hoveredReaction.value,
                submittingReactionType: badge.submittingReactionType.value,
                submittingBlacklist: badge.submittingBlacklist.value,
                closeTabAfterQueueMode: badge.closeTabAfterQueueMode.value,
                closeTabAfterQueueSaving: badge.isSavingCloseTabAfterQueuePreference.value,
                mediaResolution: badge.mediaResolution.value,
                openTabCount: badge.openTabCount.value,
                similarDomainTabCount: badge.similarDomainTabCount.value,
                timestampText: badge.timestampText.value,
                progressDisplayValue: badge.progressState.value.progressDisplayValue,
                progressColor: badge.progressState.value.progressColor,
                transferStatus: badge.transferStatus.value,
                showReactAllItemsInPost: badge.showReactAllItemsInPost.value,
                reactAllItemsInPost: badge.reactAllItemsInPost.value,
                atlasFileUrl: badge.atlasFileUrl.value,
                canDeleteFile: badge.canDeleteFile.value,
                deletingFile: badge.isDeletingFile.value,
            },
            {
                onReactionClick: (reactionType) => {
                    void badge.handleReactionClick(reactionType);
                },
                onReactionHover: (reactionType) => {
                    badge.hoveredReaction.value = reactionType;
                },
                onBlacklistClick: () => {
                    void badge.handleReactionClick('blacklist');
                },
                onCloseTabAfterQueueToggle: () => {
                    void badge.cycleCloseTabAfterQueuePreference();
                },
                onDeleteFileClick: () => {
                    void badge.handleDeleteFileClick();
                },
                onReactAllItemsInPostToggle: () => {
                    void badge.handleReactAllItemsInPostToggle();
                },
            },
        );
    },
});
