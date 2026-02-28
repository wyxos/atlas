<script setup lang="ts">
/* global chrome */
import { onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { resolveApiConnectionStatus } from './atlas-api';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;

const statusLabel = ref('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');

function openOptionsPage(): void {
    chrome.runtime.openOptionsPage(() => {
        if (chrome.runtime.lastError) {
            chrome.tabs.create({ url: chrome.runtime.getURL('options.html') });
        }
    });
}

onMounted(() => {
    void resolveApiConnectionStatus().then((status) => {
        statusLabel.value = status.label;
        statusDetail.value = status.detail;
        reverbStatusLabel.value = status.reverbLabel;
        reverbStatusDetail.value = status.reverbDetail;
    });
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
                {{ statusDetail }}
            </p>
            <p class="text-sm text-twilight-indigo-200">
                Reverb:
                <span class="font-medium text-smart-blue-200">{{ reverbStatusLabel }}</span>
                · {{ reverbStatusDetail }}
            </p>

            <button
                type="button"
                class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                @click="openOptionsPage"
            >
                Open Options
            </button>
        </section>
    </main>
</template>
