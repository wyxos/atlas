import { h, type VNode } from 'vue';
import { Ban, Download, Heart, Loader2, Smile, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import type { BadgeReactionType } from './reaction-check-queue';

export type BadgeTimestampDisplay = {
    icon: typeof Ban | typeof Download;
    text: string;
} | null;

type BadgeViewModel = {
    isChecking: boolean;
    controlsDisabled: boolean;
    activeReaction: BadgeReactionType | null;
    hoveredReaction: BadgeReactionType | null;
    submittingReactionType: BadgeReactionType | null;
    closeTabAfterQueueEnabled: boolean;
    closeTabAfterQueueSaving: boolean;
    mediaResolution: string | null;
    openTabCount: number | null;
    timestampText: BadgeTimestampDisplay;
    progressDisplayValue: number;
    progressColor: string;
    transferStatus: string | null;
    showReactAllItemsInPost: boolean;
    reactAllItemsInPost: boolean;
};

type BadgeViewHandlers = {
    onReactionClick: (type: BadgeReactionType) => void;
    onReactionHover: (type: BadgeReactionType | null) => void;
    onCloseTabAfterQueueToggle: () => void;
    onReactAllItemsInPostToggle: () => void;
};

const reactionOrder: BadgeReactionType[] = ['love', 'like', 'dislike', 'funny'];

const reactionIconByType = {
    love: Heart,
    like: ThumbsUp,
    dislike: ThumbsDown,
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
    dislike: {
        activeBackground: '#6b7280',
        hoverColor: '#9ca3af',
    },
    funny: {
        activeBackground: '#eab308',
        hoverColor: '#facc15',
    },
};

function iconColor(type: BadgeReactionType, model: BadgeViewModel): string {
    if (model.activeReaction === type) {
        return '#ffffff';
    }

    if (model.hoveredReaction === type && !model.controlsDisabled) {
        return reactionPalette[type].hoverColor;
    }

    return '#ffffff';
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
        reactionOrder.map((reactionType) => {
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
        }),
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
                padding: '6px 8px',
                width: '320px',
                minWidth: '320px',
                maxWidth: '320px',
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
                        gap: '8px',
                        fontSize: '11px',
                        lineHeight: '1.2',
                        whiteSpace: 'nowrap',
                    },
                },
                [
                    model.mediaResolution
                        ? h('span', `Res ${model.mediaResolution}`)
                        : h('span', {
                            style: {
                                width: '72px',
                                height: '12px',
                                borderRadius: '999px',
                                background: 'rgba(255, 255, 255, 0.2)',
                                animation: 'atlas-badge-pulse 1.4s ease-in-out infinite',
                            },
                        }),
                    h('span', model.openTabCount === null ? 'Tabs -' : `Tabs ${model.openTabCount}`),
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
            h(
                'div',
                {
                    style: {
                        width: '100%',
                        marginTop: '1px',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: '8px',
                        fontSize: '10px',
                        opacity: 0.9,
                    },
                },
                [
                    h('span', 'Auto-close after complete'),
                    h(
                        'button',
                        {
                            type: 'button',
                            onClick: handlers.onCloseTabAfterQueueToggle,
                            disabled: model.closeTabAfterQueueSaving,
                            style: {
                                borderRadius: '999px',
                                border: 'none',
                                padding: '3px 8px',
                                fontSize: '10px',
                                fontWeight: 700,
                                cursor: model.closeTabAfterQueueSaving ? 'not-allowed' : 'pointer',
                                opacity: model.closeTabAfterQueueSaving ? 0.7 : 1,
                                background: model.closeTabAfterQueueEnabled ? '#14b8a6' : 'rgba(255,255,255,0.22)',
                                color: model.closeTabAfterQueueEnabled ? '#052e2b' : '#ffffff',
                            },
                        },
                        model.closeTabAfterQueueSaving
                            ? 'Saving...'
                            : model.closeTabAfterQueueEnabled
                                ? 'On'
                                : 'Off',
                    ),
                ],
            ),
            h(
                'div',
                {
                    style: {
                        width: '100%',
                        marginTop: '2px',
                        display: 'flex',
                        flexDirection: 'column',
                        gap: '3px',
                    },
                },
                [
                    h('div', {
                        style: {
                            height: '4px',
                            width: '100%',
                            borderRadius: '999px',
                            background: 'rgba(255,255,255,0.2)',
                            overflow: 'hidden',
                        },
                    }, [
                        h('div', {
                            style: {
                                height: '100%',
                                width: `${model.progressDisplayValue}%`,
                                background: model.progressColor,
                                transition: 'width 180ms ease',
                            },
                        }),
                    ]),
                    h(
                        'div',
                        {
                            style: {
                                fontSize: '10px',
                                opacity: 0.9,
                                textAlign: 'right',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                            },
                        },
                        [
                            h('span', model.transferStatus ?? 'idle'),
                            h('span', `${model.progressDisplayValue}%`),
                        ],
                    ),
                ],
            ),
        ],
    );
}
