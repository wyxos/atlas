import { h, type VNode } from 'vue';
import { Ban, Download, Heart, Layers, Loader2, Smile, ThumbsUp } from 'lucide-vue-next';
import type { BadgeReactionType } from './reaction-check-queue';
import type { CloseTabAfterQueueMode } from '../atlas-options';
import { formatTabCountSummary } from '../tab-counts';
import { renderAtlasFileLink, renderDeleteFileButton } from './reaction-badge-file-actions-view';

export type BadgeTimestampDisplay = {
    icon: typeof Ban | typeof Download;
    text: string;
} | null;

type BadgeViewModel = {
    isChecking: boolean;
    controlsDisabled: boolean;
    activeReaction: BadgeReactionType | null;
    isBlacklisted: boolean;
    hoveredReaction: BadgeReactionType | null;
    submittingReactionType: BadgeReactionType | null;
    submittingBlacklist: boolean;
    closeTabAfterQueueMode: CloseTabAfterQueueMode;
    closeTabAfterQueueSaving: boolean;
    mediaResolution: string | null;
    similarDomainTabCount: number | null;
    openTabCount: number | null;
    timestampText: BadgeTimestampDisplay;
    progressDisplayValue: number;
    progressColor: string;
    transferStatus: string | null;
    showReactAllItemsInPost: boolean;
    reactAllItemsInPost: boolean;
    atlasFileUrl: string | null;
    canDeleteFile: boolean;
    deletingFile: boolean;
};

type BadgeViewHandlers = {
    onReactionClick: (type: BadgeReactionType) => void;
    onReactionHover: (type: BadgeReactionType | null) => void;
    onBlacklistClick: () => void;
    onCloseTabAfterQueueToggle: () => void;
    onDeleteFileClick: () => void;
    onReactAllItemsInPostToggle: () => void;
};

const reactionIconByType = {
    love: Heart,
    like: ThumbsUp,
    funny: Smile,
} as const;

const iconBaseStyle = {
    width: '18px',
    height: '18px',
    strokeWidth: 2,
    color: '#ffffff',
} as const;

const reactionButtonStyle = {
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    borderRadius: '4px',
    padding: '4px',
    cursor: 'pointer',
    border: 'none',
    background: 'transparent',
    transition: 'background-color 160ms ease, color 160ms ease, opacity 160ms ease',
} as const;

const spinnerStyle = {
    width: '16px',
    height: '16px',
    border: '2px solid rgba(255, 255, 255, 0.35)',
    borderTopColor: '#ffffff',
    borderRadius: '999px',
    animation: 'atlas-badge-spin 0.9s linear infinite',
} as const;

const reactionPalette: Record<BadgeReactionType, { activeBackground: string; hoverColor: string }> = {
    love: {
        activeBackground: '#ef4444',
        hoverColor: '#f87171',
    },
    like: {
        activeBackground: '#0466c8',
        hoverColor: '#0f85fa',
    },
    funny: {
        activeBackground: '#eab308',
        hoverColor: '#facc15',
    },
};

const closeTabAfterQueueModeLabel: Record<CloseTabAfterQueueMode, string> = {
    off: 'Off',
    queued: 'Queued',
    completed: 'Complete',
};

function renderTabCount(model: BadgeViewModel): VNode {
    const tabCount = formatTabCountSummary(model.openTabCount === null
        ? null
        : {
            similarDomainCount: model.similarDomainTabCount,
            totalCount: model.openTabCount,
        });

    return h(
        'span',
        {
            title: `Tabs ${tabCount}`,
            'aria-label': `Tabs ${tabCount}`,
            style: {
                display: 'inline-flex',
                alignItems: 'center',
                gap: '3px',
                opacity: 0.92,
            },
        },
        [
            h(Layers, { size: 12, strokeWidth: 2 }),
            h('span', tabCount),
        ],
    );
}

