<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
    selectedPausableCount: number;
    selectedResumableCount: number;
    selectedCancelableCount: number;
    selectedRestartableCount: number;
    resumableFailedCount: number;
    restartableFailedCount: number;
    completedCount: number;
    batchIsPausing: boolean;
    batchIsResuming: boolean;
    batchIsCanceling: boolean;
    batchIsRestarting: boolean;
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
    resumeSelection: [];
    cancelSelection: [];
    restartSelection: [];
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
                <DropdownMenu v-if="filteredCount || resumableFailedCount || restartableFailedCount || completedCount">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="gap-2">
                            <span>All actions</span>
                            <ChevronDown :size="14" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="w-60 border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100"
                    >
                        <DropdownMenuLabel class="text-smart-blue-100">
                            Queue and filter actions
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                        <DropdownMenuItem
                            :disabled="batchIsResumingFailed || resumableFailedCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('resumeFailed')"
                        >
                            {{ batchIsResumingFailed ? 'Resuming failed...' : `Resume failed (${resumableFailedCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="batchIsRestartingFailed || restartableFailedCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('restartFailed')"
                        >
                            {{ batchIsRestartingFailed ? 'Restarting failed...' : `Restart failed (${restartableFailedCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="removeIsDeleting || completedCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('removeCompleted')"
                        >
                            {{ `Remove completed (${completedCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                        <DropdownMenuItem
                            :disabled="removeIsDeleting || filteredCount === 0"
                            class="cursor-pointer text-danger-200 focus:bg-danger-600/20 focus:text-danger-100"
                            @select="$emit('removeFiltered')"
                        >
                            {{ `Remove filtered (${filteredCount})` }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <DropdownMenu v-if="selectedCount">
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="sm" class="gap-2">
                            <span>{{ `Selected actions (${selectedCount})` }}</span>
                            <ChevronDown :size="14" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        class="w-60 border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100"
                    >
                        <DropdownMenuLabel class="text-smart-blue-100">
                            {{ `Selected downloads (${selectedCount})` }}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                        <DropdownMenuItem
                            :disabled="batchIsPausing || selectedPausableCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('pauseSelection')"
                        >
                            {{ batchIsPausing ? 'Pausing selected...' : `Pause selected (${selectedPausableCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="batchIsResuming || selectedResumableCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('resumeSelection')"
                        >
                            {{ batchIsResuming ? 'Resuming selected...' : `Resume selected (${selectedResumableCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="batchIsCanceling || selectedCancelableCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('cancelSelection')"
                        >
                            {{ batchIsCanceling ? 'Canceling selected...' : `Cancel selected (${selectedCancelableCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="batchIsRestarting || selectedRestartableCount === 0"
                            class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                            @select="$emit('restartSelection')"
                        >
                            {{ batchIsRestarting ? 'Restarting selected...' : `Restart selected (${selectedRestartableCount})` }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                        <DropdownMenuItem
                            :disabled="removeIsDeleting"
                            class="cursor-pointer text-danger-200 focus:bg-danger-600/20 focus:text-danger-100"
                            @select="$emit('removeSelection')"
                        >
                            Remove selection
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </div>
</template>
