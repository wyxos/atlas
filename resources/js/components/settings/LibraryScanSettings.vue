<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

const LIBRARY_SCAN_CHANNEL = 'library-scans';
const LIBRARY_SCAN_ITEMS_PAGE_SIZE = 100;

type LibraryScanRun = {
    id: number;
    mode: string;
    status: string;
    phase: string | null;
    files_found: number;
    files_imported: number;
    files_duplicate: number;
    files_processed: number;
    files_failed: number;
    files_canceled: number;
    started_at: string | null;
    scan_completed_at: string | null;
    finished_at: string | null;
    paused_at: string | null;
    canceled_at: string | null;
    error: string | null;
    created_at: string | null;
    updated_at: string | null;
};

type LibraryScanItem = {
    id: number;
    library_scan_run_id: number;
    file_id: number | null;
    original_path: string;
    imported_path: string | null;
    hash: string | null;
    mime_type: string | null;
    size: number | null;
    status: string;
    phase: string | null;
    progress: number;
    duplicate: boolean;
    parser: string | null;
    error_code: string | null;
    error_message: string | null;
    error_context: Record<string, unknown> | null;
    created_at: string | null;
    updated_at: string | null;
};

type LibraryScanItemsPagination = {
    limit: number;
    next_cursor: string | null;
    previous_cursor: string | null;
    has_more: boolean;
};

const libraryScanRuns = ref<LibraryScanRun[]>([]);
const selectedLibraryScanItems = ref<LibraryScanItem[]>([]);
const selectedLibraryScanRunId = ref<number | null>(null);
const selectedLibraryScanItemsPagination = ref<LibraryScanItemsPagination>({
    limit: LIBRARY_SCAN_ITEMS_PAGE_SIZE,
    next_cursor: null,
    previous_cursor: null,
    has_more: false,
});
const isLibraryScansLoading = ref(false);
const isLibraryScanActionBusy = ref(false);
const libraryScanNotice = ref('');
const libraryScanNoticeTone = ref<'success' | 'error' | 'neutral'>('neutral');

const activeLibraryScan = computed(() => libraryScanRuns.value[0] ?? null);
const libraryScanCanPause = computed(() => ['pending', 'scanning', 'processing'].includes(activeLibraryScan.value?.status ?? ''));
const libraryScanCanResume = computed(() => activeLibraryScan.value?.status === 'paused');
const libraryScanCanCancel = computed(() => ['pending', 'scanning', 'processing', 'paused'].includes(activeLibraryScan.value?.status ?? ''));
const activeLibraryScanModeLabel = computed(() => (
    activeLibraryScan.value?.mode === 'reparse' ? 'Parser re-run' : 'Library scan'
));
const isViewingLatestLibraryScanItems = computed(() => !selectedLibraryScanItemsPagination.value.previous_cursor);
const libraryScanProgress = computed(() => {
    const run = activeLibraryScan.value;
    if (!run || run.files_found <= 0) {
        return 0;
    }

    const completed = run.files_processed + run.files_failed + run.files_canceled;

    return Math.min(100, Math.round((completed / run.files_found) * 100));
});

function setLibraryScanNotice(message: string, tone: 'success' | 'error' | 'neutral' = 'neutral'): void {
    libraryScanNotice.value = message;
    libraryScanNoticeTone.value = tone;
}

function upsertLibraryScanRun(run: LibraryScanRun): void {
    const index = libraryScanRuns.value.findIndex((item) => item.id === run.id);
    if (index === -1) {
        libraryScanRuns.value = [run, ...libraryScanRuns.value].slice(0, 10);
        return;
    }

    const next = libraryScanRuns.value.slice();
    next[index] = { ...next[index], ...run };
    libraryScanRuns.value = next;
}

function upsertLibraryScanItem(item: LibraryScanItem): void {
    if (selectedLibraryScanRunId.value !== item.library_scan_run_id) {
        return;
    }

    const index = selectedLibraryScanItems.value.findIndex((row) => row.id === item.id);
    if (index === -1) {
        if (!isViewingLatestLibraryScanItems.value) {
            return;
        }

        selectedLibraryScanItems.value = [item, ...selectedLibraryScanItems.value].slice(
            0,
            selectedLibraryScanItemsPagination.value.limit,
        );
        return;
    }

    const next = selectedLibraryScanItems.value.slice();
    next[index] = { ...next[index], ...item };
    selectedLibraryScanItems.value = next;
}

async function fetchLibraryScans(): Promise<void> {
    isLibraryScansLoading.value = true;
    try {
        const { data } = await window.axios.get<{ items: LibraryScanRun[] }>('/api/settings/library-scans');
        libraryScanRuns.value = data.items;
        const firstRun = data.items[0] ?? null;
        if (firstRun) {
            await fetchLibraryScanDetails(firstRun.id);
        }
    } catch {
        setLibraryScanNotice('Failed to load library scans.', 'error');
    } finally {
        isLibraryScansLoading.value = false;
    }
}