function renderCloseTabAfterQueueControl(model: BadgeViewModel, handlers: BadgeViewHandlers): VNode {
    return h(
        'div',
        {
            style: {
                display: 'inline-flex',
                alignItems: 'center',
                gap: '4px',
                opacity: 0.92,
            },
        },
        [
            h('span', 'Auto-close'),
            h(
                'button',
                {
                    type: 'button',
                    onClick: handlers.onCloseTabAfterQueueToggle,
                    disabled: model.closeTabAfterQueueSaving,
                    style: {
                        borderRadius: '999px',
                        border: 'none',
                        padding: '2px 7px',
                        fontSize: '10px',
                        fontWeight: 700,
                        lineHeight: '1.2',
                        cursor: model.closeTabAfterQueueSaving ? 'not-allowed' : 'pointer',
                        opacity: model.closeTabAfterQueueSaving ? 0.7 : 1,
                        background: model.closeTabAfterQueueMode === 'off' ? 'rgba(255,255,255,0.22)' : '#14b8a6',
                        color: model.closeTabAfterQueueMode === 'off' ? '#ffffff' : '#052e2b',
                    },
                },
                model.closeTabAfterQueueSaving
                    ? 'Saving...'
                    : closeTabAfterQueueModeLabel[model.closeTabAfterQueueMode],
            ),
        ],
    );
}

function renderProgressBorder(model: BadgeViewModel): VNode {
    return h(
        'div',
        {
            style: {
                position: 'absolute',
                left: 0,
                right: 0,
                bottom: 0,
                height: '13px',
                borderRadius: '0 0 8px 8px',
                background: 'rgba(255,255,255,0.16)',
                overflow: 'hidden',
            },
        },
        [
            h('div', {
                style: {
                    position: 'absolute',
                    inset: '0 auto 0 0',
                    width: `${model.progressDisplayValue}%`,
                    background: model.progressColor,
                    transition: 'width 180ms ease',
                },
            }),
            h(
                'div',
                {
                    style: {
                        position: 'relative',
                        zIndex: 1,
                        height: '100%',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: '0 8px',
                        fontSize: '9px',
                        fontWeight: 700,
                        lineHeight: '1',
                        color: '#ffffff',
                        textShadow: '0 1px 2px rgba(0, 0, 0, 0.85)',
                        whiteSpace: 'nowrap',
                    },
                },
                `${model.transferStatus ?? 'idle'} · ${model.progressDisplayValue}%`,
            ),
        ],
    );
}

function iconColor(type: BadgeReactionType, model: BadgeViewModel): string {
    if (model.activeReaction === type) {
        return '#ffffff';
    }

    if (model.hoveredReaction === type && !model.controlsDisabled) {
        return reactionPalette[type].hoverColor;
    }

    return '#ffffff';
}

function renderReactionButton(reactionType: BadgeReactionType, model: BadgeViewModel, handlers: BadgeViewHandlers): VNode {
    const IconComponent = reactionIconByType[reactionType];
    const isSubmittingThisReaction = model.submittingReactionType === reactionType;

    return h(
        'button',
        {
            type: 'button',
            disabled: model.controlsDisabled,
            onClick: () => {
                handlers.onReactionClick(reactionType);
            },
            onMouseenter: () => {
                handlers.onReactionHover(reactionType);
            },
            onMouseleave: () => {
                if (model.hoveredReaction === reactionType) {
                    handlers.onReactionHover(null);
                }
            },
            style: {
                ...reactionButtonStyle,
                background: model.activeReaction === reactionType
                    ? reactionPalette[reactionType].activeBackground
                    : 'transparent',
                opacity: model.controlsDisabled ? 0.75 : 1,
                cursor: model.controlsDisabled ? 'not-allowed' : 'pointer',
            },
        },
        [
            isSubmittingThisReaction
                ? h(Loader2, {
                    ...iconBaseStyle,
                    style: {
                        animation: 'atlas-badge-spin 0.9s linear infinite',
                    },
                })
                : h(IconComponent, {
                    ...iconBaseStyle,
                    color: iconColor(reactionType, model),
                }),
        ],
    );
}

