<script setup lang="ts">
/* global chrome */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import OptionsBackgroundRelayFeed from './OptionsBackgroundRelayFeed.vue';
import OptionsReverbFeed from './OptionsReverbFeed.vue';
import SiteCustomizationManager from './SiteCustomizationManager.vue';
import { resolveApiConnectionStatus } from './atlas-api';
import {
    DEFAULT_ATLAS_DOMAIN,
    getStoredOptions,
    normalizeDomain,
    saveStoredOptions,
    validateDomain,
} from './atlas-options';
import { validateDomainRule } from './match-rules';
import { normalizeReferrerQueryParams } from './referrer-cleanup';
import {
    type CustomizationTab,
    type SiteCustomizationForm,
} from './options-site-customization-form';
import {
    createEmptySiteCustomization,
    exportSiteCustomizationsPayload,
    MEDIA_CLEANER_STRATEGIES,
    parseSiteCustomizationsImportJson,
    validateSiteCustomizations,
    type MediaCleanerStrategy,
    type SiteCustomization,
} from './site-customizations';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;
const atlasDomain = ref(DEFAULT_ATLAS_DOMAIN);
const apiToken = ref('');
const showApiToken = ref(false);
const siteCustomizationForms = ref<SiteCustomizationForm[]>([]);
const selectedCustomizationIndex = ref(0);
const activeCustomizationTab = ref<CustomizationTab>('matchRules');
const newCustomizationDomain = ref('');
const errorMessage = ref('');
const isSaved = ref(false);
const isCustomizationJsonCopied = ref(false);
const statusLabel = ref<'Ready' | 'Setup required' | 'Auth failed' | 'Offline' | 'Checking'>('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref<'Connected' | 'Disconnected' | 'Unavailable' | 'Checking'>('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
const reverbEndpoint = ref<string | null>(null);
let customizationCopyTimeout: ReturnType<typeof setTimeout> | null = null;

const selectedCustomization = computed<SiteCustomizationForm | null>(() =>
    siteCustomizationForms.value[selectedCustomizationIndex.value] ?? null);

function splitQueryParamsText(input: string): string[] {
    return input.split(/[,\n]+/);
}

function createCustomizationForm(customization: SiteCustomization): SiteCustomizationForm {
    return {
        domain: customization.domain,
        matchRules: [...customization.matchRules],
        referrerCleanerQueryParamsText: customization.referrerCleaner.stripQueryParams.join(', '),
        mediaCleanerQueryParamsText: customization.mediaCleaner.stripQueryParams.join(', '),
        mediaCleanerRewriteRules: customization.mediaCleaner.rewriteRules.map((rule) => ({ ...rule })),
        mediaCleanerStrategies: [...customization.mediaCleaner.strategies],
    };
}

function createCustomizationFormFromDomain(domain: string): SiteCustomizationForm {
    return createCustomizationForm(createEmptySiteCustomization(domain));
}

function buildSiteCustomizationsFromForms(): SiteCustomization[] {
    return siteCustomizationForms.value.map((form) => ({
        domain: form.domain.trim().toLowerCase(),
        matchRules: form.matchRules
            .map((rule) => rule.trim())
            .filter((rule) => rule !== ''),
        referrerCleaner: {
            stripQueryParams: normalizeReferrerQueryParams(splitQueryParamsText(form.referrerCleanerQueryParamsText)),
        },
        mediaCleaner: {
            stripQueryParams: normalizeReferrerQueryParams(splitQueryParamsText(form.mediaCleanerQueryParamsText)),
            rewriteRules: form.mediaCleanerRewriteRules
                .map((rule) => ({
                    pattern: rule.pattern.trim(),
                    replace: rule.replace,
                }))
                .filter((rule) => rule.pattern !== '' || rule.replace !== ''),
            strategies: Array.from(new Set(form.mediaCleanerStrategies)),
        },
    }));
}

function syncFormsFromSiteCustomizations(customizations: SiteCustomization[]): void {
    siteCustomizationForms.value = customizations.map((customization) => createCustomizationForm(customization));
    if (siteCustomizationForms.value.length === 0) {
        selectedCustomizationIndex.value = 0;
        return;
    }

    selectedCustomizationIndex.value = Math.max(
        0,
        Math.min(selectedCustomizationIndex.value, siteCustomizationForms.value.length - 1),
    );
}

async function refreshApiConnectionStatus(): Promise<void> {
    const status = await resolveApiConnectionStatus();
    statusLabel.value = status.label;
    statusDetail.value = status.detail;
    reverbStatusLabel.value = status.reverbLabel;
    reverbStatusDetail.value = status.reverbDetail;
    reverbEndpoint.value = status.reverbEndpoint;
}

function validateCustomizationForms(): SiteCustomization[] | null {
    const siteCustomizations = buildSiteCustomizationsFromForms();
    const validationError = validateSiteCustomizations(siteCustomizations);
    if (validationError !== null) {
        errorMessage.value = validationError;
        return null;
    }

    return siteCustomizations;
}

function resetCustomizationJsonCopied(): void {
    if (customizationCopyTimeout !== null) {
        clearTimeout(customizationCopyTimeout);
        customizationCopyTimeout = null;
    }

    isCustomizationJsonCopied.value = false;
}

function markCustomizationJsonCopied(): void {
    resetCustomizationJsonCopied();
    isCustomizationJsonCopied.value = true;
    customizationCopyTimeout = setTimeout(() => {
        isCustomizationJsonCopied.value = false;
        customizationCopyTimeout = null;
    }, 2000);
}

async function saveOptions(): Promise<void> {
    isSaved.value = false;
    errorMessage.value = '';
    resetCustomizationJsonCopied();

    const normalizedDomain = normalizeDomain(atlasDomain.value);
    const domainError = validateDomain(normalizedDomain);
    if (domainError !== null) {
        errorMessage.value = domainError;
        return;
    }

    const siteCustomizations = validateCustomizationForms();
    if (siteCustomizations === null) {
        return;
    }

    atlasDomain.value = normalizedDomain;

    try {
        await saveStoredOptions(normalizedDomain, apiToken.value, siteCustomizations);
        syncFormsFromSiteCustomizations(siteCustomizations);
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

async function exportCustomizations(): Promise<void> {
    errorMessage.value = '';
    resetCustomizationJsonCopied();

    const siteCustomizations = validateCustomizationForms();
    if (siteCustomizations === null) {
        return;
    }

    const payload = exportSiteCustomizationsPayload(siteCustomizations);
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const downloadUrl = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = downloadUrl;
    anchor.download = 'atlas-site-customizations.json';
    anchor.click();
    URL.revokeObjectURL(downloadUrl);
}

async function copyCustomizationsToClipboard(): Promise<void> {
    errorMessage.value = '';
    resetCustomizationJsonCopied();

    const siteCustomizations = validateCustomizationForms();
    if (siteCustomizations === null) {
        return;
    }

    if (!navigator.clipboard?.writeText) {
        errorMessage.value = 'Clipboard access is unavailable in this browser context.';
        return;
    }

    try {
        await navigator.clipboard.writeText(JSON.stringify(exportSiteCustomizationsPayload(siteCustomizations), null, 2));
        markCustomizationJsonCopied();
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to copy customization JSON.';
    }
}

async function handleImportCustomizations(event: Event): Promise<void> {
    resetCustomizationJsonCopied();
    const target = event.target as HTMLInputElement | null;
    const file = target?.files?.[0];
    if (!file) {
        return;
    }

    try {
        const text = await file.text();
        const importedSiteCustomizations = parseSiteCustomizationsImportJson(text);
        syncFormsFromSiteCustomizations(importedSiteCustomizations);
        activeCustomizationTab.value = 'matchRules';
        errorMessage.value = '';
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Failed to import customization JSON.';
    } finally {
        if (target) {
            target.value = '';
        }
    }
}

onMounted(() => {
    void getStoredOptions()
        .then((stored) => {
            atlasDomain.value = stored.atlasDomain;
            apiToken.value = stored.apiToken;
            syncFormsFromSiteCustomizations(stored.siteCustomizations);
            void refreshApiConnectionStatus();
        })
        .catch((error) => {
            errorMessage.value = error instanceof Error ? error.message : 'Failed to load extension options.';
        });
});

onBeforeUnmount(() => {
    resetCustomizationJsonCopied();
});

function addCustomizationDomain(): void {
    const domain = newCustomizationDomain.value.trim().toLowerCase();
    const domainError = validateDomainRule(domain);
    if (domainError !== null) {
        errorMessage.value = domainError;
        return;
    }

    if (siteCustomizationForms.value.some((customization) => customization.domain === domain)) {
        errorMessage.value = `Domain "${domain}" already exists.`;
        return;
    }

    siteCustomizationForms.value.push(createCustomizationFormFromDomain(domain));
    selectedCustomizationIndex.value = siteCustomizationForms.value.length - 1;
    activeCustomizationTab.value = 'matchRules';
    newCustomizationDomain.value = '';
    errorMessage.value = '';
}

function removeCustomization(index: number): void {
    siteCustomizationForms.value.splice(index, 1);
    if (siteCustomizationForms.value.length === 0) {
        selectedCustomizationIndex.value = 0;
        return;
    }

    if (selectedCustomizationIndex.value >= siteCustomizationForms.value.length) {
        selectedCustomizationIndex.value = siteCustomizationForms.value.length - 1;
    }
}

function addMatchRule(): void {
    selectedCustomization.value?.matchRules.push('');
}

function removeMatchRule(index: number): void {
    selectedCustomization.value?.matchRules.splice(index, 1);
}

function addMediaRewriteRule(): void {
    selectedCustomization.value?.mediaCleanerRewriteRules.push({
        pattern: '',
        replace: '',
    });
}

function removeMediaRewriteRule(index: number): void {
    selectedCustomization.value?.mediaCleanerRewriteRules.splice(index, 1);
}

function toggleMediaCleanerStrategy(strategy: MediaCleanerStrategy): void {
    const customization = selectedCustomization.value;
    if (customization === null) {
        return;
    }

    if (customization.mediaCleanerStrategies.includes(strategy)) {
        customization.mediaCleanerStrategies = customization.mediaCleanerStrategies.filter((value) => value !== strategy);
        return;
    }

    customization.mediaCleanerStrategies = [...customization.mediaCleanerStrategies, strategy];
}
</script>

<template>
    <main class="min-h-screen px-4 py-6 app-gradient">
        <div class="mx-auto w-full max-w-[1920px] space-y-6">
            <section class="rounded-[28px] border border-smart-blue-500/30 bg-prussian-blue-700/60 p-5 shadow-2xl shadow-prussian-blue-950/15">
                <PageHeader
                    title="Atlas Extension Options"
                    subtitle="Configure the Atlas connection, keep an eye on live runtime status, and manage site-specific matching and cleanup rules."
                />

                <div class="grid gap-4 lg:grid-cols-3">
                    <article class="rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-900/30 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Atlas API</p>
                                <h2 class="mt-2 text-lg font-semibold text-regal-navy-100">Connection Status</h2>
                            </div>
                            <Badge :variant="statusLabel === 'Ready' ? 'active' : 'inactive'">{{ statusLabel }}</Badge>
                        </div>
                        <p class="mt-3 text-sm text-blue-slate-300">{{ statusDetail }}</p>
                    </article>

                    <article class="rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-900/30 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Reverb</p>
                                <h2 class="mt-2 text-lg font-semibold text-regal-navy-100">Realtime Channel</h2>
                            </div>
                            <Badge :variant="reverbStatusLabel === 'Connected' ? 'active' : 'inactive'">
                                {{ reverbStatusLabel }}
                            </Badge>
                        </div>
                        <p class="mt-3 text-sm text-blue-slate-300">{{ reverbStatusDetail }}</p>
                        <p v-if="reverbEndpoint" class="mt-3 font-mono text-xs text-smart-blue-100">
                            {{ reverbEndpoint }}
                        </p>
                    </article>

                    <article class="rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-900/30 p-4">
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Extension Build</p>
                        <p class="mt-3 text-3xl font-semibold text-smart-blue-100">{{ extensionVersion }}</p>
                        <p class="mt-3 text-sm text-blue-slate-300">
                            Site customizations stay local to this browser profile. Use the JSON import and export actions to move them elsewhere.
                        </p>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(380px,0.82fr)]">
                <form
                    class="space-y-6 rounded-[28px] border border-smart-blue-500/30 bg-prussian-blue-700/55 p-5 shadow-2xl shadow-prussian-blue-950/15"
                    @submit.prevent="saveOptions"
                >
                    <section class="space-y-4">
                        <div class="border-b border-smart-blue-500/20 pb-4">
                            <h2 class="text-lg font-semibold text-regal-navy-100">Connection</h2>
                            <p class="mt-1 text-sm text-blue-slate-300">
                                Atlas domain and API key used by extension requests, auth checks, and Reverb discovery.
                            </p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="block space-y-2">
                                <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Atlas Domain</span>
                                <input
                                    v-model="atlasDomain"
                                    type="url"
                                    placeholder="https://atlas.test"
                                    class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                />
                            </label>

                            <label class="block space-y-2">
                                <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">API Key</span>
                                <div class="flex items-center gap-2">
                                    <input
                                        v-model="apiToken"
                                        :type="showApiToken ? 'text' : 'password'"
                                        autocomplete="off"
                                        class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                    />
                                    <button
                                        type="button"
                                        class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                                        @click="showApiToken = !showApiToken"
                                    >
                                        {{ showApiToken ? 'Hide' : 'Show' }}
                                    </button>
                                </div>
                            </label>
                        </div>
                    </section>

                    <section class="space-y-4 border-t border-smart-blue-500/20 pt-6">
                        <div class="border-b border-smart-blue-500/20 pb-4">
                            <h2 class="text-lg font-semibold text-regal-navy-100">Customizations</h2>
                            <p class="mt-1 text-sm text-blue-slate-300">
                                Build one site profile per page domain and keep matching, referrer cleanup, and media normalization together.
                            </p>
                        </div>

                        <SiteCustomizationManager
                            v-model:selected-customization-index="selectedCustomizationIndex"
                            v-model:active-customization-tab="activeCustomizationTab"
                            v-model:new-customization-domain="newCustomizationDomain"
                            :customizations="siteCustomizationForms"
                            :is-customization-json-copied="isCustomizationJsonCopied"
                            :media-cleaner-strategies="MEDIA_CLEANER_STRATEGIES"
                            @add-customization-domain="addCustomizationDomain"
                            @remove-customization="removeCustomization"
                            @add-match-rule="addMatchRule"
                            @remove-match-rule="removeMatchRule"
                            @add-media-rewrite-rule="addMediaRewriteRule"
                            @remove-media-rewrite-rule="removeMediaRewriteRule"
                            @toggle-media-cleaner-strategy="toggleMediaCleanerStrategy"
                            @copy-customizations="copyCustomizationsToClipboard"
                            @export-customizations="exportCustomizations"
                            @import-customizations="handleImportCustomizations"
                        />
                    </section>

                    <div
                        v-if="errorMessage"
                        class="rounded-2xl border border-danger-500/35 bg-danger-500/10 px-4 py-3 text-sm text-red-200"
                    >
                        {{ errorMessage }}
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-smart-blue-500/20 pt-4">
                        <p class="text-sm text-twilight-indigo-300">
                            Saving updates the Atlas connection and the current site profile registry for this browser profile.
                        </p>

                        <div class="flex items-center gap-3">
                            <button
                                type="submit"
                                class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                            >
                                Save Changes
                            </button>

                            <p v-if="isSaved" class="text-sm text-emerald-300">Saved.</p>
                        </div>
                    </div>
                </form>

                <section class="rounded-[28px] border border-smart-blue-500/30 bg-prussian-blue-700/55 p-5 shadow-2xl shadow-prussian-blue-950/15">
                    <div class="border-b border-smart-blue-500/20 pb-4">
                        <h2 class="text-lg font-semibold text-regal-navy-100">Runtime Diagnostics</h2>
                        <p class="mt-1 text-sm text-blue-slate-300">
                            Use these feeds to verify the background relay and direct Reverb connection without leaving the options page.
                        </p>
                    </div>

                    <div class="mt-5 space-y-4">
                        <OptionsBackgroundRelayFeed />
                        <OptionsReverbFeed />
                    </div>
                </section>
            </div>
        </div>
    </main>
</template>
