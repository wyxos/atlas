import type { PropType } from 'vue';
import { computed, createApp, defineComponent, onBeforeUnmount, onMounted, ref } from 'vue';
import { Ban, Download } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import {
    hasRelatedPostThumbnailsBelowMedia,
    normalizeUrl,
    resolveIdentifiedMediaResolution,
    resolveMediaUrl,
    resolveReactionTargetUrl,
    type MediaElement,
} from './media-utils';
import { collectDeviantArtBatchReactionItems } from './deviantart-batch-reaction';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';
import { submitBadgeReaction } from './reaction-submit';
import { subscribeToDownloadProgress, type ProgressEvent } from './download-progress-bus';
import { renderReactionBadge, type BadgeTimestampDisplay } from './reaction-badge-view';
import { ensureReactionBadgeRuntimeStyles } from './reaction-badge-runtime-style';
import {
    getCloseTabAfterQueuePreferenceForHostname,
    setCloseTabAfterQueuePreferenceForHostname,
} from '../atlas-options';
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

type TabCountChangedListener = (count: number) => void;

const tabCountChangedListeners = new Set<TabCountChangedListener>();
let tabCountRuntimeBound = false;

function toSafeCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : 0;
}

function subscribeToTabCountChanged(listener: TabCountChangedListener): () => void {
    tabCountChangedListeners.add(listener);

    if (!tabCountRuntimeBound && chrome.runtime?.onMessage) {
        chrome.runtime.onMessage.addListener((message: unknown) => {
            if (typeof message !== 'object' || message === null) {
                return;
            }

            const payload = message as { type?: unknown; count?: unknown };
            if (payload.type !== 'ATLAS_TAB_COUNT_CHANGED') {
                return;
            }

            const count = toSafeCount(payload.count);
            tabCountChangedListeners.forEach((tabListener) => {
                tabListener(count);
            });
        });
        tabCountRuntimeBound = true;
    }

    return () => {
        tabCountChangedListeners.delete(listener);
    };
}

async function requestTabCount(): Promise<number | null> {
    if (!chrome.runtime?.sendMessage) {
        return null;
    }

    return new Promise((resolve) => {
        chrome.runtime.sendMessage({ type: 'ATLAS_GET_TAB_COUNT' }, (response: unknown) => {
            if (chrome.runtime.lastError) {
                resolve(null);
                return;
            }

            if (typeof response !== 'object' || response === null) {
                resolve(0);
                return;
            }

            resolve(toSafeCount((response as { count?: unknown }).count));
        });
    });
}