async function fetchLibraryScanDetails(id: number, cursor: string | null = null): Promise<void> {
    const { data } = await window.axios.get<{
        run: LibraryScanRun;
        items: LibraryScanItem[];
        pagination?: LibraryScanItemsPagination;
    }>(
        `/api/settings/library-scans/${id}`,
        { params: { limit: LIBRARY_SCAN_ITEMS_PAGE_SIZE, ...(cursor ? { cursor } : {}) } },
    );
    selectedLibraryScanRunId.value = id;
    upsertLibraryScanRun(data.run);
    selectedLibraryScanItems.value = data.items;
    selectedLibraryScanItemsPagination.value = data.pagination ?? {
        limit: LIBRARY_SCAN_ITEMS_PAGE_SIZE,
        next_cursor: null,
        previous_cursor: null,
        has_more: false,
    };
}

async function handleStartLibraryScan(): Promise<void> {
    isLibraryScanActionBusy.value = true;
    try {
        const { data } = await window.axios.post<{ run: LibraryScanRun }>('/api/settings/library-scans');
        upsertLibraryScanRun(data.run);
        await fetchLibraryScanDetails(data.run.id);
        setLibraryScanNotice('Library scan queued.', 'success');
    } catch {
        setLibraryScanNotice('Failed to start library scan.', 'error');
    } finally {
        isLibraryScanActionBusy.value = false;
    }
}

async function handleReparseImportedFiles(): Promise<void> {
    isLibraryScanActionBusy.value = true;
    try {
        const { data } = await window.axios.post<{ run: LibraryScanRun }>(
            '/api/settings/library-scans/reparse-imported',
        );
        upsertLibraryScanRun(data.run);
        await fetchLibraryScanDetails(data.run.id);
        setLibraryScanNotice(
            data.run.mode === 'reparse'
                ? 'Imported file parser re-run queued.'
                : 'A library scan is already active.',
            data.run.mode === 'reparse' ? 'success' : 'neutral',
        );
    } catch {
        setLibraryScanNotice('Failed to re-run imported file parser.', 'error');
    } finally {
        isLibraryScanActionBusy.value = false;
    }
}

async function postLibraryScanAction(action: 'pause' | 'resume' | 'cancel' | 'restart'): Promise<void> {
    const run = activeLibraryScan.value;
    if (!run) {
        return;
    }

    isLibraryScanActionBusy.value = true;
    try {
        const { data } = await window.axios.post<{ run: LibraryScanRun }>(
            `/api/settings/library-scans/${run.id}/${action}`,
        );
        upsertLibraryScanRun(data.run);
        await fetchLibraryScanDetails(data.run.id);
    } catch {
        setLibraryScanNotice(`Failed to ${action} library scan.`, 'error');
    } finally {
        isLibraryScanActionBusy.value = false;
    }
}

async function handleLoadLatestLibraryScanItems(): Promise<void> {
    if (!selectedLibraryScanRunId.value) {
        return;
    }

    await fetchLibraryScanDetails(selectedLibraryScanRunId.value);
}

async function handleLoadOlderLibraryScanItems(): Promise<void> {
    if (!selectedLibraryScanRunId.value || !selectedLibraryScanItemsPagination.value.next_cursor) {
        return;
    }

    await fetchLibraryScanDetails(
        selectedLibraryScanRunId.value,
        selectedLibraryScanItemsPagination.value.next_cursor,
    );
}

function startLibraryScanEchoListeners(): void {
    const echo = window.Echo as undefined | {
        private: (channel: string) => {
            listen: (event: string, callback: (payload: unknown) => void) => void;
        };
    };

    if (!echo) {
        return;
    }

    const channel = echo.private(LIBRARY_SCAN_CHANNEL);
    channel.listen('.LibraryScanRunUpdated', (payload: unknown) => {
        if (payload && typeof payload === 'object') {
            upsertLibraryScanRun(payload as LibraryScanRun);
        }
    });
    channel.listen('.LibraryScanItemUpdated', (payload: unknown) => {
        if (payload && typeof payload === 'object') {
            upsertLibraryScanItem(payload as LibraryScanItem);
        }
    });
}

function stopLibraryScanEchoListeners(): void {
    const echo = window.Echo as undefined | {
        leave: (channel: string) => void;
    };

    echo?.leave(LIBRARY_SCAN_CHANNEL);
}

onMounted(() => {
    void fetchLibraryScans();
    startLibraryScanEchoListeners();
});

onBeforeUnmount(() => {
    stopLibraryScanEchoListeners();
});
</script>

