<script setup lang="ts">
import { onMounted, onUnmounted, watch, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Download, Filter, AlertTriangle, Loader2 } from 'lucide-vue-next';
import PageLayout from '../components/PageLayout.vue';
import { Button } from '@/components/ui/button';
import FilterPanel from '../components/ui/FilterPanel.vue';
import Select from '../components/ui/Select.vue';
import FormInput from '../components/ui/FormInput.vue';
import { Listing, ActiveFilters, ListingTable } from '@wyxos/listing';
import Pill from '../components/ui/Pill.vue';
import type { DownloadTransfer } from '../types/downloadTransfer';
import { formatDate } from '../utils/date';

const route = useRoute();
const router = useRouter();

const listing = Listing.create<DownloadTransfer>({
    filters: {
        search: '',
        status: 'active',
    },
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
}) as any;
listing.loading();

listing
    .path('/api/download-transfers')
    .router(router)
    .onLoadError((error: string | null) => {
        if (error && error.includes('Failed to load data')) {
            return 'Failed to load downloads. Please try again later.';
        }

        return error;
    });

const hasActiveFilters = computed(() => listing.hasActiveFilters);

async function refreshTransfers(): Promise<void> {
    await listing.get({ query: route.query });
}

function removeTransfer(transferId: number): void {
    const idx = listing.data.findIndex((t: DownloadTransfer) => t.id === transferId);
    if (idx === -1) {
        return;
    }

    listing.data.splice(idx, 1);
}

async function handleProgressUpdate(payload: {
    downloadTransferId: number;
    fileId: number;
    domain: string;
    status: string;
    percent: number;
}): Promise<void> {
    const idx = listing.data.findIndex((t: DownloadTransfer) => t.id === payload.downloadTransferId);
    if (idx !== -1) {
        listing.data[idx] = {
            ...listing.data[idx],
            status: payload.status,
            percent: payload.percent,
        };
    }

    if (payload.status === 'completed') {
        removeTransfer(payload.downloadTransferId);
        await refreshTransfers();
    }
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
        listen: (event: string, callback: (payload: ProgressEventPayload) => void | Promise<void>) => void;
        stopListening: (event: string) => void;
    };
};

let channel: ReturnType<EchoLike['private']> | null = null;

watch(() => route.query, async (newQuery) => {
    await listing.get({ query: newQuery });
}, { deep: true });

onMounted(async () => {
    await listing.get({ query: route.query });

    const echo = (window as unknown as { Echo?: EchoLike }).Echo;
    if (!echo) {
        return;
    }

    channel = echo.private('downloads');
    channel.listen('.DownloadTransferProgressUpdated', (payload) => {
        void handleProgressUpdate(payload);
    });
    channel.listen('.DownloadTransferQueued', () => {
        void refreshTransfers();
    });
});

onUnmounted(() => {
    if (!channel) {
        return;
    }

    try {
        channel.stopListening('.DownloadTransferProgressUpdated');
        channel.stopListening('.DownloadTransferQueued');
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
                <Button variant="outline" @click="() => listing.openPanel()">
                    <Filter :size="16" class="mr-2" />
                    Filters
                </Button>
            </div>

            <ActiveFilters :listing="listing">
                <template #filter="{ filter, isRemoving, remove }">
                    <Pill :label="filter.label" :value="filter.value" variant="primary" reversed dismissible
                        @dismiss="remove">
                        <template v-if="isRemoving" #value>
                            <Loader2 :size="12" class="animate-spin" />
                        </template>
                    </Pill>
                </template>
                <template #clear="{ isAnyRemoving, isResetting, clear }">
                    <button type="button" @click="clear" :disabled="isAnyRemoving || isResetting"
                        class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-medium bg-danger-600 text-white border border-danger-500 hover:bg-danger-500 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        Clear
                    </button>
                </template>
            </ActiveFilters>

            <Transition name="table-grow" appear mode="out-in">
                <div v-if="listing.isLoading" key="loading"
                    class="border border-twilight-indigo-500 rounded-lg bg-prussian-blue-700 text-center py-12">
                    <p class="text-twilight-indigo-100 text-lg">Loading...</p>
                </div>
                <div v-else-if="listing.isUpdating" key="updating"
                    class="border border-twilight-indigo-500 rounded-lg bg-prussian-blue-700 text-center py-12">
                    <p class="text-twilight-indigo-100 text-lg">Updating...</p>
                </div>
                <div v-else-if="listing.error" key="error"
                    class="border border-twilight-indigo-500 rounded-lg bg-prussian-blue-700 text-center py-12">
                    <p class="text-red-500 text-lg">{{ listing.error }}</p>
                </div>
                <div v-else key="table" class="w-full overflow-x-auto">
                    <ListingTable :listing="listing" class="w-full overflow-hidden">
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
                                <h3 class="text-xl font-semibold text-regal-navy-100 mb-2">
                                    {{ hasActiveFilters ? 'No downloads found' : 'No downloads yet' }}
                                </h3>
                                <p class="text-twilight-indigo-300 text-center max-w-md">
                                    {{ hasActiveFilters
                                        ? 'Try adjusting your filters to see more results.'
                                        : 'When downloads are queued, they will appear here and update every 5%.' }}
                                </p>
                                <Button v-if="hasActiveFilters" variant="outline" @click="() => listing.resetFilters()"
                                    class="mt-4">
                                    Clear
                                </Button>
                            </div>
                        </template>
                    </ListingTable>
                </div>
            </Transition>

            <FilterPanel :modelValue="listing.isPanelOpen()"
                @update:modelValue="(open) => open ? listing.openPanel() : listing.closePanel()" title="Filter Downloads"
                :is-filtering="listing.isFiltering" :is-resetting="listing.isResetting"
                @apply="() => listing.applyFilters()" @reset="() => listing.resetFilters()">
                <form @submit.prevent="listing.applyFilters()" class="space-y-6">
                    <FormInput v-model="listing.filters.search" placeholder="Search by filename, domain, or ID...">
                        <template #label>
                            Search
                        </template>
                    </FormInput>
                    <Select v-model="listing.filters.status">
                        <template #label>
                            Status
                        </template>
                        <option value="active">Active</option>
                        <option value="queued">Queued</option>
                        <option value="downloading">Downloading</option>
                        <option value="failed">Failed</option>
                        <option value="completed">Completed</option>
                        <option value="all">All</option>
                    </Select>
                </form>
            </FilterPanel>
        </div>
    </PageLayout>
</template>

