import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
import type { BadgeTimestampDisplay } from './reaction-badge-view';
import { ensureReactionBadgeRuntimeStyles } from './reaction-badge-runtime-style';
import { requestCloseCurrentTab, requestTabCount, subscribeToTabCountChanged } from './reaction-badge-tab-runtime';
import { resolveReactionBadgeProgressState } from './reaction-badge-progress';
import { emptyMatchResult, isTerminalStatus } from './reaction-badge-utils';
import { queueCloseCurrentTabAfterDownloadComplete } from './reaction-badge-auto-close';
import {
    getPersistedBadgeState,
    persistBadgeCheckResult,
    persistBadgeState,
    persistDownloadProgressEvent,
    type PersistedBadgeState,
} from './badge-state-cache';
import { useCloseTabAfterQueuePreference } from './close-tab-after-queue-state';
import { useReactAllItemsInPostPreference } from './react-all-items-in-post-state';

type UseReactionBadgeProps = {
    media: MediaElement;
    onShortcutReady?: ((handler: ((type: BadgeReactionType) => void) | null) => void) | undefined;
};

export function useReactionBadge(props: UseReactionBadgeProps) {
    ensureReactionBadgeRuntimeStyles();
    const pageHostname = window.location.hostname.trim().toLowerCase();
    const closeTabAfterQueuePreference = useCloseTabAfterQueuePreference(pageHostname);
    const reactAllItemsInPostPreference = useReactAllItemsInPostPreference(pageHostname);

    const isChecking = ref(true);
    const matchResult = ref<BadgeMatchResult>(emptyMatchResult());
    const mediaResolution = ref<string | null>(null);
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

    const controlsDisabled = computed(() =>
        isChecking.value
        || submittingReactionType.value !== null
        || isDownloadLocked.value
        || reactAllItemsInPostPreference.saving.value);
    const activeReaction = computed(() => (matchResult.value.exists ? matchResult.value.reaction : null));
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
    const progressState = computed(() => resolveReactionBadgeProgressState({
        progressPercent: progressPercent.value,
        transferStatus: transferStatus.value,
        downloadedAt: matchResult.value.downloadedAt,
    }));

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
        if (!nextVisible && submittingReactionType.value !== null) {
            return;
        }

        showReactAllItemsInPost.value = nextVisible;
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

    async function refreshOpenTabCount(): Promise<void> {
        openTabCount.value = await requestTabCount();
    }

    function persistCurrentBadgeState(isLocked: boolean): void {
        persistBadgeState(resolvePersistenceUrl(), {
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
        syncRelatedPostThumbnailContext();
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
            if (showReactAllItemsInPost.value) {
                await reactAllItemsInPostPreference.refresh();
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

            const result = await submitBadgeReaction(props.media, type, { batchItems });
            const shouldAutoCloseCurrentTab = result.ok
                && closeTabAfterQueuePreference.enabled.value
                && result.shouldCloseTabAfterQueue;

            // DeviantArt gallery navigation can replace the active media node and unmount this
            // badge instance before the batch submit resolves. The tab-close side effect still
            // belongs to the successful submit, even if this component instance is now stale.
            if (shouldAutoCloseCurrentTab) {
                if (type === 'dislike') {
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
        closeTabAfterQueueEnabled: closeTabAfterQueuePreference.enabled,
        controlsDisabled,
        handleReactAllItemsInPostToggle,
        handleReactionClick,
        hoveredReaction,
        isChecking,
        isSavingCloseTabAfterQueuePreference: closeTabAfterQueuePreference.saving,
        mediaResolution,
        openTabCount,
        progressState,
        reactAllItemsInPost: reactAllItemsInPostPreference.enabled,
        showReactAllItemsInPost,
        submittingReactionType,
        timestampText,
        toggleCloseTabAfterQueuePreference: closeTabAfterQueuePreference.toggle,
        transferStatus,
    };
}
