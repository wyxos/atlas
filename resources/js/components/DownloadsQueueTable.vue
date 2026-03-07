<script setup lang="ts">
import { ref, watch } from 'vue';
import VirtualList from './VirtualList.vue';
import DownloadsQueueRow from './DownloadsQueueRow.vue';
import DownloadsQueueSortButton from './DownloadsQueueSortButton.vue';
import type {
    DownloadQueueDetails,
    DownloadQueueItem,
    DownloadQueueSortDirection,
    DownloadQueueSortKey,
} from '@/types/downloadQueue';
import {
    canCancelDownloadQueueItem,
    canPauseDownloadQueueItem,
    canRestartDownloadQueueItem,
    canResumeDownloadQueueItem,
} from '@/utils/downloadQueue';

interface Props {
    items: DownloadQueueItem[];
    detailsById: Record<number, DownloadQueueDetails>;
    isInitialLoading: boolean;
    itemHeight: number;
    selectedIds: Set<number>;
    lastSelectedId: number | null;
    actionBusy: Record<number, boolean>;
    allFilteredSelected: boolean;
    someFilteredSelected: boolean;
    sortDirections: {
        progress: DownloadQueueSortDirection | null;
        createdAt: DownloadQueueSortDirection | null;
        queuedAt: DownloadQueueSortDirection | null;
        startedAt: DownloadQueueSortDirection | null;
        completedAt: DownloadQueueSortDirection | null;
    };
}

const props = defineProps<Props>();

const emit = defineEmits<{
    sort: [key: DownloadQueueSortKey];
    toggleSelectAll: [checked: boolean];
    visibleItemsChange: [items: DownloadQueueItem[]];
    scroll: [];
    rowSelect: [item: DownloadQueueItem, event: MouseEvent];
    rowToggleSelection: [item: DownloadQueueItem];
    pause: [item: DownloadQueueItem];
    resume: [item: DownloadQueueItem];
    cancel: [item: DownloadQueueItem];
    restart: [item: DownloadQueueItem];
    delete: [item: DownloadQueueItem];
}>();

const selectAllRef = ref<HTMLInputElement | null>(null);
const virtualListRef = ref<{ resetScroll: () => void } | null>(null);

watch(
    () => [props.someFilteredSelected, props.allFilteredSelected] as const,
    ([hasSome, hasAll]) => {
        if (!selectAllRef.value) {
            return;
        }

        selectAllRef.value.indeterminate = hasSome && !hasAll;
    },
    { immediate: true },
);

function emitVisibleItemsChange(items: unknown[]): void {
    emit('visibleItemsChange', items as DownloadQueueItem[]);
}

function isActionBusy(id: number): boolean {
    return props.actionBusy[id] ?? false;
}

defineExpose({
    resetScroll: () => virtualListRef.value?.resetScroll(),
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-twilight-indigo-500 bg-prussian-blue-700">
        <div
            class="flex min-w-[1320px] items-center justify-between border-b border-twilight-indigo-500/40 px-4 py-2 text-xs uppercase tracking-wide text-blue-slate-300"
        >
            <div class="flex items-center gap-3">
                <input
                    ref="selectAllRef"
                    type="checkbox"
                    class="h-4 w-4 rounded border border-twilight-indigo-500 bg-prussian-blue-700 text-smart-blue-400"
                    :checked="allFilteredSelected"
                    aria-label="Select all downloads"
                    @change="emit('toggleSelectAll', ($event.target as HTMLInputElement).checked)"
                />
                <span>Download</span>
            </div>

            <div class="flex items-center gap-4">
                <span class="w-24 text-right">Status</span>
                <DownloadsQueueSortButton
                    label="Progress"
                    aria-label="Sort by progress"
                    :direction="sortDirections.progress"
                    @click="emit('sort', 'progress')"
                />
                <span class="w-20 text-right">Size</span>
                <DownloadsQueueSortButton
                    label="Added"
                    aria-label="Sort by added time"
                    :direction="sortDirections.createdAt"
                    @click="emit('sort', 'createdAt')"
                />
                <DownloadsQueueSortButton
                    label="Queued"
                    aria-label="Sort by queued time"
                    :direction="sortDirections.queuedAt"
                    @click="emit('sort', 'queuedAt')"
                />
                <DownloadsQueueSortButton
                    label="Started"
                    aria-label="Sort by started time"
                    :direction="sortDirections.startedAt"
                    @click="emit('sort', 'startedAt')"
                />
                <DownloadsQueueSortButton
                    label="Completed"
                    aria-label="Sort by completed time"
                    :direction="sortDirections.completedAt"
                    @click="emit('sort', 'completedAt')"
                />
                <span class="w-80 text-right">Actions</span>
            </div>
        </div>

        <div class="flex-1 min-h-0">
            <div v-if="isInitialLoading" class="px-4 py-12 text-center text-sm text-blue-slate-300">
                Loading downloads...
            </div>

            <VirtualList
                v-else
                ref="virtualListRef"
                :items="items"
                :item-height="itemHeight"
                container-class="flex-1 h-full overflow-auto"
                @scroll="emit('scroll')"
                @visible-items-change="emitVisibleItemsChange"
            >
                <template #default="{ items: visibleItems }">
                    <DownloadsQueueRow
                        v-for="item in visibleItems"
                        :key="item.id"
                        :item="item"
                        :details="detailsById[item.id] ?? null"
                        :selected="selectedIds.has(item.id)"
                        :last-selected="lastSelectedId === item.id"
                        :action-busy="isActionBusy(item.id)"
                        :can-pause="canPauseDownloadQueueItem(item)"
                        :can-resume="canResumeDownloadQueueItem(item)"
                        :can-cancel="canCancelDownloadQueueItem(item)"
                        :can-restart="canRestartDownloadQueueItem(item)"
                        @select="emit('rowSelect', item, $event)"
                        @toggle-selection="emit('rowToggleSelection', item)"
                        @pause="emit('pause', item)"
                        @resume="emit('resume', item)"
                        @cancel="emit('cancel', item)"
                        @restart="emit('restart', item)"
                        @delete="emit('delete', item)"
                    />
                </template>
            </VirtualList>

            <div v-if="!items.length && !isInitialLoading" class="px-4 py-12 text-center text-sm text-blue-slate-300">
                No downloads found.
            </div>
        </div>
    </div>
</template>
