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
import {
    normalizeMatchRules,
    type UrlMatchRule,
    validateDomainRule,
    validateRegexPattern,
} from './match-rules';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;
const atlasDomain = ref(DEFAULT_ATLAS_DOMAIN);
const apiToken = ref('');
const showApiToken = ref(false);
const matchRules = ref<UrlMatchRule[]>([]);
const newRuleDomain = ref('');
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

    const normalizedRules = normalizeMatchRules(matchRules.value);
    for (const rule of normalizedRules) {
        const ruleDomainError = validateDomainRule(rule.domain);
        if (ruleDomainError !== null) {
            errorMessage.value = ruleDomainError;
            return;
        }

        if (rule.regexes.length === 0) {
            errorMessage.value = `Domain "${rule.domain}" must have at least one regex.`;
            return;
        }

        for (const regex of rule.regexes) {
            const regexError = validateRegexPattern(regex);
            if (regexError !== null) {
                errorMessage.value = regexError;
                return;
            }
        }
    }

    atlasDomain.value = normalizedDomain;
    try {
        await saveStoredOptions(normalizedDomain, apiToken.value, normalizedRules);
        isSaved.value = true;
        await refreshApiConnectionStatus();
        setTimeout(() => {
            isSaved.value = false;
        }, 2000);
        errorMessage.value = '';
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to save extension options.';
    }
}

onMounted(() => {
    void getStoredOptions()
        .then((stored) => {
            atlasDomain.value = stored.atlasDomain;
            apiToken.value = stored.apiToken;
            matchRules.value = stored.matchRules.map((rule) => ({
                domain: rule.domain,
                regexes: [...rule.regexes],
            }));
            void refreshApiConnectionStatus();
        })
        .catch((error) => {
            errorMessage.value = error instanceof Error ? error.message : 'Failed to load extension options.';
        });
});

function addRuleDomain(): void {
    const domain = newRuleDomain.value.trim().toLowerCase();
    const domainError = validateDomainRule(domain);
    if (domainError !== null) {
        errorMessage.value = domainError;
        return;
    }

    if (matchRules.value.some((rule) => rule.domain === domain)) {
        errorMessage.value = `Domain "${domain}" already exists.`;
        return;
    }

    matchRules.value.push({
        domain,
        regexes: [''],
    });
    newRuleDomain.value = '';
    errorMessage.value = '';
}

function removeRuleDomain(index: number): void {
    matchRules.value.splice(index, 1);
}

function addRegex(domainIndex: number): void {
    matchRules.value[domainIndex].regexes.push('');
}

function removeRegex(domainIndex: number, regexIndex: number): void {
    matchRules.value[domainIndex].regexes.splice(regexIndex, 1);
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

                <div class="space-y-2">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">URL Match Rules</span>
                    <div class="flex items-center gap-2">
                        <input
                            v-model="newRuleDomain"
                            type="text"
                            placeholder="Add domain (e.g. deviantart.com)"
                            class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                            @click="addRuleDomain"
                        >
                            Add Domain
                        </button>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="(rule, domainIndex) in matchRules"
                            :key="`${rule.domain}-${domainIndex}`"
                            class="rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/40 p-3 space-y-2"
                        >
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="rule.domain"
                                    type="text"
                                    class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-2 py-1 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                                />
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-md border border-danger-500/60 bg-danger-500/20 px-2 py-1 text-xs font-medium text-danger-100 transition hover:bg-danger-500/30"
                                    @click="removeRuleDomain(domainIndex)"
                                >
                                    Delete
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div
                                    v-for="(_, regexIndex) in rule.regexes"
                                    :key="`${rule.domain}-${regexIndex}`"
                                    class="flex items-center gap-2"
                                >
                                    <input
                                        v-model="rule.regexes[regexIndex]"
                                        type="text"
                                        placeholder="Regex pattern (e.g. /art/)"
                                        class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-2 py-1 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                                    />
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-md border border-danger-500/60 bg-danger-500/20 px-2 py-1 text-xs font-medium text-danger-100 transition hover:bg-danger-500/30"
                                        @click="removeRegex(domainIndex, regexIndex)"
                                    >
                                        Delete
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-2 py-1 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                                    @click="addRegex(domainIndex)"
                                >
                                    Add Regex
                                </button>
                            </div>
                        </div>
                    </div>

                    <p class="text-xs text-twilight-indigo-300">
                        Subdomains are included. URLs are sent only when one of the domain regex rules matches.
                    </p>
                </div>

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
