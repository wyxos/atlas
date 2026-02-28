import type { PropType } from 'vue';
import { computed, createApp, defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue';
import { Heart, Smile, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import { resolveMediaResolution, resolveMediaUrl, type MediaElement } from './media-utils';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';

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
} as const;

const spinnerStyle = {
    width: '16px',
    height: '16px',
    border: '2px solid rgba(255, 255, 255, 0.35)',
    borderTopColor: '#ffffff',
    borderRadius: '999px',
    animation: 'atlas-badge-spin 0.9s linear infinite',
} as const;

function ensureRuntimeStyles(): void {
    if (document.getElementById(BADGE_STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = BADGE_STYLE_ID;
    style.textContent = '@keyframes atlas-badge-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
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
        const mediaResolution = ref('');
        let isActive = true;
        const timestampText = computed(() => {
            const blacklistedAt = formatMatchTimestamp(matchResult.value.blacklistedAt);
            if (blacklistedAt) {
                return `Blacklisted ${blacklistedAt}`;
            }

            const downloadedAt = formatMatchTimestamp(matchResult.value.downloadedAt);
            if (downloadedAt) {
                return `Downloaded ${downloadedAt}`;
            }

            return '';
        });

        function syncResolution(): void {
            const resolved = resolveMediaResolution(props.media);
            mediaResolution.value = resolved ? `${resolved.width} x ${resolved.height}` : 'Unknown';
        }

        const onMediaUpdate = (): void => {
            syncResolution();
        };

        onMounted(() => {
            syncResolution();

            props.media.addEventListener('load', onMediaUpdate);
            props.media.addEventListener('loadedmetadata', onMediaUpdate);
            props.media.addEventListener('resize', onMediaUpdate);

            void enqueueReactionCheck(resolveMediaUrl(props.media), window.location.href).then((result) => {
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
            if (activeReaction !== type) {
                return '#ffffff';
            }

            if (type === 'dislike') {
                return '#f87171';
            }

            return '#4ade80';
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
                                style: {
                                    ...reactionButtonStyle,
                                    border: activeReaction === reactionType ? '1px solid rgba(255,255,255,0.8)' : '1px solid transparent',
                                    background: activeReaction === reactionType ? 'rgba(255,255,255,0.18)' : 'transparent',
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
                            h('span', `Res ${mediaResolution.value}`),
                            timestampText.value === ''
                                ? null
                                : h('span', { style: { opacity: 0.9 } }, timestampText.value),
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
