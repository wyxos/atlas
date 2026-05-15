<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { CheckCircle2, Play, RefreshCw, ShieldCheck } from 'lucide-vue-next';

const POLL_INTERVAL_MS = 3000;

type ModerationFeedRemovalRun = {
    id: number;
    status: string;
    phase: string | null;
    chunk_size: number;
    active_rule_count: number;
    scanned_count: number;
    skipped_no_prompt_count: number;
    matched_count: number;
    updated_count: number;
    rules_match_current: boolean | null;
    can_apply: boolean;
    started_at: string | null;
    finished_at: string | null;
    applied_at: string | null;
    error: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type ModerationFeedRemovalRunsResponse = {
    active_rule_count: number;
    items: ModerationFeedRemovalRun[];
};

const runs = ref<ModerationFeedRemovalRun[]>([]);
const activeRuleCount = ref(0);
const chunkSize = ref(500);
const isLoading = ref(false);
const isActionBusy = ref(false);
const notice = ref('');
const noticeTone = ref<'success' | 'error' | 'neutral'>('neutral');
const applyDialogOpen = ref(false);
let pollTimer: ReturnType<typeof window.setInterval> | null = null;

const latestRun = computed(() => runs.value[0] ?? null);
const recentRuns = computed(() => runs.value.slice(1));
const hasRunningRun = computed(() => ['pending', 'previewing', 'applying'].includes(latestRun.value?.status ?? ''));
const canApplyLatestRun = computed(() => latestRun.value?.can_apply === true && !isActionBusy.value);
const latestRunStartedLabel = computed(() => formatDate(latestRun.value?.started_at ?? latestRun.value?.created_at));
const reportStats = computed(() => {
    const run = latestRun.value;
    if (!run) {
        return [];
    }

    return [
        { label: 'Active rules', value: run.active_rule_count },
        { label: 'Scanned', value: run.scanned_count },
        { label: 'Skipped no prompt', value: run.skipped_no_prompt_count },
        { label: 'Matched', value: run.matched_count },
        { label: 'Updated', value: run.updated_count },
    ];
});

function setNotice(message: string, tone: 'success' | 'error' | 'neutral' = 'neutral'): void {
    notice.value = message;
    noticeTone.value = tone;
}

function normalizeChunkSize(): number {
    const value = Number(chunkSize.value);
    if (!Number.isFinite(value)) {
        return 500;
    }

    return Math.max(1, Math.min(5000, Math.floor(value)));
}

function upsertRun(run: ModerationFeedRemovalRun): void {
    const index = runs.value.findIndex((item) => item.id === run.id);
    if (index === -1) {
        runs.value = [run, ...runs.value].slice(0, 10);
    } else {
        const next = runs.value.slice();
        next[index] = { ...next[index], ...run };
        runs.value = next;
    }

    syncPolling();
}

async function fetchRuns(showLoading = true): Promise<void> {
    if (showLoading) {
        isLoading.value = true;
    }

    try {
        const { data } = await window.axios.get<ModerationFeedRemovalRunsResponse>(
            '/api/settings/moderation-feed-removal-runs',
        );
        activeRuleCount.value = data.active_rule_count;
        runs.value = data.items;
        syncPolling();
    } catch {
        setNotice('Failed to load moderation maintenance reports.', 'error');
    } finally {
        if (showLoading) {
            isLoading.value = false;
        }
    }
}

async function handlePreview(): Promise<void> {
    isActionBusy.value = true;
    notice.value = '';
    chunkSize.value = normalizeChunkSize();

    try {
        const { data } = await window.axios.post<{ run: ModerationFeedRemovalRun }>(
            '/api/settings/moderation-feed-removal-runs/preview',
            { chunk_size: chunkSize.value },
        );
        upsertRun(data.run);
        setNotice(
            data.run.status === 'pending' ? 'Preview queued.' : 'A moderation maintenance run is already active.',
            'success',
        );
    } catch {
        setNotice('Failed to queue moderation preview.', 'error');
    } finally {
        isActionBusy.value = false;
    }
}

async function handleApply(): Promise<void> {
    const run = latestRun.value;
    if (!run) {
        return;
    }

    isActionBusy.value = true;
    notice.value = '';

    try {
        const { data } = await window.axios.post<{ run: ModerationFeedRemovalRun; message?: string }>(
            `/api/settings/moderation-feed-removal-runs/${run.id}/apply`,
        );
        upsertRun(data.run);
        applyDialogOpen.value = false;
        setNotice(data.message || 'Apply queued.', 'success');
    } catch (error: unknown) {
        const response = (error as {
            response?: { data?: { message?: string; run?: ModerationFeedRemovalRun } };
        })?.response;
        if (response?.data?.run) {
            upsertRun(response.data.run);
        }
        setNotice(response?.data?.message || 'Failed to queue apply.', 'error');
    } finally {
        isActionBusy.value = false;
    }
}

function startPolling(): void {
    if (pollTimer !== null) {
        return;
    }

    pollTimer = window.setInterval(() => {
        void fetchRuns(false);
    }, POLL_INTERVAL_MS);
}

function stopPolling(): void {
    if (pollTimer === null) {
        return;
    }

    window.clearInterval(pollTimer);
    pollTimer = null;
}

function syncPolling(): void {
    if (hasRunningRun.value) {
        startPolling();
    } else {
        stopPolling();
    }
}

function formatNumber(value: number): string {
    return value.toLocaleString();
}

function formatDate(value: string | null | undefined): string {
    if (!value) {
        return 'Not started';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Unknown';
    }

    return date.toLocaleString();
}

function formatStatus(status: string): string {
    return status
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function statusClass(run: ModerationFeedRemovalRun): string {
    if (['failed', 'stale'].includes(run.status)) {
        return 'border-danger-400 text-danger-200 bg-danger-500/10';
    }

    if (['previewed', 'applied'].includes(run.status)) {
        return 'border-smart-blue-400 text-smart-blue-200 bg-smart-blue-500/10';
    }

    return 'border-twilight-indigo-500 text-twilight-indigo-200 bg-prussian-blue-700/60';
}

onMounted(() => {
    void fetchRuns();
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Moderation Maintenance</h5>
                <p class="text-twilight-indigo-200">
                    Preview feed removals from active moderation rules, then apply the reviewed report.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <label class="flex items-center gap-2 text-xs text-twilight-indigo-200">
                    Chunk
                    <input
                        v-model.number="chunkSize"
                        data-test="feed-removal-chunk-size"
                        type="number"
                        min="1"
                        max="5000"
                        class="w-24 rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none"
                    />
                </label>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="isLoading"
                    @click="fetchRuns()"
                >
                    <RefreshCw :size="16" />
                    Refresh
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :loading="isActionBusy && !applyDialogOpen"
                    :disabled="hasRunningRun"
                    data-test="feed-removal-preview-button"
                    @click="handlePreview"
                >
                    <Play :size="16" />
                    Run Preview
                </Button>
            </div>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-2 text-xs text-twilight-indigo-200">
            <span class="rounded-full border border-twilight-indigo-500 bg-prussian-blue-600/70 px-3 py-1">
                {{ activeRuleCount.toLocaleString() }} active feed-removal rules now
            </span>
            <span v-if="hasRunningRun" class="rounded-full border border-smart-blue-500 bg-smart-blue-500/10 px-3 py-1 text-smart-blue-200">
                Run in progress
            </span>
        </div>

        <div v-if="isLoading" class="text-sm text-twilight-indigo-200">
            Loading moderation maintenance reports...
        </div>

        <div v-else-if="latestRun" class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-twilight-indigo-500/60 bg-prussian-blue-600/60 p-4">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-semibold text-regal-navy-100">Latest Report</span>
                        <span class="rounded-full border px-2 py-1 text-xs" :class="statusClass(latestRun)">
                            {{ formatStatus(latestRun.status) }}
                        </span>
                    </div>
                    <p class="text-xs text-twilight-indigo-300">
                        Started: {{ latestRunStartedLabel }}
                    </p>
                    <p v-if="latestRun.phase" class="text-xs text-twilight-indigo-300">
                        Phase: {{ formatStatus(latestRun.phase) }}
                    </p>
                </div>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    :disabled="!canApplyLatestRun"
                    data-test="feed-removal-apply-button"
                    @click="applyDialogOpen = true"
                >
                    <CheckCircle2 :size="16" />
                    Apply Report
                </Button>
            </div>

            <div class="grid gap-3 md:grid-cols-5">
                <div
                    v-for="stat in reportStats"
                    :key="stat.label"
                    class="rounded-lg border border-smart-blue-500/20 bg-prussian-blue-800/40 p-4"
                >
                    <div class="text-xs text-twilight-indigo-300">{{ stat.label }}</div>
                    <div class="mt-1 text-lg font-semibold text-regal-navy-100">
                        {{ formatNumber(stat.value) }}
                    </div>
                </div>
            </div>

            <p v-if="latestRun.rules_match_current === false" class="text-sm text-danger-200" data-test="feed-removal-stale-message">
                Rules changed since this preview. Run preview again before applying.
            </p>

            <p v-else-if="latestRun.status === 'previewed' && latestRun.matched_count === 0" class="text-sm text-twilight-indigo-200">
                No rows matched the latest preview.
            </p>

            <p v-else-if="latestRun.status === 'applied'" class="text-sm text-smart-blue-200">
                Library sync was queued for the updated files.
            </p>

            <p v-if="latestRun.error" class="text-sm text-danger-200">
                {{ latestRun.error }}
            </p>

            <div v-if="recentRuns.length" class="space-y-2">
                <h6 class="text-sm font-semibold text-smart-blue-200">Recent Reports</h6>
                <div class="space-y-2">
                    <div
                        v-for="run in recentRuns"
                        :key="run.id"
                        class="grid gap-2 rounded-lg border border-smart-blue-500/20 bg-prussian-blue-800/30 p-3 text-sm text-twilight-indigo-100 md:grid-cols-5"
                    >
                        <div>
                            <span class="text-twilight-indigo-300">Status:</span>
                            {{ formatStatus(run.status) }}
                        </div>
                        <div>
                            <span class="text-twilight-indigo-300">Rules:</span>
                            {{ formatNumber(run.active_rule_count) }}
                        </div>
                        <div>
                            <span class="text-twilight-indigo-300">Scanned:</span>
                            {{ formatNumber(run.scanned_count) }}
                        </div>
                        <div>
                            <span class="text-twilight-indigo-300">Matched:</span>
                            {{ formatNumber(run.matched_count) }}
                        </div>
                        <div>
                            <span class="text-twilight-indigo-300">Updated:</span>
                            {{ formatNumber(run.updated_count) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-twilight-indigo-200">
            No moderation maintenance reports have run yet.
        </p>

        <p
            v-if="notice"
            class="mt-4 text-sm"
            :class="noticeTone === 'success'
                ? 'text-smart-blue-200'
                : noticeTone === 'error'
                    ? 'text-danger-200'
                    : 'text-twilight-indigo-200'"
        >
            {{ notice }}
        </p>

        <Dialog v-model="applyDialogOpen">
            <DialogContent class="sm:max-w-[460px] bg-prussian-blue-600 border-smart-blue-500/30">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2 text-smart-blue-200">
                        <ShieldCheck :size="20" />
                        Apply Moderation Report
                    </DialogTitle>
                    <DialogDescription class="text-base mt-2 text-twilight-indigo-100">
                        Move {{ latestRun ? formatNumber(latestRun.matched_count) : '0' }} matched rows out of the feed.
                        Apply is blocked automatically if the active rules changed after preview.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose as-child>
                        <Button variant="outline" :disabled="isActionBusy">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        :loading="isActionBusy"
                        :disabled="!canApplyLatestRun"
                        data-test="feed-removal-confirm-apply-button"
                        @click="handleApply"
                    >
                        <CheckCircle2 :size="16" />
                        Apply
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
