<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue';
import { Ban, Heart, PanelRightClose, Smile, ThumbsUp, Undo2, X } from 'lucide-vue-next';
import { queueManager } from '@/composables/useQueue';
import ToastPreviewStrip, { type ToastPreviewStripItem } from './toasts/ToastPreviewStrip.vue';
import {
    cancelBatchQueuedReaction,
    cancelQueuedReaction,
    isBatchReactionQueueMetadata,
    isSingleReactionQueueMetadata,
    type QueuedReactionType,
} from '@/utils/reactionQueue';

type ReactionQueueCard = {
    fileId: number;
    isBatch: boolean;
    previewItems: ToastPreviewStripItem[];
    queueId: string;
    reactionType: QueuedReactionType;
    totalCount: number;
};

const emit = defineEmits<{
    close: [];
}>();

const queue = queueManager;
const queueItems = queue.collection.getAllComputed();

const visibleCards = computed<ReactionQueueCard[]>(() =>
    queueItems.value
        .flatMap((item): ReactionQueueCard[] => {
            if (isSingleReactionQueueMetadata(item.metadata)) {
                return [{
                    fileId: item.metadata.fileId,
                    isBatch: false,
                    previewItems: [{
                        key: item.metadata.fileId,
                        label: item.metadata.fileId,
                        thumbnail: item.metadata.thumbnail,
                    }],
                    queueId: item.id,
                    reactionType: item.metadata.reactionType,
                    totalCount: 1,
                }];
            }

            if (isBatchReactionQueueMetadata(item.metadata)) {
                return [{
                    fileId: item.metadata.fileIds[0] ?? 0,
                    isBatch: true,
                    previewItems: getBatchPreviewItems(item.metadata.fileIds, item.metadata.previews),
                    queueId: item.id,
                    reactionType: item.metadata.reactionType,
                    totalCount: item.metadata.fileIds.length,
                }];
            }

            return [];
        })
        .reverse(),
);

const queuedCount = computed(() =>
    visibleCards.value.reduce((total, card) => total + card.totalCount, 0),
);

function getBatchPreviewItems(
    fileIds: number[],
    previews: Array<{ fileId: number; thumbnail?: string }> | undefined,
): ToastPreviewStripItem[] {
    const previewsByFileId = new Map(previews?.map((preview) => [preview.fileId, preview.thumbnail]));

    return fileIds.map((fileId) => ({
        key: fileId,
        label: fileId,
        thumbnail: previewsByFileId.get(fileId),
    }));
}

function getReactionMeta(type: QueuedReactionType): { color: string; icon: typeof Heart; label: string } {
    const metas: Record<QueuedReactionType, { color: string; icon: typeof Heart; label: string }> = {
        love: { color: 'text-red-500', icon: Heart, label: 'Loved' },
        like: { color: 'text-blue-500', icon: ThumbsUp, label: 'Liked' },
        funny: { color: 'text-yellow-500', icon: Smile, label: 'Funny' },
        blacklist: { color: 'text-danger-400', icon: Ban, label: 'Blacklisted' },
    };

    return metas[type];
}

function getProgress(queueId: string): number {
    return queue.query.getProgress(queueId);
}

function formatCountdown(queueId: string): string {
    const totalMs = Math.max(0, queue.query.getRemainingTime(queueId));
    const seconds = Math.floor(totalMs / 1000);
    const centiseconds = Math.floor((totalMs % 1000) / 10);

    return `${seconds.toString().padStart(2, '0')}:${centiseconds.toString().padStart(2, '0')}`;
}

async function undoCard(card: ReactionQueueCard): Promise<void> {
    if (card.isBatch) {
        await cancelBatchQueuedReaction(card.queueId);
        return;
    }

    await cancelQueuedReaction(card.fileId, card.reactionType);
}

onMounted(() => {
    queue.modal.setModalOpen(true);
    queue.freeze.freezeAll();
});

onUnmounted(() => {
    queue.modal.setModalOpen(false);
    queue.freeze.unfreezeImmediately();
});
</script>

