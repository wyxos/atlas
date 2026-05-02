import { useToast } from '@/components/ui/toast/use-toast';
import { queueManager } from '@/composables/useQueue';
import { createReactionCallback, createBatchReactionCallback, createBatchBlacklistCallback, type BatchBlacklistResult } from './reactions';
import updateReactionState from '@/utils/reactionStateUpdater';
import type { ReactionType } from '@/types/reaction';
import type { Ref } from 'vue';
import type { FeedItem } from '@/composables/useTabs';
import ReactionQueueToast from '@/components/toasts/ReactionQueueToast.vue';
import BatchReactionQueueToast from '@/components/toasts/BatchReactionQueueToast.vue';

const toast = useToast();

const queue = queueManager;
const queueCollection = queue.collection;
const REACTION_COUNTDOWN_DURATION = 5000; // 5 seconds
const QUEUED_REACTION_TYPES = ['love', 'like', 'funny', 'blacklist'] as const;
export type QueuedReactionType = ReactionType | 'blacklist';
type ReactionQueueMetadata = {
    restoreCallback?: () => Promise<void> | void;
    items?: Ref<FeedItem[]>;
};
type SingleReactionQueueMetadata = ReactionQueueMetadata & {
    fileId: number;
    reactionType: QueuedReactionType;
};
type BatchReactionQueueMetadata = ReactionQueueMetadata & {
    fileIds: number[];
    reactionType: QueuedReactionType;
};
type QueueReactionOptions = {
    forceDownload?: boolean;
    updateLocalState?: boolean;
};
type QueueBatchReactionOptions = {
    updateLocalState?: boolean;
};
type QueueBlacklistOptions = {
    onSuccess?: (results: BatchBlacklistResult[]) => Promise<void> | void;
};

function isQueuedReactionType(value: unknown): value is QueuedReactionType {
    return typeof value === 'string' && QUEUED_REACTION_TYPES.includes(value as QueuedReactionType);
}

function isSingleReactionQueueMetadata(metadata: unknown): metadata is SingleReactionQueueMetadata {
    return typeof metadata === 'object'
        && metadata !== null
        && typeof (metadata as { fileId?: unknown }).fileId === 'number'
        && isQueuedReactionType((metadata as { reactionType?: unknown }).reactionType);
}

function isBatchReactionQueueMetadata(metadata: unknown): metadata is BatchReactionQueueMetadata {
    return typeof metadata === 'object'
        && metadata !== null
        && Array.isArray((metadata as { fileIds?: unknown }).fileIds)
        && (metadata as { fileIds: unknown[] }).fileIds.every((fileId) => typeof fileId === 'number')
        && isQueuedReactionType((metadata as { reactionType?: unknown }).reactionType);
}

function getLatestQueuedReactionMetadata():
    | { queueId: string; metadata: SingleReactionQueueMetadata | BatchReactionQueueMetadata }
    | null {
    const items = queueCollection.getAll();

    for (let index = items.length - 1; index >= 0; index--) {
        const item = items[index];

        if (isBatchReactionQueueMetadata(item.metadata) || isSingleReactionQueueMetadata(item.metadata)) {
            return {
                queueId: item.id,
                metadata: item.metadata,
            };
        }
    }

    return null;
}

async function runRestoreCallback(
    restoreCallback: (() => Promise<void> | void) | undefined,
    errorMessage: string,
): Promise<void> {
    if (!restoreCallback) {
        return;
    }

    try {
        await restoreCallback();
    } catch (error) {
        console.error(errorMessage, error);
    }
}

/**
 * Queue a reaction with countdown toast.
 * Shows toast immediately with countdown, executes reaction on expiration.
 */
