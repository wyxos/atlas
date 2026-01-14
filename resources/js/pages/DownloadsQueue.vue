<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Download, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import { Button } from '@/components/ui/button';
import type { DownloadTransfer } from '../types/downloadTransfer';
import { formatDate } from '../utils/date';

const transfers = ref<DownloadTransfer[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

type TransfersIndexResponse = {
    data: DownloadTransfer[];
};

async function fetchTransfers(): Promise<void> {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await window.axios.get('/api/download-transfers');
        transfers.value = (data as TransfersIndexResponse).data;
    } catch {
        error.value = 'Failed to load downloads. Please try again.';
    } finally {
        loading.value = false;
    }
}

function upsertTransfer(update: {
    downloadTransferId: number;
    fileId: number;
    domain: string;
    status: string;
    percent: number;
}): void {
    const idx = transfers.value.findIndex((t) => t.id === update.downloadTransferId);
    if (idx === -1) {
        return;
    }

    const current = transfers.value[idx];
    transfers.value[idx] = {
        ...current,
        status: update.status,
        percent: update.percent,
    };
}

type ProgressEventPayload = {
    downloadTransferId: number;
    fileId: number;
    domain: string;
    status: string;
    percent: number;
};

type EchoLike = {
    private: (channelName: string) => {
        listen: (event: string, callback: (payload: ProgressEventPayload) => void) => void;
        stopListening: (event: string) => void;
    };
};

let channel: ReturnType<EchoLike['private']> | null = null;

onMounted(async () => {
    await fetchTransfers();

    const echo = (window as unknown as { Echo?: EchoLike }).Echo;
    if (!echo) {
        return;
    }

    channel = echo.private('downloads');
    channel.listen('.DownloadTransferProgressUpdated', (payload) => {
        upsertTransfer({
            downloadTransferId: payload.downloadTransferId,
            fileId: payload.fileId,
            domain: payload.domain,
            status: payload.status,
            percent: payload.percent,
        });
    });
});

onUnmounted(() => {
    if (!channel) {
        return;
    }

    try {
        channel.stopListening('.DownloadTransferProgressUpdated');
    } catch {
        // ignore
    }
});
</script>

<template>
    <PageLayout>
        <div class="w-full">
            <div class="mb-8 flex items-center justify-between">
                <div>
                    <h4 class="text-2xl font-semibold mb-2 text-regal-navy-100">Downloads</h4>
                    <p class="text-blue-slate-300">Track queued and active downloads in real time.</p>
                </div>
                <Button variant="outline" @click="fetchTransfers" :disabled="loading">
                    <RefreshCw :size="16" class="mr-2" />
                    Refresh
                </Button>
            </div>

            <div v-if="loading" class="border border-twilight-indigo-500 rounded-lg bg-prussian-blue-700 text-center py-12">
                <p class="text-twilight-indigo-100 text-lg">Loading...</p>
            </div>

            <div v-else-if="error" class="border border-twilight-indigo-500 rounded-lg bg-prussian-blue-700 text-center py-12">
                <p class="text-red-500 text-lg">{{ error }}</p>
            </div>

            <div v-else class="w-full overflow-x-auto">
                <o-table :data="transfers" class="w-full overflow-hidden">
                    <o-table-column field="id" label="ID" width="80" />

                    <o-table-column field="file" label="File" width="260">
                        <template #default="{ row }">
                            <div class="flex flex-col min-w-0">
                                <span class="text-twilight-indigo-100 truncate">
                                    {{ row.file?.filename ?? `File #${row.file_id}` }}
                                </span>
                                <span class="text-xs text-blue-slate-300 truncate">
                                    {{ row.domain }}
                                </span>
                            </div>
                        </template>
                    </o-table-column>

                    <o-table-column field="status" label="Status" width="160">
                        <template #default="{ row }">
                            <div class="flex items-center gap-2 text-twilight-indigo-100">
                                <Download v-if="row.status === 'completed'" :size="16" />
                                <AlertTriangle v-else-if="row.status === 'failed'" :size="16" class="text-danger-400" />
                                <span class="capitalize">{{ row.status }}</span>
                            </div>
                        </template>
                    </o-table-column>

                    <o-table-column field="percent" label="Progress" width="240">
                        <template #default="{ row }">
                            <div class="flex items-center gap-3">
                                <div class="h-2 w-40 rounded bg-prussian-blue-900 overflow-hidden">
                                    <div
                                        class="h-2 bg-smart-blue-600"
                                        :style="{ width: `${row.percent}%` }"
                                    />
                                </div>
                                <span class="text-sm text-twilight-indigo-100 tabular-nums w-10 text-right">
                                    {{ row.percent }}%
                                </span>
                            </div>
                        </template>
                    </o-table-column>

                    <o-table-column field="queued_at" label="Queued" width="160">
                        <template #default="{ row }">
                            <span class="text-twilight-indigo-100">
                                {{ row.queued_at ? formatDate(row.queued_at) : '—' }}
                            </span>
                        </template>
                    </o-table-column>

                    <o-table-column field="started_at" label="Started" width="160">
                        <template #default="{ row }">
                            <span class="text-twilight-indigo-100">
                                {{ row.started_at ? formatDate(row.started_at) : '—' }}
                            </span>
                        </template>
                    </o-table-column>

                    <o-table-column field="error" label="Error" width="320">
                        <template #default="{ row }">
                            <span v-if="row.error" class="text-danger-300 truncate block max-w-[280px]" :title="row.error">
                                {{ row.error }}
                            </span>
                            <span v-else class="text-twilight-indigo-500 italic text-xs">—</span>
                        </template>
                    </o-table-column>

                    <template #empty>
                        <div class="flex flex-col items-center justify-center py-12 px-6">
                            <Download :size="64" class="text-twilight-indigo-400 mb-4" />
                            <h3 class="text-xl font-semibold text-regal-navy-100 mb-2">No downloads</h3>
                            <p class="text-twilight-indigo-300 text-center max-w-md">
                                When downloads are queued, they will appear here and update every 5%.
                            </p>
                        </div>
                    </template>
                </o-table>
            </div>
        </div>
    </PageLayout>
</template>