<template>
    <div class="border border-smart-blue-500/30 rounded-lg p-6 bg-prussian-blue-700/50">
        <div class="flex flex-wrap items-start justify-between gap-4 mb-4">
            <div>
                <h5 class="text-lg font-semibold text-smart-blue-300 mb-2">Library Scan</h5>
                <p class="text-twilight-indigo-200">
                    Import unmanaged files from the Atlas storage root.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button
                    type="button"
                    size="sm"
                    :loading="isLibraryScanActionBusy"
                    @click="handleStartLibraryScan"
                >
                    Scan Library
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="secondary"
                    :loading="isLibraryScanActionBusy"
                    @click="handleReparseImportedFiles"
                >
                    Re-run Parsers
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!libraryScanCanPause || isLibraryScanActionBusy"
                    @click="postLibraryScanAction('pause')"
                >
                    Pause
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!libraryScanCanResume || isLibraryScanActionBusy"
                    @click="postLibraryScanAction('resume')"
                >
                    Resume
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    :disabled="!activeLibraryScan || isLibraryScanActionBusy"
                    @click="postLibraryScanAction('restart')"
                >
                    Restart
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="destructive"
                    :disabled="!libraryScanCanCancel || isLibraryScanActionBusy"
                    @click="postLibraryScanAction('cancel')"
                >
                    Cancel
                </Button>
            </div>
        </div>

        <div v-if="isLibraryScansLoading" class="text-sm text-twilight-indigo-200">
            Loading library scans...
        </div>

        <div v-else-if="activeLibraryScan" class="space-y-4">
            <div class="grid gap-3 text-sm text-twilight-indigo-100 md:grid-cols-3">
                <p>
                    <span class="text-twilight-indigo-300">Mode:</span>
                    {{ activeLibraryScanModeLabel }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Status:</span>
                    {{ activeLibraryScan.status }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Phase:</span>
                    {{ activeLibraryScan.phase ?? 'pending' }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Progress:</span>
                    {{ libraryScanProgress }}%
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Found:</span>
                    {{ activeLibraryScan.files_found }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Imported:</span>
                    {{ activeLibraryScan.files_imported }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Duplicates:</span>
                    {{ activeLibraryScan.files_duplicate }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Processed:</span>
                    {{ activeLibraryScan.files_processed }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Failed:</span>
                    {{ activeLibraryScan.files_failed }}
                </p>
                <p>
                    <span class="text-twilight-indigo-300">Canceled:</span>
                    {{ activeLibraryScan.files_canceled }}
                </p>
            </div>

            <div class="h-2 overflow-hidden rounded-full bg-prussian-blue-800">
                <div
                    class="h-full bg-smart-blue-400 transition-all"
                    :style="{ width: `${libraryScanProgress}%` }"
                />
            </div>

            <p v-if="activeLibraryScan.error" class="text-sm text-danger-200">
                {{ activeLibraryScan.error }}
            </p>

            <div v-if="selectedLibraryScanItems.length" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h6 class="text-sm font-semibold text-smart-blue-200">Queued Files</h6>
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="isViewingLatestLibraryScanItems"
                            @click="handleLoadLatestLibraryScanItems"
                        >
                            Latest
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="!selectedLibraryScanItemsPagination.next_cursor"
                            @click="handleLoadOlderLibraryScanItems"
                        >
                            Older
                        </Button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="text-xs uppercase text-twilight-indigo-300">
                            <tr>
                                <th class="py-2 pr-4 font-medium">File</th>
                                <th class="py-2 pr-4 font-medium">Status</th>
                                <th class="py-2 pr-4 font-medium">Parser</th>
                                <th class="py-2 pr-4 font-medium">Progress</th>
                                <th class="py-2 pr-4 font-medium">Result</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-smart-blue-500/20 text-twilight-indigo-100">
                            <tr v-for="item in selectedLibraryScanItems" :key="item.id">
                                <td class="py-2 pr-4">
                                    <div class="max-w-[280px] truncate">
                                        {{ item.imported_path ?? item.original_path }}
                                    </div>
                                    <div v-if="item.mime_type" class="text-xs text-twilight-indigo-300">
                                        {{ item.mime_type }}
                                    </div>
                                </td>
                                <td class="py-2 pr-4">
                                    {{ item.status }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ item.parser ?? 'none' }}
                                </td>
                                <td class="py-2 pr-4">
                                    {{ item.progress }}%
                                </td>
                                <td class="py-2 pr-4">
                                    <span v-if="item.duplicate">Duplicate</span>
                                    <span v-else-if="item.error_message" class="text-danger-200">
                                        {{ item.error_message }}
                                    </span>
                                    <span v-else>{{ item.phase ?? 'pending' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-twilight-indigo-200">
            No library scans have run yet.
        </p>

        <p
            v-if="libraryScanNotice"
            class="mt-4 text-sm"
            :class="libraryScanNoticeTone === 'success'
                ? 'text-smart-blue-200'
                : libraryScanNoticeTone === 'error'
                    ? 'text-danger-200'
                    : 'text-twilight-indigo-200'"
        >
            {{ libraryScanNotice }}
        </p>
    </div>
</template>
