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
    type UrlMatchRule,
    validateDomain,
} from './atlas-options';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;
const atlasDomain = ref(DEFAULT_ATLAS_DOMAIN);
const apiToken = ref('');
const showApiToken = ref(false);
const matchRulesText = ref('');
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

    const parsedRules = parseMatchRulesText(matchRulesText.value);
    if (parsedRules.error) {
        errorMessage.value = parsedRules.error;
        return;
    }

    atlasDomain.value = normalizedDomain;
    try {
        await saveStoredOptions(normalizedDomain, apiToken.value, parsedRules.rules);
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
            matchRulesText.value = formatMatchRules(stored.matchRules);
            void refreshApiConnectionStatus();
        })
        .catch((error) => {
            errorMessage.value = error instanceof Error ? error.message : 'Failed to load extension options.';
        });
});

function formatMatchRules(rules: UrlMatchRule[]): string {
    return rules
        .flatMap((rule) => rule.regexes.map((regex) => `${rule.domain}|${regex}`))
        .join('\n');
}

function parseMatchRulesText(input: string): { rules: UrlMatchRule[]; error: string | null } {
    const grouped = new Map<string, string[]>();
    const lines = input
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '');

    for (const line of lines) {
        const separatorIndex = line.indexOf('|');
        if (separatorIndex <= 0 || separatorIndex === line.length - 1) {
            return { rules: [], error: `Invalid rule format: "${line}". Use domain|regex.` };
        }

        const domain = line.slice(0, separatorIndex).trim().toLowerCase();
        const regexPattern = line.slice(separatorIndex + 1).trim();

        if (domain === '' || regexPattern === '') {
            return { rules: [], error: `Invalid rule format: "${line}". Use domain|regex.` };
        }

        try {
            // Validate regex at save time so content script never throws on invalid patterns.
             
            new RegExp(regexPattern, 'i');
        } catch {
            return { rules: [], error: `Invalid regex in rule: "${line}".` };
        }

        if (!grouped.has(domain)) {
            grouped.set(domain, []);
        }

        grouped.get(domain)!.push(regexPattern);
    }

    return {
        rules: Array.from(grouped.entries()).map(([domain, regexes]) => ({
            domain,
            regexes: Array.from(new Set(regexes)),
        })),
        error: null,
    };
}
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

                <label class="block space-y-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">
                        URL Match Rules
                    </span>
                    <textarea
                        v-model="matchRulesText"
                        rows="8"
                        placeholder="deviantart.com|^https://(www\\.)?deviantart\\.com/.+\nimages-wixmp.com|^https://images-wixmp\\.com/.+"
                        class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                    />
                    <p class="text-xs text-twilight-indigo-300">
                        One rule per line: <code>domain|regex</code>. Subdomains are included.
                    </p>
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
