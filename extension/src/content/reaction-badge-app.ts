import type { PropType } from 'vue';
import { computed, createApp, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { Ban, Download } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import { normalizeUrl, resolveMediaResolution, resolveMediaUrl, resolveReactionMediaUrl, type MediaElement } from './media-utils';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';
import { submitBadgeReaction } from './reaction-submit';
import { subscribeToDownloadProgress } from './download-progress-bus';
import { renderReactionBadge, type BadgeTimestampDisplay } from './reaction-badge-view';
import { ensureReactionBadgeRuntimeStyles } from './reaction-badge-runtime-style';
import {
    getPersistedBadgeState,
    persistBadgeCheckResult,
    persistBadgeState,
    persistDownloadProgressEvent,
    type PersistedBadgeState,
} from './badge-state-cache';

type MountedBadge = {
    element: HTMLDivElement;
    triggerReaction: (type: BadgeReactionType) => void;
    unmount: () => void;
};

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
        ensureReactionBadgeRuntimeStyles();

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
        const lastCheckedMediaUrl = ref<string | null>(null);
        const lastReactionMediaUrl = ref<string | null>(null);

        let isActive = true;
        let unsubscribeProgress: (() => void) | null = null;
        let mediaMutationObserver: MutationObserver | null = null;
        let checkSequence = 0;

        const controlsDisabled = computed(() =>
            isChecking.value || submittingReactionType.value !== null || isDownloadLocked.value);
        const timestampText = computed<BadgeTimestampDisplay>(() => {
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
                persistDownloadProgressEvent(event);
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

                persistBadgeState(lastReactionMediaUrl.value, {
                    fileId: trackedFileId.value,
                    transferId: trackedTransferId.value,
                    status: transferStatus.value,
                    percent: progressPercent.value,
                    isDownloadLocked: isDownloadLocked.value,
                    downloadedAt: matchResult.value.downloadedAt,
                    blacklistedAt: matchResult.value.blacklistedAt,
                });
            });
        }

        function handleTerminalUnlock(): void {
            isDownloadLocked.value = false;
            submittingReactionType.value = null;
            hasSeenActiveTransfer.value = false;
            persistBadgeState(lastReactionMediaUrl.value, {
                fileId: trackedFileId.value,
                transferId: trackedTransferId.value,
                status: transferStatus.value,
                percent: progressPercent.value,
                isDownloadLocked: false,
                downloadedAt: matchResult.value.downloadedAt,
                blacklistedAt: matchResult.value.blacklistedAt,
            });
        }

        function resetStateForMediaContextChange(): void {
            isChecking.value = true;
            hoveredReaction.value = null;
            matchResult.value = emptyMatchResult();
            submittingReactionType.value = null;
            isDownloadLocked.value = false;
            progressPercent.value = null;
            transferStatus.value = null;
            trackedFileId.value = null;
            trackedTransferId.value = null;
            hasSeenActiveTransfer.value = false;
        }

        function applyPersistedState(snapshot: PersistedBadgeState | null): void {
            if (snapshot === null) {
                return;
            }

            matchResult.value = {
                ...matchResult.value,
                exists: snapshot.exists,
                reaction: snapshot.reaction,
                downloadedAt: snapshot.downloadedAt,
                blacklistedAt: snapshot.blacklistedAt,
            };
            trackedFileId.value = snapshot.fileId;
            trackedTransferId.value = snapshot.transferId;
            transferStatus.value = snapshot.status;
            progressPercent.value = snapshot.percent;
            isDownloadLocked.value = snapshot.isDownloadLocked;
            hasSeenActiveTransfer.value = snapshot.status !== null && !isTerminalStatus(snapshot.status);
        }

        async function refreshMatchForCurrentMedia(force = false): Promise<void> {
            const mediaUrl = resolveMediaUrl(props.media);
            if (!force && mediaUrl === lastCheckedMediaUrl.value) {
                return;
            }

            lastCheckedMediaUrl.value = mediaUrl;
            const reactionMediaUrl = normalizeUrl(resolveReactionMediaUrl(props.media));
            lastReactionMediaUrl.value = reactionMediaUrl;
            const currentSequence = ++checkSequence;
            resetStateForMediaContextChange();
            applyPersistedState(getPersistedBadgeState(reactionMediaUrl));
            if (isDownloadLocked.value) {
                ensureProgressSubscription();
            }

            const result = await enqueueReactionCheck(mediaUrl);
            if (!isActive || currentSequence !== checkSequence) {
                return;
            }

            persistBadgeCheckResult(reactionMediaUrl, result);
            const persistedAfterCheck = getPersistedBadgeState(reactionMediaUrl);
            if (persistedAfterCheck !== null) {
                applyPersistedState(persistedAfterCheck);
            } else {
                matchResult.value = result;
            }
            isChecking.value = false;
        }

        const onMediaUpdate = (): void => {
            syncResolution();
            void refreshMatchForCurrentMedia();
        };

        onMounted(() => {
            syncResolution();
            props.onShortcutReady?.((type: BadgeReactionType) => {
                void handleReactionClick(type);
            });

            props.media.addEventListener('load', onMediaUpdate);
            props.media.addEventListener('loadedmetadata', onMediaUpdate);
            props.media.addEventListener('resize', onMediaUpdate);

            mediaMutationObserver = new MutationObserver(() => {
                syncResolution();
                void refreshMatchForCurrentMedia();
            });

            mediaMutationObserver.observe(props.media, {
                attributes: true,
                attributeFilter: ['src', 'srcset', 'poster'],
                childList: true,
                subtree: true,
            });

            void refreshMatchForCurrentMedia(true);
        });

        onBeforeUnmount(() => {
            isActive = false;
            checkSequence += 1;
            persistBadgeState(lastReactionMediaUrl.value, {
                exists: matchResult.value.exists,
                reaction: matchResult.value.reaction,
                fileId: trackedFileId.value,
                transferId: trackedTransferId.value,
                status: transferStatus.value,
                percent: progressPercent.value,
                isDownloadLocked: isDownloadLocked.value,
                downloadedAt: matchResult.value.downloadedAt,
                blacklistedAt: matchResult.value.blacklistedAt,
            });
            props.onShortcutReady?.(null);
            props.media.removeEventListener('load', onMediaUpdate);
            props.media.removeEventListener('loadedmetadata', onMediaUpdate);
            props.media.removeEventListener('resize', onMediaUpdate);
            if (mediaMutationObserver) {
                mediaMutationObserver.disconnect();
                mediaMutationObserver = null;
            }
            if (unsubscribeProgress) {
                unsubscribeProgress();
                unsubscribeProgress = null;
            }
        });

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
                lastReactionMediaUrl.value = normalizeUrl(resolveReactionMediaUrl(props.media));
                trackedFileId.value = result.fileId;
                trackedTransferId.value = result.downloadTransferId;
                transferStatus.value = result.downloadStatus;
                progressPercent.value = result.downloadProgressPercent;
                hasSeenActiveTransfer.value = result.downloadStatus !== null && !isTerminalStatus(result.downloadStatus);
                persistBadgeState(lastReactionMediaUrl.value, {
                    exists: matchResult.value.exists,
                    reaction: matchResult.value.reaction,
                    fileId: trackedFileId.value,
                    transferId: trackedTransferId.value,
                    status: transferStatus.value,
                    percent: progressPercent.value,
                    isDownloadLocked: result.downloadRequested,
                    downloadedAt: matchResult.value.downloadedAt,
                    blacklistedAt: matchResult.value.blacklistedAt,
                });

                if (result.downloadRequested) {
                    isDownloadLocked.value = true;
                    ensureProgressSubscription();
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

            return renderReactionBadge(
                {
                    isChecking: isChecking.value,
                    controlsDisabled: controlsDisabled.value,
                    activeReaction,
                    hoveredReaction: hoveredReaction.value,
                    submittingReactionType: submittingReactionType.value,
                    mediaResolution: mediaResolution.value,
                    timestampText: timestampText.value,
                    progressDisplayValue,
                    progressColor,
                    transferStatus: transferStatus.value,
                },
                {
                    onReactionClick: (reactionType) => {
                        void handleReactionClick(reactionType);
                    },
                    onReactionHover: (reactionType) => {
                        hoveredReaction.value = reactionType;
                    },
                },
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