async function requestCloseCurrentTab(): Promise<void> {
    if (!chrome.runtime?.sendMessage) {
        return;
    }

    await new Promise<void>((resolve) => {
        chrome.runtime.sendMessage({ type: 'ATLAS_CLOSE_CURRENT_TAB' }, () => {
            void chrome.runtime.lastError;
            resolve();
        });
    });
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
        const openTabCount = ref<number | null>(null);
        const hoveredReaction = ref<BadgeReactionType | null>(null);
        const submittingReactionType = ref<BadgeReactionType | null>(null);
        const isDownloadLocked = ref(false);
        const closeTabAfterQueueEnabled = ref(false);
        const isSavingCloseTabAfterQueuePreference = ref(false);
        const progressPercent = ref<number | null>(null);
        const transferStatus = ref<string | null>(null);
        const trackedFileId = ref<number | null>(null);
        const trackedTransferId = ref<number | null>(null);
        const hasSeenActiveTransfer = ref(false);
        const showReactAllItemsInPost = ref(false);
        const reactAllItemsInPost = ref(false);
        const lastCheckedMediaUrl = ref<string | null>(null);
        const lastReactionMediaUrl = ref<string | null>(null);
        const trackedMediaUrls = ref<string[]>([]);

        let isActive = true;
        let unsubscribeProgress: (() => void) | null = null;
        let unsubscribeTabCount: (() => void) | null = null;
        let mediaMutationObserver: MutationObserver | null = null;
        let checkSequence = 0;
        let suppressMediaContextUpdates = false;
        const pageHostname = window.location.hostname.trim().toLowerCase();

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

        function resolveTrackedUrlsForCurrentMedia(): string[] {
            const pageUrl = normalizeUrl(window.location.href);
            const reactionTargetUrl = resolveReactionTargetUrl(props.media, pageUrl);
            const mediaUrl = normalizeUrl(resolveMediaUrl(props.media));
            const urls = [
                reactionTargetUrl,
                mediaUrl,
                props.media instanceof HTMLVideoElement ? pageUrl : null,
            ].filter((url): url is string => url !== null);

            return Array.from(new Set(urls));
        }

        function syncTrackedUrlsForCurrentMedia(): void {
            trackedMediaUrls.value = resolveTrackedUrlsForCurrentMedia();
            lastReactionMediaUrl.value = trackedMediaUrls.value[0] ?? null;
        }

        function syncRelatedPostThumbnailContext(): void {
            const nextVisible = hasRelatedPostThumbnailsBelowMedia(props.media);
            showReactAllItemsInPost.value = nextVisible;
            if (!nextVisible) {
                reactAllItemsInPost.value = false;
            }
        }

        function resolvePersistenceUrl(): string | null {
            return lastReactionMediaUrl.value ?? trackedMediaUrls.value[0] ?? null;
        }

        function getLatestPersistedStateForTrackedUrls(): PersistedBadgeState | null {
            let latest: PersistedBadgeState | null = null;

            for (const url of trackedMediaUrls.value) {
                const snapshot = getPersistedBadgeState(url);
                if (snapshot === null) {
                    continue;
                }

                if (latest === null || snapshot.updatedAt > latest.updatedAt) {
                    latest = snapshot;
                }
            }

            return latest;
        }

        function matchingUrlFromProgressEvent(event: ProgressEvent): string | null {
            if (trackedMediaUrls.value.length === 0) {
                return null;
            }

            const candidate = normalizeUrl(event.sourceUrl);
            if (candidate !== null && trackedMediaUrls.value.includes(candidate)) {
                return candidate;
            }

            return null;
        }

        function syncResolution(): void {
            const resolved = resolveIdentifiedMediaResolution(props.media);
            mediaResolution.value = resolved ? `${resolved.width} x ${resolved.height}` : null;
        }

        async function loadCloseTabAfterQueuePreference(): Promise<void> {
            if (pageHostname === '') {
                closeTabAfterQueueEnabled.value = false;
                return;
            }

            try {
                closeTabAfterQueueEnabled.value = await getCloseTabAfterQueuePreferenceForHostname(pageHostname);
            } catch {
                closeTabAfterQueueEnabled.value = false;
            }
        }

        async function toggleCloseTabAfterQueuePreference(): Promise<void> {
            if (isSavingCloseTabAfterQueuePreference.value || pageHostname === '') {
                return;
            }

            const nextEnabled = !closeTabAfterQueueEnabled.value;
            closeTabAfterQueueEnabled.value = nextEnabled;
            isSavingCloseTabAfterQueuePreference.value = true;

            try {
                await setCloseTabAfterQueuePreferenceForHostname(pageHostname, nextEnabled);
            } catch {
                closeTabAfterQueueEnabled.value = !nextEnabled;
            } finally {
                isSavingCloseTabAfterQueuePreference.value = false;
            }
        }

        async function refreshOpenTabCount(): Promise<void> {
            openTabCount.value = await requestTabCount();
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

                const transferMatches = trackedTransferId.value !== null && event.transferId === trackedTransferId.value;
                const fileMatches = trackedFileId.value !== null && event.fileId === trackedFileId.value;
                const matchedUrl = matchingUrlFromProgressEvent(event);

                if (!transferMatches && !fileMatches && matchedUrl === null) {
                    return;
                }

                if (matchedUrl !== null) {
                    lastReactionMediaUrl.value = matchedUrl;
                }

                if (event.fileId !== null) {
                    trackedFileId.value = event.fileId;
                }
                if (event.transferId !== null) {
                    trackedTransferId.value = event.transferId;
                }

                const priorReaction = matchResult.value.reaction;
                const priorExists = matchResult.value.exists;
                const persisted = getLatestPersistedStateForTrackedUrls();
                if (persisted !== null) {
                    applyPersistedState(persisted);
                    if (persisted.reaction === null && priorReaction !== null) {
                        matchResult.value = {
                            ...matchResult.value,
                            exists: persisted.exists || priorExists,
                            reaction: priorReaction,
                        };
                    }
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

                persistBadgeState(resolvePersistenceUrl(), {
                    fileId: trackedFileId.value,
                    transferId: trackedTransferId.value,
                    status: transferStatus.value,
                    percent: progressPercent.value,
                    isDownloadLocked: isDownloadLocked.value,
                    reactedAt: matchResult.value.reactedAt,
                    downloadedAt: matchResult.value.downloadedAt,
                    blacklistedAt: matchResult.value.blacklistedAt,
                });
            });
        }

        function handleTerminalUnlock(): void {
            isDownloadLocked.value = false;
            submittingReactionType.value = null;
            hasSeenActiveTransfer.value = false;
            persistBadgeState(resolvePersistenceUrl(), {
                fileId: trackedFileId.value,
                transferId: trackedTransferId.value,
                status: transferStatus.value,
                percent: progressPercent.value,
                isDownloadLocked: false,
                reactedAt: matchResult.value.reactedAt,
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

            const hasReaction = snapshot.reaction !== null;
            matchResult.value = {
                ...matchResult.value,
                exists: snapshot.exists || hasReaction,
                reaction: snapshot.reaction,
                reactedAt: snapshot.reactedAt,
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
            syncTrackedUrlsForCurrentMedia();
            const checkUrl = trackedMediaUrls.value[0] ?? null;
            if (!force && checkUrl === lastCheckedMediaUrl.value) {
                return;
            }

            lastCheckedMediaUrl.value = checkUrl;
            const currentSequence = ++checkSequence;
            resetStateForMediaContextChange();
            const persistedBeforeCheck = getLatestPersistedStateForTrackedUrls();
            if (persistedBeforeCheck !== null) {
                applyPersistedState(persistedBeforeCheck);
            }

            try {
                const result = await enqueueReactionCheck(checkUrl);
                if (!isActive || currentSequence !== checkSequence) {
                    return;
                }

                persistBadgeCheckResult(resolvePersistenceUrl(), result);
                const persistedAfterCheck = getLatestPersistedStateForTrackedUrls();
                if (persistedAfterCheck !== null) {
                    applyPersistedState(persistedAfterCheck);
                } else {
                    matchResult.value = result;
                }
            } catch {
                if (!isActive || currentSequence !== checkSequence) {
                    return;
                }

                matchResult.value = emptyMatchResult();
            } finally {
                if (isActive && currentSequence === checkSequence) {
                    isChecking.value = false;
                }
            }
        }

        const onMediaUpdate = (): void => {
            if (suppressMediaContextUpdates) {
                return;
            }

            syncResolution();
            syncRelatedPostThumbnailContext();
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
                if (suppressMediaContextUpdates) {
                    return;
                }

                syncResolution();
                syncRelatedPostThumbnailContext();
                void refreshMatchForCurrentMedia();
            });

            mediaMutationObserver.observe(props.media, {
                attributes: true,
                attributeFilter: ['src', 'srcset', 'poster'],
                childList: true,
                subtree: true,
            });

            ensureProgressSubscription();
            unsubscribeTabCount = subscribeToTabCountChanged((count) => {
                openTabCount.value = count;
            });
            void refreshOpenTabCount();
            void loadCloseTabAfterQueuePreference();
            syncRelatedPostThumbnailContext();
            void refreshMatchForCurrentMedia(true);
        });

        onBeforeUnmount(() => {
            isActive = false;
            checkSequence += 1;
            persistBadgeState(resolvePersistenceUrl(), {
                exists: matchResult.value.exists,
                reaction: matchResult.value.reaction,
                fileId: trackedFileId.value,
                transferId: trackedTransferId.value,
                status: transferStatus.value,
                percent: progressPercent.value,
                isDownloadLocked: isDownloadLocked.value,
                reactedAt: matchResult.value.reactedAt,
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
            if (unsubscribeTabCount) {
                unsubscribeTabCount();
                unsubscribeTabCount = null;
            }
        });

        async function handleReactionClick(type: BadgeReactionType): Promise<void> {
            if (controlsDisabled.value) {
                return;
            }

            submittingReactionType.value = type;
            hasSeenActiveTransfer.value = false;

            try {
                let batchItems = null;
                if (reactAllItemsInPost.value) {
                    suppressMediaContextUpdates = true;
                    try {
                        batchItems = await collectDeviantArtBatchReactionItems(props.media, {
                            hostname: pageHostname,
                        });
                    } catch {
                        batchItems = null;
                    } finally {
                        suppressMediaContextUpdates = false;
                        syncResolution();
                        syncRelatedPostThumbnailContext();
                    }
                }

                const result = await submitBadgeReaction(props.media, type, {
                    batchItems,
                });
                if (!isActive || !result.ok) {
                    return;
                }

                matchResult.value = {
                    ...matchResult.value,
                    exists: result.exists || matchResult.value.exists,
                    reaction: result.reaction,
                };
                syncTrackedUrlsForCurrentMedia();
                trackedFileId.value = result.fileId;
                trackedTransferId.value = result.downloadTransferId;
                transferStatus.value = result.downloadStatus;
                progressPercent.value = result.downloadProgressPercent;
                hasSeenActiveTransfer.value = result.downloadStatus !== null && !isTerminalStatus(result.downloadStatus);
                persistBadgeState(resolvePersistenceUrl(), {
                    exists: matchResult.value.exists,
                    reaction: matchResult.value.reaction,
                    fileId: trackedFileId.value,
                    transferId: trackedTransferId.value,
                    status: transferStatus.value,
                    percent: progressPercent.value,
                    isDownloadLocked: result.downloadRequested,
                    reactedAt: matchResult.value.reactedAt,
                    downloadedAt: matchResult.value.downloadedAt,
                    blacklistedAt: matchResult.value.blacklistedAt,
                });

                if (result.downloadRequested) {
                    isDownloadLocked.value = true;
                    ensureProgressSubscription();
                    if (isTerminalStatus(result.downloadStatus)) {
                        handleTerminalUnlock();
                    }

                    if (closeTabAfterQueueEnabled.value) {
                        void requestCloseCurrentTab();
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

        function handleReactAllItemsInPostToggle(): void {
            if (!showReactAllItemsInPost.value || controlsDisabled.value) {
                return;
            }

            reactAllItemsInPost.value = !reactAllItemsInPost.value;
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
                    closeTabAfterQueueEnabled: closeTabAfterQueueEnabled.value,
                    closeTabAfterQueueSaving: isSavingCloseTabAfterQueuePreference.value,
                    mediaResolution: mediaResolution.value,
                    openTabCount: openTabCount.value,
                    timestampText: timestampText.value,
                    progressDisplayValue,
                    progressColor,
                    transferStatus: transferStatus.value,
                    showReactAllItemsInPost: showReactAllItemsInPost.value,
                    reactAllItemsInPost: reactAllItemsInPost.value,
                },
                {
                    onReactionClick: (reactionType) => {
                        void handleReactionClick(reactionType);
                    },
                    onReactionHover: (reactionType) => {
                        hoveredReaction.value = reactionType;
                    },
                    onCloseTabAfterQueueToggle: () => {
                        void toggleCloseTabAfterQueuePreference();
                    },
                    onReactAllItemsInPostToggle: () => {
                        handleReactAllItemsInPostToggle();
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