export function queueReaction(
    fileId: number,
    reactionType: ReactionType,
    thumbnail?: string,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<FeedItem[]>,
    options?: QueueReactionOptions,
): void {
    const queueId = `${reactionType}-${fileId}`;

    // Remove existing reaction for this file if any
    const existingItems = queueCollection.getAll();
    const existingItem = existingItems.find((item) => item.id.startsWith(`${reactionType}-${fileId}`) || item.id.startsWith(`reaction-${fileId}`));
    if (existingItem) {
        queueCollection.remove(existingItem.id);
        toast.dismiss(existingItem.id);
    }

    // Create reaction callback
    const reactionCallback = createReactionCallback();

    // Add to queue
    queueCollection.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            try {
                // Execute the reaction
                await reactionCallback(
                    fileId,
                    reactionType,
                    options?.forceDownload === true ? { forceDownload: true } : undefined,
                );
                
                if (options?.updateLocalState === true && items) {
                    updateReactionState(items, fileId, reactionType);
                }
                
                // Dismiss toast on success
                toast.dismiss(queueId);
            } catch (error) {
                console.error('Failed to execute queued reaction:', error);
                await runRestoreCallback(restoreCallback, 'Failed to restore queued reaction state:');
                // Show error toast
                toast.error('Failed to save reaction', {
                    id: `${queueId}-error`,
                });
                // Dismiss the countdown toast
                toast.dismiss(queueId);
            }
        },
        metadata: {
            fileId,
            reactionType,
            thumbnail,
            restoreCallback,
            items,
        },
    });

    // Show toast immediately with countdown
    toast(
        {
            component: ReactionQueueToast,
            props: {
                queueId,
                fileId,
                reactionType,
                thumbnail,
            },
        },
        {
            id: queueId,
            timeout: false, // Don't auto-dismiss - we handle it in onComplete
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

/**
 * Queue a batch of reactions with countdown toast.
 * Shows toast immediately with countdown, executes all reactions on expiration.
 */
export function queueBatchReaction(
    fileIds: number[],
    reactionType: ReactionType,
    previews: Array<{ fileId: number; thumbnail?: string }>,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<FeedItem[]>,
    options?: QueueBatchReactionOptions,
): void {
    if (fileIds.length === 0) {
        return;
    }

    const queueId = `batch-${reactionType}-${fileIds[0]}-${fileIds.length}-${Date.now()}`;

    const batchReactionCallback = createBatchReactionCallback();

    // Add to queue
    queue.collection.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            // Dismiss the countdown toast as soon as the timer expires.
            toast.dismiss(queueId);
            try {
                await batchReactionCallback(fileIds, reactionType);
                
                if (options?.updateLocalState === true && items) {
                    fileIds.forEach((fileId) => {
                        updateReactionState(items, fileId, reactionType);
                    });
                }
            } catch (error) {
                console.error('Failed to execute queued batch reaction:', error);
                await runRestoreCallback(restoreCallback, 'Failed to restore queued batch reaction state:');
                // Show error toast
                toast.error('Failed to save batch reaction', {
                    id: `${queueId}-error`,
                });
            }
        },
        metadata: {
            fileIds,
            reactionType,
            previews,
            restoreCallback,
            items,
        },
    });

    // Show toast immediately with countdown
    toast(
        {
            component: BatchReactionQueueToast,
            props: {
                queueId,
                reactionType,
                previews,
                totalCount: fileIds.length,
            },
        },
        {
            id: queueId,
            timeout: false, // Don't auto-dismiss - we handle it in onComplete
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

export function queueBlacklist(
    fileId: number,
    thumbnail?: string,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<FeedItem[]>,
    options?: QueueBlacklistOptions,
): void {
    const queueId = `blacklist-${fileId}`;
    const batchBlacklistCallback = createBatchBlacklistCallback();

    const existingItem = queueCollection.getAll().find((item) => item.id === queueId);
    if (existingItem) {
        queueCollection.remove(existingItem.id);
        toast.dismiss(existingItem.id);
    }

    queueCollection.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            try {
                const results = await batchBlacklistCallback([fileId]);
                await options?.onSuccess?.(results);
                toast.dismiss(queueId);
            } catch (error) {
                console.error('Failed to execute queued blacklist:', error);
                await runRestoreCallback(restoreCallback, 'Failed to restore queued blacklist state:');
                toast.error('Failed to blacklist file', {
                    id: `${queueId}-error`,
                });
                toast.dismiss(queueId);
            }
        },
        metadata: {
            fileId,
            reactionType: 'blacklist',
            thumbnail,
            restoreCallback,
            items,
        },
    });

    toast(
        {
            component: ReactionQueueToast,
            props: {
                queueId,
                fileId,
                reactionType: 'blacklist',
                thumbnail,
            },
        },
        {
            id: queueId,
            timeout: false,
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

export function queueBatchBlacklist(
    fileIds: number[],
    previews: Array<{ fileId: number; thumbnail?: string }>,
    restoreCallback?: () => Promise<void> | void,
    items?: Ref<FeedItem[]>,
    options?: QueueBlacklistOptions,
): void {
    if (fileIds.length === 0) {
        return;
    }

    const queueId = `batch-blacklist-${fileIds[0]}-${fileIds.length}-${Date.now()}`;
    const batchBlacklistCallback = createBatchBlacklistCallback();

    queue.collection.add({
        id: queueId,
        duration: REACTION_COUNTDOWN_DURATION,
        onComplete: async () => {
            toast.dismiss(queueId);

            try {
                const results = await batchBlacklistCallback(fileIds);
                await options?.onSuccess?.(results);
            } catch (error) {
                console.error('Failed to execute queued batch blacklist:', error);
                await runRestoreCallback(restoreCallback, 'Failed to restore queued batch blacklist state:');
                toast.error('Failed to blacklist files', {
                    id: `${queueId}-error`,
                });
            }
        },
        metadata: {
            fileIds,
            reactionType: 'blacklist',
            previews,
            restoreCallback,
            items,
        },
    });

    toast(
        {
            component: BatchReactionQueueToast,
            props: {
                queueId,
                reactionType: 'blacklist',
                previews,
                totalCount: fileIds.length,
            },
        },
        {
            id: queueId,
            timeout: false,
            closeButton: false,
            closeOnClick: false,
            toastClassName: 'reaction-queue-toast-wrapper',
            bodyClassName: 'reaction-queue-toast-body',
        }
    );
}

/**
 * Cancel a queued reaction and restore to masonry if restore callback exists.
 */
export async function cancelQueuedReaction(fileId: number, reactionType: QueuedReactionType): Promise<void> {
    const queueId = `${reactionType}-${fileId}`;
    const item = queueCollection.getAll().find((item) => item.id === queueId);

    // Remove from queue
    queueCollection.remove(queueId);
    toast.dismiss(queueId);

    await runRestoreCallback(
        (item?.metadata as ReactionQueueMetadata | undefined)?.restoreCallback,
        'Failed to restore item to masonry:',
    );
}

/**
 * Cancel a queued batch reaction and restore to masonry if restore callback exists.
 */
export async function cancelBatchQueuedReaction(queueId: string): Promise<void> {
    const item = queueCollection.getAll().find((item) => item.id === queueId);

    // Remove from queue
    queueCollection.remove(queueId);
    toast.dismiss(queueId);

    await runRestoreCallback(
        (item?.metadata as ReactionQueueMetadata | undefined)?.restoreCallback,
        'Failed to restore batch items to masonry:',
    );
}

export function undoLatestQueuedReaction(): boolean {
    const latestQueuedReaction = getLatestQueuedReactionMetadata();
    if (!latestQueuedReaction) {
        return false;
    }

    if (isBatchReactionQueueMetadata(latestQueuedReaction.metadata)) {
        void cancelBatchQueuedReaction(latestQueuedReaction.queueId);
        return true;
    }

    void cancelQueuedReaction(latestQueuedReaction.metadata.fileId, latestQueuedReaction.metadata.reactionType);
    return true;
}
