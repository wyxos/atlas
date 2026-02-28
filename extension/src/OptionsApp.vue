<script setup lang="ts">
/* global chrome */
import { onMounted, ref } from 'vue';

const STORAGE_KEYS = {
    atlasDomain: 'atlasDomain',
    apiToken: 'apiToken',
} as const;

const atlasDomain = ref('');
const apiToken = ref('');
const errorMessage = ref('');
const isSaved = ref(false);

function normalizeDomain(input: string): string {
    return input.trim().replace(/\/+$/, '');
}

function validateDomain(input: string): string | null {
    if (input === '') {
        return 'Atlas domain is required.';
    }

    if (! /^https?:\/\//i.test(input)) {
        return 'Atlas domain must start with http:// or https://.';
    }

    try {
        // Ensures host/protocol shape is valid.
        new URL(input);
    } catch {
        return 'Atlas domain is not a valid URL.';
    }

    return null;
}

function saveOptions(): void {
    isSaved.value = false;
    errorMessage.value = '';

    const normalizedDomain = normalizeDomain(atlasDomain.value);
    const domainError = validateDomain(normalizedDomain);

    if (domainError !== null) {
        errorMessage.value = domainError;
        return;
    }

    atlasDomain.value = normalizedDomain;

    chrome.storage.local.set(
        {
            [STORAGE_KEYS.atlasDomain]: normalizedDomain,
            [STORAGE_KEYS.apiToken]: apiToken.value.trim(),
        },
        () => {
            if (chrome.runtime.lastError) {
                errorMessage.value = chrome.runtime.lastError.message;
                return;
            }

            isSaved.value = true;
            setTimeout(() => {
                isSaved.value = false;
            }, 2000);
        },
    );
}

onMounted(() => {
    chrome.storage.local.get([STORAGE_KEYS.atlasDomain, STORAGE_KEYS.apiToken], (stored) => {
        if (chrome.runtime.lastError) {
            errorMessage.value = chrome.runtime.lastError.message;
            return;
        }

        atlasDomain.value = typeof stored.atlasDomain === 'string' ? normalizeDomain(stored.atlasDomain) : '';
        apiToken.value = typeof stored.apiToken === 'string' ? stored.apiToken : '';
    });
});
</script>

<template>
    <main class="min-h-screen p-4 app-gradient">
        <section class="mx-auto max-w-xl rounded-lg border border-smart-blue-500/30 bg-prussian-blue-700/60 p-4 space-y-4">
            <header class="space-y-1">
                <h1 class="text-base font-semibold text-regal-navy-100">Atlas Extension Options</h1>
                <p class="text-sm text-twilight-indigo-200">Configure your Atlas endpoint and API token.</p>
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
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">API Token</span>
                    <input
                        v-model="apiToken"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                    />
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
