import type { PropType } from 'vue';
import { computed, createApp, defineComponent, h, onBeforeUnmount, onMounted, ref } from 'vue';
import { Ban, Download, Heart, Loader2, Smile, ThumbsDown, ThumbsUp } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import { resolveMediaResolution, resolveMediaUrl, type MediaElement } from './media-utils';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';
import { fetchTransferStatus, submitBadgeReaction } from './reaction-submit';
import { subscribeToDownloadProgress } from './download-progress-bus';

type MountedBadge = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeReactionType) => void;
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

function isTerminalStatus(status: string | null): boolean {
    if (status === null) {
        return false;
    }

    return status === 'completed' || status === 'failed' || status === 'canceled';
}

const AtlasReactionBadge = defineComponent({
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
        ensureRuntimeStyles();

        const isChecking = ref(true);
        const matchResult = ref<BadgeMatchResult>(emptyMatchResult());
        const mediaResolution = ref<string | null>(null);
        const hoveredReaction = ref<BadgeReactionType | null>(null);
        const submittingReactionType = ref<BadgeReactionType | null>(null);
        const isDownloadLocked = ref(false);
        const progressPercent = ref<number | null>(null);
        const transferStatus = ref<string | null>(null);
        const trackedFileId = ref<number | null>(null);
        const trackedTransferId = ref<number | null>(null);
        const hasSeenActiveTransfer = ref(false);

        let isActive = true;
        let unsubscribeProgress: (() => void) | null = null;
        let fallbackPollTimer: ReturnType<typeof setInterval> | null = null;
        let fallbackPollStopsAt = 0;

        const controlsDisabled = computed(() =>
            isChecking.value || submittingReactionType.value !== null || isDownloadLocked.value);
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

        function ensureProgressSubscription(): void {
            if (unsubscribeProgress) {
                return;
            }

            unsubscribeProgress = subscribeToDownloadProgress((event) => {
                if (!isActive) {
                    return;
                }

                if (trackedTransferId.value !== null) {
                    if (event.transferId !== trackedTransferId.value) {
                        return;
                    }
                } else if (trackedFileId.value !== null) {
                    if (event.fileId !== trackedFileId.value) {
                        return;
                    }

                    if (event.transferId !== null) {
                        trackedTransferId.value = event.transferId;
                    }
                } else {
                    return;
                }

                if (event.percent !== null) {
                    progressPercent.value = Math.max(0, Math.min(100, Math.round(event.percent)));
                }
                if (event.status !== null) {
                    transferStatus.value = event.status;
                    if (!isTerminalStatus(event.status)) {
                        hasSeenActiveTransfer.value = true;
                    }
                }

                if (event.status !== null && isTerminalStatus(event.status)) {
                    handleTerminalUnlock();
                }
            });
        }

        function stopFallbackPolling(): void {
            if (fallbackPollTimer !== null) {
                clearInterval(fallbackPollTimer);
                fallbackPollTimer = null;
            }
            fallbackPollStopsAt = 0;
        }

        function handleTerminalUnlock(): void {
            isDownloadLocked.value = false;
            submittingReactionType.value = null;
            hasSeenActiveTransfer.value = false;
            stopFallbackPolling();
        }

        function startFallbackPolling(): void {
            if (fallbackPollTimer !== null) {
                return;
            }

            fallbackPollStopsAt = Date.now() + (3 * 60 * 1000);
            fallbackPollTimer = setInterval(() => {
                if (!isActive || !isDownloadLocked.value) {
                    stopFallbackPolling();
                    return;
                }

                if (Date.now() >= fallbackPollStopsAt) {
                    handleTerminalUnlock();
                    return;
                }

                const transferId = trackedTransferId.value;
                void fetchTransferStatus({
                    transferId,
                    fileId: trackedFileId.value,
                }).then((statusResult) => {
                    if (!isActive || !statusResult.ok) {
                        return;
                    }

                    if (statusResult.fileId !== null) {
                        trackedFileId.value = statusResult.fileId;
                    }
                    if (statusResult.transferId !== null) {
                        trackedTransferId.value = statusResult.transferId;
                    }
                    if (statusResult.progressPercent !== null) {
                        progressPercent.value = Math.max(0, Math.min(100, Math.round(statusResult.progressPercent)));
                    }
                    if (statusResult.status !== null) {
                        transferStatus.value = statusResult.status;
                        if (!isTerminalStatus(statusResult.status)) {
                            hasSeenActiveTransfer.value = true;
                        }
                    }

                    if ((statusResult.downloadedAt !== null || statusResult.blacklistedAt !== null) && hasSeenActiveTransfer.value) {
                        matchResult.value = {
                            ...matchResult.value,
                            downloadedAt: statusResult.downloadedAt ?? matchResult.value.downloadedAt,
                            blacklistedAt: statusResult.blacklistedAt ?? matchResult.value.blacklistedAt,
                        };
                        handleTerminalUnlock();
                        return;
                    }

                    if (statusResult.status !== null && isTerminalStatus(statusResult.status)) {
                        handleTerminalUnlock();
                    }
                });
            }, 1200);
        }

        const onMediaUpdate = (): void => {
            syncResolution();
        };

        onMounted(() => {
            syncResolution();
            props.onShortcutReady?.((type: BadgeReactionType) => {
                void handleReactionClick(type);
            });

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
            props.onShortcutReady?.(null);
            props.media.removeEventListener('load', onMediaUpdate);
            props.media.removeEventListener('loadedmetadata', onMediaUpdate);
            props.media.removeEventListener('resize', onMediaUpdate);
            if (unsubscribeProgress) {
                unsubscribeProgress();
                unsubscribeProgress = null;
            }
            stopFallbackPolling();
        });

        function iconColor(type: BadgeReactionType, activeReaction: BadgeReactionType | null): string {
            if (activeReaction === type) {
                return '#ffffff';
            }

            if (hoveredReaction.value === type && !controlsDisabled.value) {
                return reactionPalette[type].hoverColor;
            }

            return '#ffffff';
        }

        async function handleReactionClick(type: BadgeReactionType): Promise<void> {
            if (controlsDisabled.value) {
                return;
            }

            submittingReactionType.value = type;
            hasSeenActiveTransfer.value = false;

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
                trackedFileId.value = result.fileId;
                trackedTransferId.value = result.downloadTransferId;
                transferStatus.value = result.downloadStatus;
                progressPercent.value = result.downloadProgressPercent;
                hasSeenActiveTransfer.value = result.downloadStatus !== null && !isTerminalStatus(result.downloadStatus);

                if (result.downloadRequested) {
                    isDownloadLocked.value = true;
                    ensureProgressSubscription();
                    startFallbackPolling();
                    if (isTerminalStatus(result.downloadStatus)) {
                        handleTerminalUnlock();
                    }
                } else {
                    handleTerminalUnlock();
                }
            } finally {
                if (isActive && !isDownloadLocked.value) {
                    submittingReactionType.value = null;
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
                        const isSubmittingThisReaction = submittingReactionType.value === reactionType;

                        return h(
                            'button',
                            {
                                type: 'button',
                                disabled: controlsDisabled.value,
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
                                    opacity: controlsDisabled.value ? 0.75 : 1,
                                    cursor: controlsDisabled.value ? 'not-allowed' : 'pointer',
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
                                        color: iconColor(reactionType, activeReaction),
                                    }),
                            ],
                        );
                    }),
                );

            const progressDisplayValue = (() => {
                if (progressPercent.value !== null) {
                    return Math.max(0, Math.min(100, Math.round(progressPercent.value)));
                }

                if (transferStatus.value === 'completed') {
                    return 100;
                }

                if (matchResult.value.downloadedAt !== null) {
                    return 100;
                }

                return 0;
            })();
            const progressColor = transferStatus.value === 'completed' || matchResult.value.downloadedAt !== null
                ? '#22c55e'
                : '#14b8a6';

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
                                        width: `${progressDisplayValue}%`,
                                        background: progressColor,
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
                                    h('span', transferStatus.value ?? 'idle'),
                                    h('span', `${progressDisplayValue}%`),
                                ],
                            ),
                        ],
                    ),
                ],
            );
        };
    },
});

export function createReactionBadgeHost(media: MediaElement): MountedBadge {
    const element = document.createElement('div');
    let triggerReactionHandler: ((type: BadgeReactionType) => void) | null = null;
    const app = createApp(AtlasReactionBadge, {
        media,
        onShortcutReady: (handler: ((type: BadgeReactionType) => void) | null) => {
            triggerReactionHandler = handler;
        },
    });
    app.mount(element);

    return {
        element,
        triggerReaction: (type: BadgeReactionType) => {
            triggerReactionHandler?.(type);
        },
        unmount: () => {
            triggerReactionHandler = null;
            app.unmount();
        },
    };
}
