<script setup lang="ts">
/* global chrome */
import { onMounted, onUnmounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { resolveApiConnectionStatus } from './atlas-api';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;

const statusLabel = ref('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
const reverbEndpoint = ref<string | null>(null);
const tabCount = ref<number | null>(null);
const isDiscardingTabs = ref(false);
const discardTabsResult = ref<string | null>(null);

type DiscardInactiveTabsResponse = {
    ok?: unknown;
    discardedCount?: unknown;
    failedCount?: unknown;
    skippedCount?: unknown;
};

function openOptionsPage(): void {
    chrome.runtime.openOptionsPage(() => {
        if (chrome.runtime.lastError) {
            chrome.tabs.create({ url: chrome.runtime.getURL('options.html') });
        }
    });
}

function toSafeCount(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : 0;
}

function pluralize(value: number, singular: string, plural: string): string {
    return value === 1 ? singular : plural;
}

function refreshTabCount(): void {
    if (!chrome.tabs?.query) {
        tabCount.value = null;
        return;
    }

    chrome.tabs.query({}, (tabs: unknown) => {
        if (chrome.runtime.lastError || !Array.isArray(tabs)) {
            tabCount.value = null;
            return;
        }

        tabCount.value = tabs.length;
    });
}

function handleTabPresenceChanged(): void {
    refreshTabCount();
}

async function discardInactiveTabs(): Promise<void> {
    if (isDiscardingTabs.value) {
        return;
    }

    isDiscardingTabs.value = true;
    discardTabsResult.value = null;

    try {
        const response = await new Promise<DiscardInactiveTabsResponse | null>((resolve) => {
            if (!chrome.runtime?.sendMessage) {
                resolve(null);
                return;
            }

            chrome.runtime.sendMessage({ type: 'ATLAS_DISCARD_INACTIVE_TABS' }, (payload: unknown) => {
                if (chrome.runtime.lastError || typeof payload !== 'object' || payload === null) {
                    resolve(null);
                    return;
                }

                resolve(payload as DiscardInactiveTabsResponse);
            });
        });

        if (!response || response.ok !== true) {
            discardTabsResult.value = 'Failed to discard inactive tabs.';
            return;
        }

        const discardedCount = toSafeCount(response.discardedCount);
        const failedCount = toSafeCount(response.failedCount);
        const skippedCount = toSafeCount(response.skippedCount);
        if (discardedCount === 0 && failedCount === 0 && skippedCount === 0) {
            discardTabsResult.value = 'No inactive tabs to discard.';
            return;
        }

        const segments = [
            `Discarded ${discardedCount} ${pluralize(discardedCount, 'tab', 'tabs')}`,
        ];

        if (skippedCount > 0) {
            segments.push(`skipped ${skippedCount} already discarded`);
        }

        if (failedCount > 0) {
            segments.push(`failed ${failedCount}`);
        }

        discardTabsResult.value = `${segments.join(', ')}.`;
    } catch {
        discardTabsResult.value = 'Failed to discard inactive tabs.';
    } finally {
        isDiscardingTabs.value = false;
    }
}

onMounted(() => {
    void resolveApiConnectionStatus().then((status) => {
        statusLabel.value = status.label;
        statusDetail.value = status.detail;
        reverbStatusLabel.value = status.reverbLabel;
        reverbStatusDetail.value = status.reverbDetail;
        reverbEndpoint.value = status.reverbEndpoint;
    });

    refreshTabCount();
    chrome.tabs?.onCreated?.addListener(handleTabPresenceChanged);
    chrome.tabs?.onRemoved?.addListener(handleTabPresenceChanged);
    chrome.tabs?.onUpdated?.addListener(handleTabPresenceChanged);
});

onUnmounted(() => {
    chrome.tabs?.onCreated?.removeListener(handleTabPresenceChanged);
    chrome.tabs?.onRemoved?.removeListener(handleTabPresenceChanged);
    chrome.tabs?.onUpdated?.removeListener(handleTabPresenceChanged);
});
</script>

<template>
    <main class="min-h-screen p-4 app-gradient">
        <section class="rounded-lg border border-smart-blue-500/30 bg-prussian-blue-700/60 p-4 space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h1 class="text-base font-semibold text-regal-navy-100">Atlas Browser Extension</h1>
                <Badge :variant="statusLabel === 'Ready' ? 'active' : 'inactive'">{{ statusLabel }}</Badge>
            </div>

            <p class="text-sm text-twilight-indigo-200">
                Version
                <span class="font-medium text-smart-blue-200">{{ extensionVersion }}</span>
            </p>
            <p class="text-sm text-twilight-indigo-200">
                Tabs
                <span class="font-medium text-smart-blue-200">{{ tabCount ?? '—' }}</span>
            </p>
            <p class="text-sm text-twilight-indigo-200">
                {{ statusDetail }}
            </p>
            <p class="text-sm text-twilight-indigo-200">
                Reverb:
                <span class="font-medium text-smart-blue-200">{{ reverbStatusLabel }}</span>
                · {{ reverbStatusDetail }}
            </p>
            <p v-if="reverbEndpoint" class="text-xs text-twilight-indigo-300">
                Reverb Endpoint: <span class="font-mono">{{ reverbEndpoint }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    @click="openOptionsPage"
                >
                    Open Options
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30 disabled:cursor-not-allowed disabled:opacity-50"
                    data-test="discard-inactive-tabs"
                    :disabled="isDiscardingTabs"
                    @click="discardInactiveTabs"
                >
                    {{ isDiscardingTabs ? 'Discarding…' : 'Discard Inactive Tabs' }}
                </button>
            </div>
            <p v-if="discardTabsResult" class="text-xs text-twilight-indigo-300">
                {{ discardTabsResult }}
            </p>
        </section>
    </main>
</template>
