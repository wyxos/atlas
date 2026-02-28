<script setup lang="ts">
/* global chrome */
import { onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { resolveApiConnectionStatus } from './atlas-api';
import {
    DEFAULT_ATLAS_DOMAIN,
    getStoredOptions,
    normalizeDomain,
    saveStoredOptions,
    validateDomain,
} from './atlas-options';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;
const atlasDomain = ref(DEFAULT_ATLAS_DOMAIN);
const apiToken = ref('');
const showApiToken = ref(false);
const errorMessage = ref('');
const isSaved = ref(false);
const statusLabel = ref<'Ready' | 'Setup required' | 'Auth failed' | 'Offline' | 'Checking'>('Checking');
const statusDetail = ref('Validating extension API access.');

async function refreshApiConnectionStatus(): Promise<void> {
    const status = await resolveApiConnectionStatus();
    statusLabel.value = status.label;
    statusDetail.value = status.detail;
}

async function saveOptions(): Promise<void> {
    isSaved.value = false;
    errorMessage.value = '';

    const normalizedDomain = normalizeDomain(atlasDomain.value);
    const domainError = validateDomain(normalizedDomain);

    if (domainError !== null) {
        errorMessage.value = domainError;
        return;
    }

    atlasDomain.value = normalizedDomain;
    try {
        await saveStoredOptions(normalizedDomain, apiToken.value);
        isSaved.value = true;
        await refreshApiConnectionStatus();
        setTimeout(() => {
            isSaved.value = false;
        }, 2000);
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to save extension options.';
    }
}

onMounted(() => {
    void getStoredOptions()
        .then((stored) => {
            atlasDomain.value = stored.atlasDomain;
            apiToken.value = stored.apiToken;
            void refreshApiConnectionStatus();
        })
        .catch((error) => {
            errorMessage.value = error instanceof Error ? error.message : 'Failed to load extension options.';
        });
});
</script>

<template>
    <main class="min-h-screen p-4 app-gradient">
        <section class="mx-auto max-w-xl rounded-lg border border-smart-blue-500/30 bg-prussian-blue-700/60 p-4 space-y-4">
            <header class="space-y-1">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-base font-semibold text-regal-navy-100">Atlas Extension Options</h1>
                    <Badge :variant="statusLabel === 'Ready' ? 'active' : 'inactive'">{{ statusLabel }}</Badge>
                </div>
                <p class="text-sm text-twilight-indigo-200">Configure your Atlas endpoint and API key.</p>
                <p class="text-sm text-twilight-indigo-200">
                    Version
                    <span class="font-medium text-smart-blue-200">{{ extensionVersion }}</span>
                </p>
                <p class="text-sm text-twilight-indigo-200">{{ statusDetail }}</p>
            </header>

            <form class="space-y-4" @submit.prevent="saveOptions">
                <label class="block space-y-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Atlas Domain</span>
                    <input
                        v-model="atlasDomain"
                        type="url"
                        placeholder="https://atlas.test"
                        class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                    />
                </label>

                <label class="block space-y-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">API Key</span>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="apiToken"
                            :type="showApiToken ? 'text' : 'password'"
                            autocomplete="off"
                            class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                            @click="showApiToken = !showApiToken"
                        >
                            {{ showApiToken ? 'Hide' : 'Show' }}
                        </button>
                    </div>
                </label>

                <div class="flex items-center gap-3">
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    >
                        Save
                    </button>

                    <p v-if="isSaved" class="text-sm text-emerald-300">Saved.</p>
                </div>
            </form>

            <p v-if="errorMessage" class="text-sm text-red-300">{{ errorMessage }}</p>
        </section>
    </main>
</template>
