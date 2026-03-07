<script setup lang="ts">
import { Pause, Play, RotateCcw, Trash2, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import type { DownloadQueueDetails, DownloadQueueItem } from '@/types/downloadQueue';
import {
    copyDownloadQueuePath,
    formatDownloadQueueTimestamp,
    getDownloadQueueStatusClass,
} from '@/utils/downloadQueue';
import { formatFileSize } from '@/utils/file';

interface Props {
    item: DownloadQueueItem;
    details: DownloadQueueDetails | null;
    selected: boolean;
    lastSelected: boolean;
    actionBusy: boolean;
    canPause: boolean;
    canResume: boolean;
    canCancel: boolean;
    canRestart: boolean;
}

const props = defineProps<Props>();

const emit = defineEmits<{
    select: [event: MouseEvent];
    toggleSelection: [];
    pause: [];
    resume: [];
    cancel: [];
    restart: [];
    delete: [];
}>();

function handleRowClick(event: MouseEvent): void {
    const target = event.target as HTMLElement | null;

    if (target?.closest('button, a, input')) {
        return;
    }

    emit('select', event);
}

function handleCheckboxClick(): void {
    emit('toggleSelection');
}

function handleCopyPath(): void {
    void copyDownloadQueuePath(props.details?.path ?? null, props.details?.absolute_path ?? null);
}
</script>

<template>
    <div
        class="flex h-16 min-w-[1320px] items-center justify-between border-b border-twilight-indigo-500/20 px-4 text-sm text-twilight-indigo-100 transition-colors hover:bg-prussian-blue-600/60 cursor-pointer"
        :class="selected
            ? (lastSelected
                ? 'bg-smart-blue-600/20 ring-1 ring-inset ring-smart-blue-400/70'
                : 'bg-prussian-blue-500/70 ring-1 ring-inset ring-smart-blue-500/40')
            : ''"
        @click="handleRowClick"
    >
        <div class="flex min-w-0 items-center gap-3">
            <input
                type="checkbox"
                class="h-4 w-4 rounded border border-twilight-indigo-500 bg-prussian-blue-700 text-smart-blue-400"
                :checked="selected"
                aria-label="Select download"
                @click.stop="handleCheckboxClick"
            />
            <div class="h-10 w-10 overflow-hidden rounded border border-twilight-indigo-500/40 bg-prussian-blue-600">
                <img v-if="details?.preview" :src="details.preview" alt="" class="h-full w-full object-cover" />
                <Skeleton v-else class="h-full w-full rounded-none bg-prussian-blue-500/60" />
            </div>

            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-mono text-sm text-twilight-indigo-100">
                        ID {{ item.id }}
                    </span>
                    <button
                        v-if="details"
                        type="button"
                        class="truncate text-xs text-blue-slate-300 hover:text-white"
                        title="Copy full path"
                        @click.stop="handleCopyPath"
                    >
                        {{ details.path }}
                    </button>
                    <Skeleton v-else class="h-3 w-36 bg-prussian-blue-500/60" />
                </div>

                <div v-if="details" class="truncate text-xs text-smart-blue-400 hover:text-white">
                    <a :href="details.referrer_url" target="_blank">{{ details.referrer_url }}</a>
                </div>
                <Skeleton v-else class="mt-1 h-3 w-48 bg-prussian-blue-500/60" />

                <div
                    v-if="item.error"
                    class="mt-0.5 truncate text-[11px] text-warning-300"
                    :title="item.error"
                >
                    {{ item.error }}
                </div>
            </div>
        </div>

        <div class="flex items-center gap-4">
            <div class="flex w-24 items-center justify-end gap-2">
                <span
                    class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium"
                    :class="getDownloadQueueStatusClass(item.status)"
                >
                    {{ item.status }}
                </span>
            </div>

            <div class="w-28">
                <div v-if="item.percent !== null" class="h-1.5 w-full rounded bg-prussian-blue-600">
                    <div
                        class="h-full rounded bg-smart-blue-500 transition-all"
                        :style="{ width: `${item.percent}%` }"
                    />
                </div>
                <Skeleton v-else class="h-2 w-full bg-prussian-blue-500/60" />
                <div v-if="item.percent !== null" class="mt-1 text-right text-[11px] text-blue-slate-300">
                    {{ `${item.percent}%` }}
                </div>
                <div v-else class="mt-1 flex justify-end">
                    <Skeleton class="h-3 w-10 bg-prussian-blue-500/60" />
                </div>
            </div>

            <div class="w-20 text-right text-xs text-blue-slate-300">
                <span v-if="details">
                    {{ formatFileSize(details.size) }}
                </span>
                <Skeleton v-else class="ml-auto h-3 w-12 bg-prussian-blue-500/60" />
            </div>

            <div class="w-28 text-right text-xs text-blue-slate-300">
                {{ formatDownloadQueueTimestamp(item.created_at) }}
            </div>
            <div class="w-28 text-right text-xs text-blue-slate-300">
                {{ formatDownloadQueueTimestamp(item.queued_at) }}
            </div>
            <div class="w-28 text-right text-xs text-blue-slate-300">
                {{ formatDownloadQueueTimestamp(item.started_at) }}
            </div>
            <div class="w-28 text-right text-xs text-blue-slate-300">
                {{ formatDownloadQueueTimestamp(item.finished_at ?? item.failed_at) }}
            </div>

            <div class="flex w-80 items-center justify-end gap-2">
                <Button
                    variant="outline"
                    size="icon-sm"
                    class="border-warning-500/50 text-warning-200 hover:bg-warning-600/15 hover:text-warning-100"
                    :disabled="actionBusy || !canPause"
                    aria-label="Pause download"
                    @click.stop="$emit('pause')"
                >
                    <Pause :size="14" />
                </Button>
                <Button
                    variant="outline"
                    size="icon-sm"
                    class="border-success-500/50 text-success-200 hover:bg-success-600/15 hover:text-success-100"
                    :disabled="actionBusy || !canResume"
                    aria-label="Resume download"
                    @click.stop="$emit('resume')"
                >
                    <Play :size="14" />
                </Button>
                <Button
                    variant="outline"
                    size="icon-sm"
                    class="border-danger-500/50 text-danger-200 hover:bg-danger-600/15 hover:text-danger-100"
                    :disabled="actionBusy || !canCancel"
                    aria-label="Cancel download"
                    @click.stop="$emit('cancel')"
                >
                    <X :size="14" />
                </Button>
                <Button
                    variant="outline"
                    size="icon-sm"
                    class="border-sapphire-500/50 text-sapphire-200 hover:bg-sapphire-600/15 hover:text-sapphire-100"
                    :disabled="actionBusy || !canRestart"
                    aria-label="Restart download"
                    @click.stop="$emit('restart')"
                >
                    <RotateCcw :size="14" />
                </Button>
                <Button
                    variant="outline"
                    size="icon-sm"
                    class="border-danger-500/50 text-danger-200 hover:bg-danger-600/15 hover:text-danger-100"
                    :disabled="actionBusy"
                    aria-label="Delete download"
                    @click.stop="$emit('delete')"
                >
                    <Trash2 :size="14" />
                </Button>
            </div>
        </div>
    </div>
</template>
