<script setup lang="ts">
/* global chrome */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { createProgressEvent, type ProgressEvent } from './download-progress-event';
import { connectRuntimeReverb } from './reverb-runtime';
import type { ReverbClient, ReverbConnectionState, ReverbSubscription } from './reverb-client';

type ReverbEventLogEntry = ProgressEvent & {
    id: number;
    receivedAt: string;
};

const MAX_REVERB_EVENT_LOG_ROWS = 20;

const reverbStatusLabel = ref<'Connected' | 'Disconnected' | 'Unavailable' | 'Checking'>('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
const reverbEndpoint = ref<string | null>(null);
const reverbEvents = ref<ReverbEventLogEntry[]>([]);

const RELEVANT_STORAGE_KEYS = new Set(['atlasDomain', 'apiToken']);

let activeReverbClient: ReverbClient | null = null;
let activeReverbStateSubscription: ReverbSubscription | null = null;
let activeReverbErrorSubscription: ReverbSubscription | null = null;
let activeReverbEventSubscription: ReverbSubscription | null = null;
let reverbMonitorSequence = 0;
let nextReverbEventLogId = 1;

function formatJson(value: unknown): string {
    return JSON.stringify(value, null, 2) ?? '';
}

function clearReverbEvents(): void {
    reverbEvents.value = [];
}

function disconnectActiveReverbMonitor(): void {
    activeReverbEventSubscription?.unsubscribe();
    activeReverbEventSubscription = null;
    activeReverbErrorSubscription?.unsubscribe();
    activeReverbErrorSubscription = null;
    activeReverbStateSubscription?.unsubscribe();
    activeReverbStateSubscription = null;
    activeReverbClient?.disconnect();
    activeReverbClient = null;
}

function setDisconnectedReverbDetail(baseDetail: string, errorMessage: string | null): void {
    reverbStatusLabel.value = 'Disconnected';
    reverbStatusDetail.value = errorMessage && errorMessage.trim() !== ''
        ? `${baseDetail} ${errorMessage}`
        : baseDetail;
}

function applyReverbConnectionState(state: ReverbConnectionState, errorMessage: string | null = null): void {
    if (state === 'connected') {
        reverbStatusLabel.value = 'Connected';
        reverbStatusDetail.value = 'Reverb websocket connected. Listening for download events.';
        return;
    }

    if (state === 'connecting') {
        reverbStatusLabel.value = 'Checking';
        reverbStatusDetail.value = 'Connecting to Reverb websocket.';
        return;
    }

    if (state === 'reconnecting') {
        reverbStatusLabel.value = 'Checking';
        reverbStatusDetail.value = 'Reverb websocket reconnecting.';
        return;
    }

    if (state === 'failed') {
        setDisconnectedReverbDetail('Reverb websocket failed.', errorMessage);
        return;
    }

    setDisconnectedReverbDetail('Reverb websocket disconnected.', errorMessage);
}

function pushReverbEvent(event: ProgressEvent): void {
    reverbEvents.value = [
        {
            id: nextReverbEventLogId++,
            receivedAt: new Date().toLocaleTimeString(),
            ...event,
        },
        ...reverbEvents.value,
    ].slice(0, MAX_REVERB_EVENT_LOG_ROWS);
}

function isDocumentHidden(): boolean {
    const override = (globalThis as typeof globalThis & {
        __atlasDiagnosticsHiddenOverride?: boolean | null;
    }).__atlasDiagnosticsHiddenOverride;

    if (override !== undefined && override !== null) {
        return override;
    }

    return typeof document !== 'undefined' && document.visibilityState === 'hidden';
}

function hasRelevantStorageChanges(changes?: Record<string, unknown> | null): boolean {
    if (!changes) {
        return false;
    }

    for (const key of Object.keys(changes)) {
        if (RELEVANT_STORAGE_KEYS.has(key)) {
            return true;
        }
    }

    return false;
}

async function refreshReverbMonitor(): Promise<void> {
    const currentSequence = ++reverbMonitorSequence;
    disconnectActiveReverbMonitor();

    reverbStatusLabel.value = 'Checking';
    reverbStatusDetail.value = 'Checking Reverb connection.';
    reverbEndpoint.value = null;

    const runtime = await connectRuntimeReverb();
    if (currentSequence !== reverbMonitorSequence) {
        if (runtime.kind === 'connected') {
            runtime.client.disconnect();
        }

        return;
    }

    switch (runtime.kind) {
        case 'setup_required':
            reverbStatusLabel.value = 'Unavailable';
            reverbStatusDetail.value = 'Requires API key first.';
            return;
        case 'auth_failed':
            reverbStatusLabel.value = 'Unavailable';
            reverbStatusDetail.value = 'Cannot test Reverb until API auth succeeds.';
            return;
        case 'offline':
            reverbStatusLabel.value = 'Disconnected';
            reverbStatusDetail.value = 'Unable to reach Atlas.';
            return;
        case 'reverb_unavailable':
            reverbStatusLabel.value = 'Unavailable';
            reverbStatusDetail.value = 'Reverb is not configured on Atlas.';
            reverbEndpoint.value = runtime.endpoint;
            return;
        case 'disconnected':
            reverbStatusLabel.value = 'Disconnected';
            reverbStatusDetail.value = runtime.detail;
            reverbEndpoint.value = runtime.endpoint;
            return;
        case 'connected':
            reverbEndpoint.value = runtime.endpoint;
            activeReverbClient = runtime.client;
            activeReverbEventSubscription = runtime.client.onEvent((eventName, payload) => {
                if (currentSequence !== reverbMonitorSequence) {
                    return;
                }

                pushReverbEvent(createProgressEvent(eventName, payload));
            });
            activeReverbErrorSubscription = runtime.client.onConnectionError((message) => {
                if (currentSequence !== reverbMonitorSequence || reverbStatusLabel.value === 'Connected') {
                    return;
                }

                setDisconnectedReverbDetail('Reverb websocket error.', message);
            });
            activeReverbStateSubscription = runtime.client.onConnectionState((state) => {
                if (currentSequence !== reverbMonitorSequence) {
                    return;
                }

                applyReverbConnectionState(state, runtime.client.getLastConnectionError());
            });
            return;
    }
}

function handleStorageChanged(changes?: Record<string, unknown> | null): void {
    if (isDocumentHidden() || !hasRelevantStorageChanges(changes)) {
        return;
    }

    void refreshReverbMonitor();
}

function handleVisibilityChange(): void {
    if (isDocumentHidden()) {
        return;
    }

    void refreshReverbMonitor();
}

onMounted(() => {
    if (chrome.storage?.onChanged) {
        chrome.storage.onChanged.addListener(handleStorageChanged);
    }

    document.addEventListener('visibilitychange', handleVisibilityChange);

    if (!isDocumentHidden()) {
        void refreshReverbMonitor();
    }
});

onBeforeUnmount(() => {
    reverbMonitorSequence += 1;
    if (chrome.storage?.onChanged) {
        chrome.storage.onChanged.removeListener(handleStorageChanged);
    }
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    disconnectActiveReverbMonitor();
});
</script>

<template>
    <section class="rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/40 p-3 space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <h2 class="text-sm font-medium text-regal-navy-100">Direct Reverb Feed</h2>
                    <Badge :variant="reverbStatusLabel === 'Connected' ? 'active' : 'inactive'">
                        {{ reverbStatusLabel }}
                    </Badge>
                </div>
                <p class="text-xs text-twilight-indigo-300">
                    {{ reverbStatusDetail }}
                </p>
                <p v-if="reverbEndpoint" class="text-xs text-twilight-indigo-300">
                    Reverb Endpoint: <span class="font-mono">{{ reverbEndpoint }}</span>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Badge :variant="reverbEvents.length > 0 ? 'active' : 'inactive'">
                    {{ reverbEvents.length }} events
                </Badge>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    @click="void refreshReverbMonitor()"
                >
                    Reconnect Reverb
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    @click="clearReverbEvents"
                >
                    Clear Events
                </button>
            </div>
        </div>

        <p
            v-if="reverbEvents.length === 0"
            class="rounded-md border border-dashed border-smart-blue-500/30 bg-prussian-blue-800/30 px-3 py-4 text-sm text-twilight-indigo-300"
        >
            No Reverb events received yet.
        </p>

        <div v-else class="max-h-96 space-y-2 overflow-y-auto pr-1">
            <article
                v-for="event in reverbEvents"
                :key="event.id"
                class="rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/55 p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-medium text-regal-navy-100">{{ event.event }}</p>
                    <p class="text-xs text-twilight-indigo-300">{{ event.receivedAt }}</p>
                </div>
                <p class="mt-2 font-mono text-xs text-smart-blue-100">
                    transfer={{ event.transferId ?? '-' }}
                    · file={{ event.fileId ?? '-' }}
                    · status={{ event.status ?? '-' }}
                    · percent={{ event.percent ?? '-' }}
                </p>
                <details class="mt-2">
                    <summary class="cursor-pointer text-xs text-twilight-indigo-200">Payload</summary>
                    <pre class="mt-2 overflow-x-auto rounded-md bg-prussian-blue-900/70 p-3 text-xs text-smart-blue-100">{{ formatJson(event.payload) }}</pre>
                </details>
            </article>
        </div>
    </section>
</template>
