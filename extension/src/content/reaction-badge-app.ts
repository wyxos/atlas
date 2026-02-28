import type { PropType } from 'vue';
import { computed, createApp, defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue';
import { Ban, Download, Heart, Smile, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import { resolveMediaResolution, resolveMediaUrl, type MediaElement } from './media-utils';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';
import { submitBadgeReaction } from './reaction-submit';

type MountedBadge = {
    element: HTMLDivElement;
    unmount: () => void;
};

const BADGE_STYLE_ID = 'atlas-reaction-badge-runtime-style';

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

function ensureRuntimeStyles(): void {
    if (document.getElementById(BADGE_STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = BADGE_STYLE_ID;
    style.textContent = `
@keyframes atlas-badge-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes atlas-badge-pulse { 0%,100% { opacity: .45; } 50% { opacity: .9; } }
`;
    document.head.appendChild(style);
}

function emptyMatchResult(): BadgeMatchResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

const AtlasReactionBadge = defineComponent({
    name: 'AtlasReactionBadge',
    props: {
        media: {
            type: Object as PropType<MediaElement>,
            required: true,
        },
    },
    setup(props) {
        ensureRuntimeStyles();

        const isChecking = ref(true);
        const matchResult = ref<BadgeMatchResult>(emptyMatchResult());
        const mediaResolution = ref<string | null>(null);
        const hoveredReaction = ref<BadgeReactionType | null>(null);
        const isSubmittingReaction = ref(false);
        let isActive = true;
        const timestampText = computed(() => {
            const blacklistedAt = formatMatchTimestamp(matchResult.value.blacklistedAt);
            if (blacklistedAt) {
                return {
                    icon: Ban,
                    text: `- ${blacklistedAt}`,
                };
            }

            const downloadedAt = formatMatchTimestamp(matchResult.value.downloadedAt);
            if (downloadedAt) {
                return {
                    icon: Download,
                    text: `- ${downloadedAt}`,
                };
            }

            return null;
        });

        function syncResolution(): void {
            const resolved = resolveMediaResolution(props.media);
            mediaResolution.value = resolved ? `${resolved.width} x ${resolved.height}` : null;
        }

        const onMediaUpdate = (): void => {
            syncResolution();
        };

        onMounted(() => {
            syncResolution();

            props.media.addEventListener('load', onMediaUpdate);
            props.media.addEventListener('loadedmetadata', onMediaUpdate);
            props.media.addEventListener('resize', onMediaUpdate);

            void enqueueReactionCheck(resolveMediaUrl(props.media)).then((result) => {
                if (!isActive) {
                    return;
                }

                matchResult.value = result;
                isChecking.value = false;
            });
        });

        onBeforeUnmount(() => {
            isActive = false;
            props.media.removeEventListener('load', onMediaUpdate);
            props.media.removeEventListener('loadedmetadata', onMediaUpdate);
            props.media.removeEventListener('resize', onMediaUpdate);
        });

        function iconColor(type: BadgeReactionType, activeReaction: BadgeReactionType | null): string {
            if (activeReaction === type) {
                return '#ffffff';
            }

            if (hoveredReaction.value === type) {
                return reactionPalette[type].hoverColor;
            }

            return '#ffffff';
        }

        async function handleReactionClick(type: BadgeReactionType): Promise<void> {
            if (isSubmittingReaction.value || isChecking.value) {
                return;
            }

            isSubmittingReaction.value = true;

            try {
                const result = await submitBadgeReaction(props.media, type);
                if (!isActive || !result.ok) {
                    return;
                }

                matchResult.value = {
                    ...matchResult.value,
                    exists: result.exists || matchResult.value.exists,
                    reaction: result.reaction,
                };
            } finally {
                if (isActive) {
                    isSubmittingReaction.value = false;
                }
            }
        }

        return () => {
            const activeReaction = matchResult.value.exists ? matchResult.value.reaction : null;
            const iconRow = isChecking.value
                ? h(
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
                )
                : h(
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

                        return h(
                            'button',
                            {
                                type: 'button',
                                onClick: () => {
                                    void handleReactionClick(reactionType);
                                },
                                onMouseenter: () => {
                                    hoveredReaction.value = reactionType;
                                },
                                onMouseleave: () => {
                                    if (hoveredReaction.value === reactionType) {
                                        hoveredReaction.value = null;
                                    }
                                },
                                style: {
                                    ...reactionButtonStyle,
                                    background: activeReaction === reactionType
                                        ? reactionPalette[reactionType].activeBackground
                                        : 'transparent',
                                    opacity: isSubmittingReaction.value ? 0.75 : 1,
                                    cursor: isSubmittingReaction.value ? 'wait' : 'pointer',
                                },
                            },
                            [
                                h(IconComponent, {
                                    ...iconBaseStyle,
                                    color: iconColor(reactionType, activeReaction),
                                }),
                            ],
                        );
                    }),
                );

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
                        minWidth: '172px',
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
                            mediaResolution.value
                                ? h('span', `Res ${mediaResolution.value}`)
                                : h('span', {
                                    style: {
                                        width: '72px',
                                        height: '12px',
                                        borderRadius: '999px',
                                        background: 'rgba(255, 255, 255, 0.2)',
                                        animation: 'atlas-badge-pulse 1.4s ease-in-out infinite',
                                    },
                                }),
                            timestampText.value === null
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
                                        h(timestampText.value.icon, { size: 12, strokeWidth: 2 }),
                                        h('span', timestampText.value.text),
                                    ],
                                ),
                        ],
                    ),
                    iconRow,
                ],
            );
        };
    },
});

export function createReactionBadgeHost(media: MediaElement): MountedBadge {
    const element = document.createElement('div');
    const app = createApp(AtlasReactionBadge, { media });
    app.mount(element);

    return {
        element,
        unmount: () => {
            app.unmount();
        },
    };
}
