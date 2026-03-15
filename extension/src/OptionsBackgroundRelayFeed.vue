<script setup lang="ts">
/* global chrome */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import type { DownloadProgressDebugSnapshot } from './background-download-progress';

type BackgroundDebugResponse = {
    ok?: boolean;
    snapshot?: DownloadProgressDebugSnapshot;
};

const POLL_INTERVAL_MS = 2000;

const snapshot = ref<DownloadProgressDebugSnapshot | null>(null);
const isLoading = ref(true);
const hasError = ref(false);

let pollTimer: number | null = null;

const statusLabel = computed(() => {
    if (snapshot.value === null) {
        return isLoading.value ? 'Checking' : 'Unavailable';
    }

    switch (snapshot.value.connectionState) {
        case 'connected':
            return 'Connected';
        case 'connecting':
        case 'reconnecting':
            return 'Checking';
        case 'idle':
        case 'setup_required':
        case 'auth_failed':
        case 'offline':
        case 'reverb_unavailable':
        case 'disconnected':
        case 'failed':
            return 'Disconnected';
    }

    return 'Unavailable';
});

const statusDetail = computed(() => {
    if (snapshot.value === null) {
        return hasError.value
            ? 'Unable to read the background relay state.'
            : 'Checking background relay state.';
    }

    const baseDetail = snapshot.value.connectionDetail;
    switch (snapshot.value.connectionState) {
        case 'idle':
            return snapshot.value.subscriberTabCount > 0
                ? 'Waiting for the background relay to reconnect.'
                : 'No subscribed tabs yet.';
        case 'connecting':
            return 'Background relay is connecting to Reverb.';
        case 'reconnecting':
            return 'Background relay is reconnecting to Reverb.';
        case 'connected':
            return 'Background relay is connected and rebroadcasting events to tabs.';
        case 'setup_required':
            return 'Background relay cannot connect until the API key is configured.';
        case 'auth_failed':
            return 'Background relay could not authenticate against Atlas.';
        case 'offline':
            return 'Background relay could not reach Atlas.';
        case 'reverb_unavailable':
            return 'Atlas reported that Reverb is unavailable.';
        case 'failed':
        case 'disconnected':
            return baseDetail ?? 'Background relay is disconnected.';
    }

    return baseDetail ?? 'Background relay state is unknown.';
});

function formatJson(value: unknown): string {
    return JSON.stringify(value, null, 2) ?? '';
}

async function refreshSnapshot(): Promise<void> {
    isLoading.value = snapshot.value === null;

    try {
        const response = await new Promise<BackgroundDebugResponse | null>((resolve) => {
            try {
                chrome.runtime.sendMessage({ type: 'ATLAS_GET_DOWNLOAD_PROGRESS_DEBUG_STATE' }, (value: unknown) => {
                    if (chrome.runtime.lastError || typeof value !== 'object' || value === null) {
                        resolve(null);
                        return;
                    }

                    resolve(value as BackgroundDebugResponse);
                });
            } catch {
                resolve(null);
            }
        });

        if (!response?.ok || !response.snapshot) {
            hasError.value = true;
            return;
        }

        snapshot.value = response.snapshot;
        hasError.value = false;
    } finally {
        isLoading.value = false;
    }
}

function startPolling(): void {
    if (pollTimer !== null) {
        return;
    }

    pollTimer = window.setInterval(() => {
        void refreshSnapshot();
    }, POLL_INTERVAL_MS);
}

function stopPolling(): void {
    if (pollTimer === null) {
        return;
    }

    window.clearInterval(pollTimer);
    pollTimer = null;
}

async function clearEvents(): Promise<void> {
    await new Promise<void>((resolve) => {
        try {
            chrome.runtime.sendMessage({ type: 'ATLAS_CLEAR_DOWNLOAD_PROGRESS_DEBUG_STATE' }, () => {
                resolve();
            });
        } catch {
            resolve();
        }
    });

    await refreshSnapshot();
}

onMounted(() => {
    void refreshSnapshot();
    startPolling();
});

onBeforeUnmount(() => {
    stopPolling();
});
</script>

<template>
    <section class="rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/40 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-medium text-regal-navy-100">Background Relay</h2>
                    <Badge :variant="statusLabel === 'Connected' ? 'active' : 'inactive'">
                        {{ statusLabel }}
                    </Badge>
                </div>
                <p class="text-xs text-twilight-indigo-300">
                    {{ statusDetail }}
                </p>
                <p class="text-xs text-twilight-indigo-300">
                    Subscribers:
                    <span class="font-mono text-smart-blue-100">{{ snapshot?.subscriberTabCount ?? 0 }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Badge :variant="(snapshot?.recentEvents.length ?? 0) > 0 ? 'active' : 'inactive'">
                    {{ snapshot?.recentEvents.length ?? 0 }} relayed
                </Badge>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    @click="void refreshSnapshot()"
                >
                    Refresh
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    @click="void clearEvents()"
                >
                    Clear
                </button>
            </div>
        </div>

        <p
            v-if="(snapshot?.recentEvents.length ?? 0) === 0"
            class="rounded-md border border-dashed border-smart-blue-500/30 bg-prussian-blue-800/30 px-3 py-4 text-sm text-twilight-indigo-300"
        >
            No events recorded by the background relay yet.
        </p>

        <div v-else class="max-h-80 space-y-2 overflow-y-auto pr-1">
            <article
                v-for="row in snapshot?.recentEvents ?? []"
                :key="row.id"
                class="rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/55 p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-medium text-regal-navy-100">{{ row.event.event }}</p>
                    <p class="text-xs text-twilight-indigo-300">{{ new Date(row.receivedAt).toLocaleTimeString() }}</p>
                </div>
                <p class="mt-2 font-mono text-xs text-smart-blue-100">
                    transfer={{ row.event.transferId ?? '-' }}
                    · file={{ row.event.fileId ?? '-' }}
                    · status={{ row.event.status ?? '-' }}
                    · percent={{ row.event.percent ?? '-' }}
                </p>
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs text-twilight-indigo-200">Payload</summary>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-prussian-blue-900/70 p-3 text-xs text-smart-blue-100">{{ formatJson(row.event.payload) }}</pre>
                </details>
            </article>
        </div>
    </section>
</template>
