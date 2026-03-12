<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { DownloadQueueFilterStatus } from '@/types/downloadQueue';
import { getDownloadQueueFilterLabel } from '@/utils/downloadQueue';

interface Props {
    filters: readonly DownloadQueueFilterStatus[];
    selectedStatus: DownloadQueueFilterStatus;
    downloadsCount: number;
    filteredCount: number;
    statusCounts: Record<string, number>;
    selectedCount: number;
    selectedInFilterCount: number;
    resumableFailedCount: number;
    restartableFailedCount: number;
    completedCount: number;
    batchIsPausing: boolean;
    batchIsCanceling: boolean;
    batchIsResumingFailed: boolean;
    batchIsRestartingFailed: boolean;
    removeIsDeleting: boolean;
}

defineProps<Props>();

defineEmits<{
    selectStatus: [status: DownloadQueueFilterStatus];
    resumeFailed: [];
    restartFailed: [];
    removeCompleted: [];
    pauseSelection: [];
    cancelSelection: [];
    removeSelection: [];
    removeFiltered: [];
}>();
</script>

<template>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-for="status in filters"
                :key="status"
                type="button"
                class="inline-flex items-center gap-2 rounded border px-3 py-1 text-xs font-semibold uppercase tracking-wide transition-colors"
                :class="selectedStatus === status
                    ? 'border-smart-blue-500 bg-smart-blue-600 text-white'
                    : 'border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100 hover:bg-prussian-blue-500'"
                @click="$emit('selectStatus', status)"
            >
                <span>{{ getDownloadQueueFilterLabel(status) }}</span>
                <span
                    class="rounded px-1.5 py-0.5 text-[10px] font-semibold"
                    :class="selectedStatus === status
                        ? 'bg-white/15 text-white'
                        : 'bg-prussian-blue-500 text-blue-slate-200'"
                >
                    {{ status === 'all' ? downloadsCount : (statusCounts[status] ?? 0) }}
                </span>
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-3 text-xs text-blue-slate-300">
            <span>Total files: {{ downloadsCount }} | Filtered files: {{ filteredCount }}</span>
            <span v-if="selectedCount">
                Selected: {{ selectedCount }} | In filter: {{ selectedInFilterCount }}
            </span>
            <div
                v-if="selectedCount || filteredCount || resumableFailedCount || restartableFailedCount || completedCount"
                class="flex items-center gap-2"
            >
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="batchIsResumingFailed || resumableFailedCount === 0"
                    @click="$emit('resumeFailed')"
                >
                    {{ batchIsResumingFailed ? 'Resuming failed...' : `Resume failed (${resumableFailedCount})` }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="batchIsRestartingFailed || restartableFailedCount === 0"
                    @click="$emit('restartFailed')"
                >
                    {{ batchIsRestartingFailed ? 'Restarting failed...' : `Restart failed (${restartableFailedCount})` }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="removeIsDeleting || completedCount === 0"
                    @click="$emit('removeCompleted')"
                >
                    {{ `Remove completed (${completedCount})` }}
                </Button>
                <Button
                    v-if="selectedCount"
                    variant="outline"
                    size="sm"
                    :disabled="batchIsPausing"
                    @click="$emit('pauseSelection')"
                >
                    {{ batchIsPausing ? 'Pausing...' : 'Pause selection' }}
                </Button>
                <Button
                    v-if="selectedCount"
                    variant="outline"
                    size="sm"
                    :disabled="batchIsCanceling"
                    @click="$emit('cancelSelection')"
                >
                    {{ batchIsCanceling ? 'Canceling...' : 'Cancel selection' }}
                </Button>
                <Button
                    v-if="selectedCount"
                    variant="outline"
                    size="sm"
                    :disabled="removeIsDeleting"
                    @click="$emit('removeSelection')"
                >
                    Remove selection
                </Button>
                <Button
                    v-if="filteredCount"
                    variant="outline"
                    size="sm"
                    :disabled="removeIsDeleting"
                    @click="$emit('removeFiltered')"
                >
                    Remove all
                </Button>
            </div>
        </div>
    </div>
</template>
