<script setup lang="ts">
/* global chrome */
import { computed, onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
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
const statusLabel = ref<'Ready' | 'Setup required' | 'Auth failed' | 'Offline' | 'Checking'>('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref<'Connected' | 'Disconnected' | 'Unavailable' | 'Checking'>('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
const reverbEndpoint = ref<string | null>(null);

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

async function saveOptions(): Promise<void> {
    isSaved.value = false;
    errorMessage.value = '';

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

async function handleImportCustomizations(event: Event): Promise<void> {
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
    <main class="min-h-screen p-4 app-gradient">
        <section class="mx-auto max-w-6xl rounded-lg border border-smart-blue-500/30 bg-prussian-blue-700/60 p-4 space-y-4">
            <header class="space-y-1">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-base font-semibold text-regal-navy-100">Atlas Extension Options</h1>
                    <Badge :variant="statusLabel === 'Ready' ? 'active' : 'inactive'">{{ statusLabel }}</Badge>
                </div>
                <p class="text-sm text-twilight-indigo-200">Configure your Atlas endpoint, API key, and per-site customizations.</p>
                <p class="text-sm text-twilight-indigo-200">
                    Version
                    <span class="font-medium text-smart-blue-200">{{ extensionVersion }}</span>
                </p>
                <p class="text-sm text-twilight-indigo-200">{{ statusDetail }}</p>
                <p class="text-sm text-twilight-indigo-200">
                    Reverb:
                    <span class="font-medium text-smart-blue-200">{{ reverbStatusLabel }}</span>
                    · {{ reverbStatusDetail }}
                </p>
                <p v-if="reverbEndpoint" class="text-xs text-twilight-indigo-300">
                    Reverb Endpoint: <span class="font-mono">{{ reverbEndpoint }}</span>
                </p>
            </header>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                <div class="space-y-4">
                    <OptionsBackgroundRelayFeed />
                    <OptionsReverbFeed />
                </div>

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

                    <SiteCustomizationManager
                        v-model:selected-customization-index="selectedCustomizationIndex"
                        v-model:active-customization-tab="activeCustomizationTab"
                        v-model:new-customization-domain="newCustomizationDomain"
                        :customizations="siteCustomizationForms"
                        :media-cleaner-strategies="MEDIA_CLEANER_STRATEGIES"
                        @add-customization-domain="addCustomizationDomain"
                        @remove-customization="removeCustomization"
                        @add-match-rule="addMatchRule"
                        @remove-match-rule="removeMatchRule"
                        @add-media-rewrite-rule="addMediaRewriteRule"
                        @remove-media-rewrite-rule="removeMediaRewriteRule"
                        @toggle-media-cleaner-strategy="toggleMediaCleanerStrategy"
                        @export-customizations="exportCustomizations"
                        @import-customizations="handleImportCustomizations"
                    />

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
            </div>

            <p v-if="errorMessage" class="text-sm text-red-300">{{ errorMessage }}</p>
        </section>
    </main>
</template>
