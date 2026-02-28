<script setup lang="ts">
/* global chrome */
import { onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';

const extensionVersion = __ATLAS_EXTENSION_VERSION__;
const STORAGE_KEYS = {
    atlasDomain: 'atlasDomain',
    apiToken: 'apiToken',
} as const;
const DEFAULT_ATLAS_DOMAIN = 'https://atlas.test';

const statusLabel = ref('Checking');
const statusDetail = ref('Validating extension API access.');

function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

async function checkApiConnection(): Promise<void> {
    try {
        const stored = await new Promise<Record<string, unknown>>((resolve, reject) => {
            chrome.storage.local.get([STORAGE_KEYS.atlasDomain, STORAGE_KEYS.apiToken], (result) => {
                if (chrome.runtime.lastError) {
                    reject(new Error(chrome.runtime.lastError.message));
                    return;
                }

                resolve(result);
            });
        });
        const atlasDomain = normalizeDomain(typeof stored.atlasDomain === 'string' ? stored.atlasDomain : '');
        const apiToken = typeof stored.apiToken === 'string' ? stored.apiToken.trim() : '';
        const domain = atlasDomain !== '' ? atlasDomain : DEFAULT_ATLAS_DOMAIN;

        if (apiToken === '') {
            statusLabel.value = 'Setup required';
            statusDetail.value = 'Set the API key in extension options before using Atlas API actions.';
            return;
        }

        const response = await fetch(`${domain}/api/extension/ping`, {
            method: 'GET',
            headers: {
                'X-Atlas-Api-Key': apiToken,
            },
        });

        if (response.ok) {
            statusLabel.value = 'Ready';
            statusDetail.value = `Connected to ${domain}`;
            return;
        }

        statusLabel.value = 'Auth failed';
        statusDetail.value = 'API key or domain is invalid. Update extension options.';
    } catch {
        statusLabel.value = 'Offline';
        statusDetail.value = 'Unable to verify API access. Check extension options.';
    }
}

function openOptionsPage(): void {
    chrome.runtime.openOptionsPage(() => {
        if (chrome.runtime.lastError) {
            chrome.tabs.create({ url: chrome.runtime.getURL('options.html') });
        }
    });
}

onMounted(() => {
    void checkApiConnection();
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
