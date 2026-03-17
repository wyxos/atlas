import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Ban, Download } from 'lucide-vue-next';
import { formatMatchTimestamp } from './match-timestamp';
import {
    hasRelatedPostThumbnailsBelowMedia,
    resolveIdentifiedMediaResolution,
    type MediaElement,
} from './media-utils';
import { collectDeviantArtBatchReactionItems } from './deviantart-batch-reaction';
import {
    collectCivitAiListingMetadataOverrides,
} from './civitai-reaction-context';
import { enqueueReactionCheck, type BadgeMatchResult, type BadgeReactionType } from './reaction-check-queue';
import { createDownloadedReactionDialog } from './downloaded-reaction-dialog';
import { submitBadgeReaction, type SubmitDownloadBehavior } from './reaction-submit';
import { subscribeToDownloadProgress } from './download-progress-bus';
import type { BadgeTimestampDisplay } from './reaction-badge-view';
import { ensureReactionBadgeRuntimeStyles } from './reaction-badge-runtime-style';
import { requestCloseCurrentTab, requestTabCount, subscribeToTabCountChanged } from './reaction-badge-tab-runtime';
import { resolveReactionBadgeProgressState } from './reaction-badge-progress';
import { emptyMatchResult, isTerminalStatus, preserveTrackedMatchResult, shouldPreserveTrackedTransfer } from './reaction-badge-utils';
import {
    getLatestPersistedStateForTrackedUrls as getTrackedPersistedBadgeState,
    matchingTrackedUrlFromProgressEvent,
    resolvePersistenceUrl as resolveTrackedPersistenceUrl,
    resolveTrackedMediaUrls,
} from './reaction-badge-tracking';
import { queueCloseCurrentTabAfterDownloadComplete } from './reaction-badge-auto-close';
import {
    persistBadgeCheckResult, persistBadgeState,
    persistDownloadProgressEvent, type PersistedBadgeState,
} from './badge-state-cache';
import { useCloseTabAfterQueuePreference } from './close-tab-after-queue-state';
import { useReactAllItemsInPostPreference } from './react-all-items-in-post-state';
type UseReactionBadgeProps = {
    media: MediaElement;
    onShortcutReady?: ((handler: ((type: BadgeReactionType) => void) | null) => void) | undefined;
};
const DEVIANT_ART_HOST_PATTERN = /(^|\.)deviantart\.com$/i;
const CIVITAI_HOST_PATTERN = /(^|\.)civitai\.com$/i;
const RELATED_POST_THUMBNAIL_RETRY_DELAYS_MS = [120, 400, 1000, 2200] as const;
export function useReactionBadge(props: UseReactionBadgeProps) {
    ensureReactionBadgeRuntimeStyles();
    const pageHostname = window.location.hostname.trim().toLowerCase();
    const isDeviantArtPage = DEVIANT_ART_HOST_PATTERN.test(pageHostname);
    const isCivitAiPage = CIVITAI_HOST_PATTERN.test(pageHostname);
    const closeTabAfterQueuePreference = useCloseTabAfterQueuePreference(pageHostname);
    const reactAllItemsInPostPreference = useReactAllItemsInPostPreference(pageHostname);
    const downloadedReactionDialog = createDownloadedReactionDialog();

    const isChecking = ref(true);
    const matchResult = ref<BadgeMatchResult>(emptyMatchResult());
    const mediaResolution = ref<string | null>(null);
    const similarDomainTabCount = ref<number | null>(null);
    const openTabCount = ref<number | null>(null);
    const hoveredReaction = ref<BadgeReactionType | null>(null);
    const submittingReactionType = ref<BadgeReactionType | null>(null);
    const isDownloadLocked = ref(false);
    const progressPercent = ref<number | null>(null);
    const transferStatus = ref<string | null>(null);
    const trackedFileId = ref<number | null>(null);
    const trackedTransferId = ref<number | null>(null);
    const hasSeenActiveTransfer = ref(false);
    const showReactAllItemsInPost = ref(false);
    const lastCheckedMediaUrl = ref<string | null>(null);
    const lastReactionMediaUrl = ref<string | null>(null);
    const trackedMediaUrls = ref<string[]>([]);
    let isActive = true;
    let unsubscribeProgress: (() => void) | null = null;
    let unsubscribeTabCount: (() => void) | null = null;
    let mediaMutationObserver: MutationObserver | null = null;
    let checkSequence = 0;
    let suppressMediaContextUpdates = false;
    let relatedPostThumbnailRetryTimer: number | null = null;
    let relatedPostThumbnailRetryIndex = 0;
    const controlsDisabled = computed(() => isChecking.value
        || submittingReactionType.value !== null || isDownloadLocked.value || reactAllItemsInPostPreference.saving.value);
    const activeReaction = computed(() => (matchResult.value.exists ? matchResult.value.reaction : null));
    const timestampText = computed<BadgeTimestampDisplay>(() => {
        const blacklistedAt = formatMatchTimestamp(matchResult.value.blacklistedAt);
        if (blacklistedAt) {
            return { icon: Ban, text: `- ${blacklistedAt}` };
        }
        const downloadedAt = formatMatchTimestamp(matchResult.value.downloadedAt);
        if (downloadedAt) {
            return { icon: Download, text: `- ${downloadedAt}` };
        }
        return null;
    });
    const progressState = computed(() => resolveReactionBadgeProgressState({
        progressPercent: progressPercent.value, transferStatus: transferStatus.value, downloadedAt: matchResult.value.downloadedAt,
    }));
    function syncTrackedUrlsForCurrentMedia(): void {
        trackedMediaUrls.value = resolveTrackedMediaUrls(props.media, window.location.href);
        lastReactionMediaUrl.value = trackedMediaUrls.value[0] ?? null;
    }
    function syncRelatedPostThumbnailContext(): void {
        const nextVisible = hasRelatedPostThumbnailsBelowMedia(props.media, pageHostname);
        if (!nextVisible && submittingReactionType.value !== null) {
            return;
        }
        showReactAllItemsInPost.value = nextVisible;
        if (nextVisible) {
            clearRelatedPostThumbnailRetry();
        }
    }
    function clearRelatedPostThumbnailRetry(): void {
        if (relatedPostThumbnailRetryTimer !== null) {
            window.clearTimeout(relatedPostThumbnailRetryTimer);
            relatedPostThumbnailRetryTimer = null;
        }
    }
    function scheduleRelatedPostThumbnailRetry(): void {
        if (!isActive || suppressMediaContextUpdates || !isDeviantArtPage || showReactAllItemsInPost.value || relatedPostThumbnailRetryTimer !== null) {
            return;
        }
        const nextDelay = RELATED_POST_THUMBNAIL_RETRY_DELAYS_MS[relatedPostThumbnailRetryIndex];
        if (nextDelay === undefined) {
            return;
        }
        relatedPostThumbnailRetryIndex += 1;
        relatedPostThumbnailRetryTimer = window.setTimeout(() => {
            relatedPostThumbnailRetryTimer = null;
            if (!isActive || suppressMediaContextUpdates) {
                return;
            }
            syncRelatedPostThumbnailContext();
            if (!showReactAllItemsInPost.value) {
                scheduleRelatedPostThumbnailRetry();
            }
        }, nextDelay);
    }
    function restartRelatedPostThumbnailRetry(): void {
        clearRelatedPostThumbnailRetry();
        relatedPostThumbnailRetryIndex = 0;
        scheduleRelatedPostThumbnailRetry();
    }
    function syncResolution(): void {
        const resolved = resolveIdentifiedMediaResolution(props.media);
        mediaResolution.value = resolved ? `${resolved.width} x ${resolved.height}` : null;
    }
    function persistCurrentBadgeState(isLocked: boolean): void {
        persistBadgeState(resolveTrackedPersistenceUrl(lastReactionMediaUrl.value, trackedMediaUrls.value), {
            exists: matchResult.value.exists,
            reaction: matchResult.value.reaction,
            fileId: trackedFileId.value,
            transferId: trackedTransferId.value,
            status: transferStatus.value,
            percent: progressPercent.value,
            isDownloadLocked: isLocked,
            reactedAt: matchResult.value.reactedAt,
            downloadedAt: matchResult.value.downloadedAt,
            blacklistedAt: matchResult.value.blacklistedAt,
        });
    }
    function handleTerminalUnlock(): void {
        isDownloadLocked.value = false;
        submittingReactionType.value = null;
        hasSeenActiveTransfer.value = false;
        persistCurrentBadgeState(false);
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
    function resetStateForMediaContextChange(): void {
        const shouldPreserveActiveTransfer = shouldPreserveTrackedTransfer({
            isDownloadLocked: isDownloadLocked.value,
            trackedFileId: trackedFileId.value,
            trackedTransferId: trackedTransferId.value,
            transferStatus: transferStatus.value,
        });

        isChecking.value = true;
        hoveredReaction.value = null;
        matchResult.value = shouldPreserveActiveTransfer ? preserveTrackedMatchResult(matchResult.value) : emptyMatchResult();
        submittingReactionType.value = null;
        if (!shouldPreserveActiveTransfer) {
            isDownloadLocked.value = false;
            progressPercent.value = null;
            transferStatus.value = null;
            trackedFileId.value = null;
            trackedTransferId.value = null;
            hasSeenActiveTransfer.value = false;
        }
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
            const matchedUrl = matchingTrackedUrlFromProgressEvent(event, trackedMediaUrls.value);
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
            const persisted = getTrackedPersistedBadgeState(trackedMediaUrls.value);
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
            persistCurrentBadgeState(isDownloadLocked.value);
        });
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
        const persistedBeforeCheck = getTrackedPersistedBadgeState(trackedMediaUrls.value);
        if (persistedBeforeCheck !== null) {
            applyPersistedState(persistedBeforeCheck);
        }

        try {
            const result = await enqueueReactionCheck(checkUrl, {
                media: props.media,
                candidatePageUrls: [window.location.href],
            });
            if (!isActive || currentSequence !== checkSequence) {
                return;
            }

            persistBadgeCheckResult(resolveTrackedPersistenceUrl(lastReactionMediaUrl.value, trackedMediaUrls.value), result);
            const persistedAfterCheck = getTrackedPersistedBadgeState(trackedMediaUrls.value);
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
        restartRelatedPostThumbnailRetry();
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
            restartRelatedPostThumbnailRetry();
            void refreshMatchForCurrentMedia();
        });
        mediaMutationObserver.observe(props.media, {
            attributes: true,
            attributeFilter: ['src', 'srcset', 'poster'],
            childList: true,
            subtree: true,
        });

        ensureProgressSubscription();
        unsubscribeTabCount = subscribeToTabCountChanged((snapshot) => {
            similarDomainTabCount.value = snapshot.similarDomainCount;
            openTabCount.value = snapshot.totalCount;
        });
        void requestTabCount().then((snapshot) => {
            similarDomainTabCount.value = snapshot?.similarDomainCount ?? null;
            openTabCount.value = snapshot?.totalCount ?? null;
        });
        syncRelatedPostThumbnailContext();
        restartRelatedPostThumbnailRetry();
        void refreshMatchForCurrentMedia(true);
    });

    onBeforeUnmount(() => {
        isActive = false;
        checkSequence += 1;
        persistCurrentBadgeState(isDownloadLocked.value);
        props.onShortcutReady?.(null);
        props.media.removeEventListener('load', onMediaUpdate);
        props.media.removeEventListener('loadedmetadata', onMediaUpdate);
        props.media.removeEventListener('resize', onMediaUpdate);

        if (mediaMutationObserver) {
            mediaMutationObserver.disconnect();
            mediaMutationObserver = null;
        }
        clearRelatedPostThumbnailRetry();
        downloadedReactionDialog.destroy();
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
            let listingMetadataOverrides = null;
            if (showReactAllItemsInPost.value) {
                await reactAllItemsInPostPreference.refresh();
            }

            if (isCivitAiPage) {
                listingMetadataOverrides = await collectCivitAiListingMetadataOverrides(props.media);
            }

            if (showReactAllItemsInPost.value && reactAllItemsInPostPreference.enabled.value) {
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

            const usesBatchEndpoint = (batchItems?.length ?? 0) >= 2;
            let downloadBehavior: SubmitDownloadBehavior | undefined;
            if (!usesBatchEndpoint && type !== 'dislike' && matchResult.value.downloadedAt !== null) {
                const choice = await downloadedReactionDialog.prompt();
                if (choice === 'cancel') {
                    return;
                }

                downloadBehavior = choice === 'redownload' ? 'force' : 'skip';
            }

            const result = await submitBadgeReaction(props.media, type, {
                ...(batchItems !== null ? { batchItems } : {}),
                ...(listingMetadataOverrides !== null ? { listingMetadataOverrides } : {}),
                ...(downloadBehavior !== undefined ? { downloadBehavior } : {}),
            });
            const closeTabAfterQueueMode = closeTabAfterQueuePreference.mode.value;
            const shouldAutoCloseCurrentTab = result.ok
                && closeTabAfterQueueMode !== 'off'
                && result.shouldCloseTabAfterQueue;

            // DeviantArt gallery navigation can replace the active media node and unmount this
            // badge instance before the batch submit resolves. The tab-close side effect still
            // belongs to the successful submit, even if this component instance is now stale.
            if (shouldAutoCloseCurrentTab) {
                if (type === 'dislike' || closeTabAfterQueueMode === 'queued') {
                    void requestCloseCurrentTab();
                } else {
                    queueCloseCurrentTabAfterDownloadComplete(result.downloadCloseTargets);
                }
            }
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
            persistCurrentBadgeState(result.downloadRequested);

            if (result.downloadRequested) {
                isDownloadLocked.value = true;
                ensureProgressSubscription();
                if (isTerminalStatus(result.downloadStatus)) {
                    handleTerminalUnlock();
                }
                return;
            }

            handleTerminalUnlock();
        } finally {
            if (isActive && !isDownloadLocked.value) {
                submittingReactionType.value = null;
            }
        }
    }

    async function handleReactAllItemsInPostToggle(): Promise<void> {
        if (!showReactAllItemsInPost.value || controlsDisabled.value) {
            return;
        }

        await reactAllItemsInPostPreference.toggle();
    }

    return {
        activeReaction,
        closeTabAfterQueueMode: closeTabAfterQueuePreference.mode,
        controlsDisabled,
        handleReactAllItemsInPostToggle,
        handleReactionClick,
        hoveredReaction,
        isChecking,
        isSavingCloseTabAfterQueuePreference: closeTabAfterQueuePreference.saving,
        mediaResolution,
        openTabCount,
        similarDomainTabCount,
        progressState,
        reactAllItemsInPost: reactAllItemsInPostPreference.enabled,
        showReactAllItemsInPost,
        submittingReactionType,
        timestampText,
        cycleCloseTabAfterQueuePreference: closeTabAfterQueuePreference.cycleMode,
        transferStatus,
    };
}