function renderBlacklistButton(model: BadgeViewModel, handlers: BadgeViewHandlers): VNode {
    const disabled = model.controlsDisabled || model.isBlacklisted;

    return h(
        'button',
        {
            type: 'button',
            disabled,
            'aria-label': 'Blacklist',
            'aria-pressed': model.isBlacklisted,
            onClick: handlers.onBlacklistClick,
            style: {
                ...reactionButtonStyle,
                background: model.isBlacklisted ? '#dc2626' : 'transparent',
                opacity: disabled ? 0.75 : 1,
                cursor: disabled ? 'not-allowed' : 'pointer',
            },
        },
        [
            model.submittingBlacklist
                ? h(Loader2, {
                    ...iconBaseStyle,
                    style: {
                        animation: 'atlas-badge-spin 0.9s linear infinite',
                    },
                })
                : h(Ban, {
                    ...iconBaseStyle,
                    color: '#ffffff',
                }),
        ],
    );
}

function renderIconRow(model: BadgeViewModel, handlers: BadgeViewHandlers): VNode {
    if (model.isChecking) {
        return h(
            'div',
            {
                style: {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    width: '100%',
                },
            },
            [h('span', { style: spinnerStyle })],
        );
    }

    return h(
        'div',
        {
            style: {
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: '8px',
                width: '100%',
            },
        },
        [
            renderReactionButton('love', model, handlers),
            renderReactionButton('like', model, handlers),
            renderBlacklistButton(model, handlers),
            renderReactionButton('funny', model, handlers),
            renderAtlasFileLink(model),
            renderDeleteFileButton(model, handlers),
        ],
    );
}

function renderReactAllItemsRow(model: BadgeViewModel, handlers: BadgeViewHandlers): VNode | null {
    if (!model.showReactAllItemsInPost) {
        return null;
    }

    return h(
        'label',
        {
            style: {
                width: '100%',
                marginTop: '1px',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: '8px',
                fontSize: '10px',
                opacity: model.controlsDisabled ? 0.75 : 0.92,
                cursor: model.controlsDisabled ? 'not-allowed' : 'pointer',
                userSelect: 'none',
            },
        },
        [
            h('span', 'React all items in post'),
            h('input', {
                type: 'checkbox',
                checked: model.reactAllItemsInPost,
                disabled: model.controlsDisabled,
                onChange: handlers.onReactAllItemsInPostToggle,
                style: {
                    width: '12px',
                    height: '12px',
                    margin: 0,
                    accentColor: '#14b8a6',
                    cursor: model.controlsDisabled ? 'not-allowed' : 'pointer',
                },
            }),
        ],
    );
}

export function renderReactionBadge(model: BadgeViewModel, handlers: BadgeViewHandlers): VNode {
    return h(
        'div',
        {
            style: {
                position: 'relative',
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                borderRadius: '8px',
                background: 'rgba(0, 0, 0, 0.6)',
                backdropFilter: 'blur(4px)',
                color: '#ffffff',
                pointerEvents: 'auto',
                gap: '4px',
                padding: '6px 8px 17px',
                width: '300px',
                minWidth: '300px',
                maxWidth: '300px',
                overflow: 'hidden',
            },
        },
        [
            h(
                'div',
                {
                    style: {
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        width: '100%',
                        gap: '6px',
                        fontSize: '11px',
                        lineHeight: '1.2',
                        whiteSpace: 'nowrap',
                    },
                },
                [
                    model.mediaResolution
                        ? h('span', {
                            style: {
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                            },
                        }, model.mediaResolution)
                        : h('span', {
                            style: {
                                width: '72px',
                                height: '12px',
                                borderRadius: '999px',
                                background: 'rgba(255, 255, 255, 0.2)',
                                animation: 'atlas-badge-pulse 1.4s ease-in-out infinite',
                            },
                        }),
                    h(
                        'span',
                        {
                            style: {
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'flex-end',
                                gap: '8px',
                                flexShrink: 0,
                            },
                        },
                        [
                            renderTabCount(model),
                            renderCloseTabAfterQueueControl(model, handlers),
                        ],
                    ),
                    model.timestampText === null
                        ? null
                        : h(
                            'span',
                            {
                                style: {
                                    opacity: 0.9,
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: '4px',
                                },
                            },
                            [
                                h(model.timestampText.icon, { size: 12, strokeWidth: 2 }),
                                h('span', model.timestampText.text),
                            ],
                        ),
                ],
            ),
            renderIconRow(model, handlers),
            renderReactAllItemsRow(model, handlers),
            renderProgressBorder(model),
        ],
    );
}