<template>
    <div class="flex h-full w-full justify-end bg-prussian-blue-900/70">
        <aside class="flex h-full w-[min(100%,30rem)] flex-col border-l border-white/10 bg-prussian-blue-700 shadow-2xl shadow-prussian-blue-900/60">
            <header class="grid grid-cols-[2.25rem_1fr_2.25rem] items-center border-b border-white/10 bg-prussian-blue-800/80 px-4 py-3">
                <div />
                <div class="flex justify-center">
                    <span
                        class="inline-flex min-w-6 items-center justify-center rounded-full border border-smart-blue-300/60 bg-smart-blue-500 px-2 py-0.5 text-xs font-semibold text-white shadow-lg shadow-smart-blue-900/40"
                        data-test="browse-global-start-panel-count"
                    >
                        {{ queuedCount }}
                    </span>
                </div>
                <button
                    type="button"
                    class="inline-flex h-9 w-9 items-center justify-center border border-white/10 bg-prussian-blue-900/40 text-twilight-indigo-100 transition hover:border-white/20 hover:bg-prussian-blue-800/80"
                    aria-label="Close reaction queue"
                    data-test="browse-global-start-panel-close-button"
                    @click="emit('close')"
                >
                    <PanelRightClose :size="16" />
                </button>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto bg-prussian-blue-700 p-4" data-test="browse-global-start-panel-list">
                <TransitionGroup
                    v-if="visibleCards.length > 0"
                    name="reaction-card"
                    tag="div"
                    class="relative space-y-3"
                    data-test="browse-global-start-panel-cards"
                >
                    <article
                        v-for="card in visibleCards"
                        :key="card.queueId"
                        class="reaction-panel-card group relative flex min-w-[300px] items-center gap-4 rounded-lg border border-twilight-indigo-500/50 bg-prussian-blue-600 p-4 shadow-xl"
                        data-test="browse-global-start-panel-card"
                    >
                        <ToastPreviewStrip
                            v-if="card.isBatch"
                            :items="card.previewItems"
                            :total-count="card.totalCount"
                            :max-visible="3"
                        />
                        <div v-else class="shrink-0">
                            <img
                                v-if="card.previewItems[0]?.thumbnail"
                                :src="card.previewItems[0].thumbnail"
                                :alt="`File ${card.fileId}`"
                                class="size-16 rounded object-cover"
                            />
                            <div
                                v-else
                                class="flex size-16 items-center justify-center rounded bg-gradient-to-br from-smart-blue-300 via-sapphire-500 to-prussian-blue-800 text-xs text-twilight-indigo-100"
                                :aria-label="`File ${card.fileId}`"
                            >
                                #{{ card.fileId }}
                            </div>
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex min-w-0 items-center gap-2">
                                    <div :class="['shrink-0', getReactionMeta(card.reactionType).color]">
                                        <component :is="getReactionMeta(card.reactionType).icon" class="size-4" />
                                    </div>
                                    <p class="truncate text-sm font-semibold text-twilight-indigo-100">
                                        {{ getReactionMeta(card.reactionType).label }}
                                        <template v-if="card.isBatch">
                                            {{ card.totalCount }} files
                                        </template>
                                        <template v-else>
                                            file #{{ card.fileId }}
                                        </template>
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="shrink-0 rounded p-1 text-twilight-indigo-300 transition-colors hover:bg-twilight-indigo-500/20 hover:text-twilight-indigo-100"
                                    aria-label="Remove queued reaction"
                                    @click="undoCard(card)"
                                >
                                    <X class="size-4" />
                                </button>
                            </div>

                            <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-twilight-indigo-500/20">
                                <div
                                    class="h-full bg-smart-blue-400 transition-all duration-100 ease-linear"
                                    :style="{ width: `${getProgress(card.queueId)}%` }"
                                />
                            </div>

                            <div class="mt-2 flex items-center justify-between gap-2">
                                <p class="font-mono text-xs text-twilight-indigo-300">
                                    {{ formatCountdown(card.queueId) }}
                                </p>
                                <button
                                    type="button"
                                    class="flex items-center gap-1 rounded bg-twilight-indigo-500/20 px-2 py-1 text-xs font-medium text-twilight-indigo-200 transition-colors hover:bg-twilight-indigo-500/30 hover:text-twilight-indigo-100"
                                    @click="undoCard(card)"
                                >
                                    <Undo2 class="size-3" />
                                    Undo
                                </button>
                            </div>
                        </div>
                    </article>
                </TransitionGroup>

                <div
                    v-else
                    class="flex h-full items-center justify-center border border-dashed border-white/10 bg-prussian-blue-800/40 px-6 text-sm text-twilight-indigo-300"
                    data-test="browse-global-start-panel-empty"
                >
                    No queued reactions
                </div>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.reaction-panel-card {
    max-width: 600px;
}

.reaction-card-move,
.reaction-card-enter-active,
.reaction-card-leave-active {
    transition-duration: 260ms;
    transition-property: opacity, transform;
    transition-timing-function: cubic-bezier(0.2, 0, 0, 1);
}

.reaction-card-enter-from {
    opacity: 0;
    transform: translate3d(0, -18px, 0) scale(0.98);
}

.reaction-card-leave-active {
    position: absolute;
    width: calc(100% - 2rem);
}

.reaction-card-leave-to {
    opacity: 0;
    transform: translate3d(120%, 0, 0) scale(0.96);
}
</style>
