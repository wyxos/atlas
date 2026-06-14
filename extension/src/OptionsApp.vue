<script setup lang="ts">
/* global chrome */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
const activeOptionsTab = ref<'setup' | 'runtime'>('setup');
const newCustomizationDomain = ref('');
const errorMessage = ref('');
const isSaved = ref(false);
const isCustomizationJsonCopied = ref(false);
const statusLabel = ref<'Ready' | 'Setup required' | 'Auth failed' | 'Offline' | 'Checking'>('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref<'Available' | 'Disconnected' | 'Unavailable' | 'Checking'>('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
let customizationCopyTimeout: ReturnType<typeof setTimeout> | null = null;

const selectedCustomization = computed<SiteCustomizationForm | null>(() =>
    siteCustomizationForms.value[selectedCustomizationIndex.value] ?? null);
const apiBadgeVariant = computed<'active' | 'inactive' | 'error' | 'pending'>(() => {
    if (statusLabel.value === 'Ready') {
        return 'active';
    }

    if (statusLabel.value === 'Checking') {
        return 'pending';
    }

    return statusLabel.value === 'Setup required' ? 'inactive' : 'error';
});
const reverbBadgeVariant = computed<'active' | 'inactive' | 'error' | 'pending'>(() => {
    if (reverbStatusLabel.value === 'Available') {
        return 'active';
    }

    if (reverbStatusLabel.value === 'Checking') {
        return 'pending';
    }

    return reverbStatusLabel.value === 'Unavailable' ? 'error' : 'inactive';
});
const apiStatusSummary = computed(() => statusLabel.value === 'Ready'
    ? 'Ready for extension requests.'
    : statusDetail.value);
const reverbStatusDetailClasses = computed(() => [
    'text-sm',
    reverbStatusLabel.value === 'Unavailable' ? 'text-danger-100' : 'text-blue-slate-300',
]);

function splitQueryParamsText(input: string): string[] {
    return input.split(/[,\n]+/);
}

function parseWidgetMinImageWidthText(input: string): number | null {
    const trimmed = input.trim();
    if (trimmed === '') {
        return null;
    }

    return /^\d+$/.test(trimmed) ? Number(trimmed) : Number.NaN;
}

function createCustomizationForm(customization: SiteCustomization): SiteCustomizationForm {
    const widgetMinImageWidth = customization.widget?.minImageWidth ?? null;

    return {
        enabled: customization.enabled,
        domain: customization.domain,
        matchRules: [...customization.matchRules],
        widgetMinImageWidthText: widgetMinImageWidth === null
            ? ''
            : String(widgetMinImageWidth),
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
        enabled: form.enabled,
        domain: form.domain.trim().toLowerCase(),
        matchRules: form.matchRules
            .map((rule) => rule.trim())
            .filter((rule) => rule !== ''),
        widget: {
            minImageWidth: parseWidgetMinImageWidthText(form.widgetMinImageWidthText),
        },
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
    <main class="dark min-h-screen px-4 py-6 app-gradient">
        <div
            class="flex w-full flex-col gap-5"
            data-test-options-shell
        >
            <section class="flex flex-col gap-4 rounded-sm border border-smart-blue-500/25 bg-prussian-blue-700/45 p-5 shadow-lg shadow-prussian-blue-950/10 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-regal-navy-100">Atlas Extension Options</h1>
                    <p class="mt-1 text-sm text-blue-slate-300">
                        Atlas connection, runtime status, and site-specific matching rules.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <Badge :variant="apiBadgeVariant">Atlas {{ statusLabel }}</Badge>
                    <Badge :variant="reverbBadgeVariant">Reverb {{ reverbStatusLabel }}</Badge>
                    <span class="rounded-sm border border-smart-blue-500/30 bg-prussian-blue-900/35 px-3 py-1 text-xs font-medium text-smart-blue-100">
                        Build {{ extensionVersion }}
                    </span>
                </div>
            </section>

            <div data-test-extension-options-tabs>
                <Tabs
                    v-model="activeOptionsTab"
                    class="flex flex-col gap-5"
                >
                    <TabsList
                        class="grid w-full grid-cols-2 rounded-sm border border-smart-blue-500/25 !bg-prussian-blue-900/35 p-1 !text-twilight-indigo-200 shadow-none sm:w-fit"
                        data-test-extension-options-tab-list
                    >
                        <TabsTrigger
                            value="setup"
                            class="min-w-32 rounded-sm border border-transparent !text-twilight-indigo-200 shadow-none hover:border-smart-blue-400/35 hover:!bg-prussian-blue-800/55 data-[state=active]:border-smart-blue-300 data-[state=active]:!bg-smart-blue-500/16 data-[state=active]:!text-smart-blue-100 data-[state=active]:shadow-none dark:data-[state=active]:border-smart-blue-300 dark:data-[state=active]:!bg-smart-blue-500/16 dark:data-[state=active]:!text-smart-blue-100"
                            data-test-options-tab="setup"
                            @click="activeOptionsTab = 'setup'"
                        >
                            Setup
                        </TabsTrigger>
                        <TabsTrigger
                            value="runtime"
                            class="min-w-32 rounded-sm border border-transparent !text-twilight-indigo-200 shadow-none hover:border-smart-blue-400/35 hover:!bg-prussian-blue-800/55 data-[state=active]:border-smart-blue-300 data-[state=active]:!bg-smart-blue-500/16 data-[state=active]:!text-smart-blue-100 data-[state=active]:shadow-none dark:data-[state=active]:border-smart-blue-300 dark:data-[state=active]:!bg-smart-blue-500/16 dark:data-[state=active]:!text-smart-blue-100"
                            data-test-options-tab="runtime"
                            @click="activeOptionsTab = 'runtime'"
                        >
                            Runtime
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="setup">
                        <form
                            class="space-y-6 rounded-sm border border-smart-blue-500/30 bg-prussian-blue-700/55 p-5 shadow-lg shadow-prussian-blue-950/10"
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
                                            class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                        />
                                    </label>

                                    <label class="block space-y-2">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">API Key</span>
                                        <div class="flex items-center gap-2">
                                            <input
                                                v-model="apiToken"
                                                :type="showApiToken ? 'text' : 'password'"
                                                autocomplete="off"
                                                class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                            />
                                            <button
                                                type="button"
                                                class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
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
                                        Build one site profile per page domain, then decide whether Atlas is enabled there before editing matching, referrer cleanup, and media normalization.
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
                                class="rounded-sm border border-danger-500/35 bg-danger-500/10 px-4 py-3 text-sm text-red-200"
                            >
                                {{ errorMessage }}
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-smart-blue-500/20 pt-4">
                                <p class="text-sm text-twilight-indigo-300">
                                    Saving updates the Atlas connection plus the enabled or disabled site profile registry for this browser profile.
                                </p>

                                <div class="flex items-center gap-3">
                                    <button
                                        type="submit"
                                        class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                                    >
                                        Save Changes
                                    </button>

                                    <p v-if="isSaved" class="text-sm text-emerald-300">Saved.</p>
                                </div>
                            </div>
                        </form>
                    </TabsContent>

                    <TabsContent value="runtime">
                        <section class="rounded-sm border border-smart-blue-500/30 bg-prussian-blue-700/55 p-5 shadow-lg shadow-prussian-blue-950/10">
                            <div class="grid gap-4 lg:grid-cols-2">
                                <section class="flex flex-col gap-2 rounded-sm bg-prussian-blue-900/25 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Atlas API</h2>
                                        <Badge :variant="apiBadgeVariant">{{ statusLabel }}</Badge>
                                    </div>
                                    <p
                                        class="text-sm text-blue-slate-300"
                                        data-test-api-status-detail
                                    >
                                        {{ apiStatusSummary }}
                                    </p>
                                </section>

                                <section class="flex flex-col gap-2 rounded-sm bg-prussian-blue-900/25 p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <h2 class="text-sm font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Reverb</h2>
                                        <Badge :variant="reverbBadgeVariant">{{ reverbStatusLabel }}</Badge>
                                    </div>
                                    <p
                                        :class="reverbStatusDetailClasses"
                                        data-test-reverb-status-detail
                                    >
                                        {{ reverbStatusDetail }}
                                    </p>
                                </section>
                            </div>

                            <div class="mt-6 flex flex-col gap-5">
                                <OptionsBackgroundRelayFeed />
                                <OptionsReverbFeed />
                            </div>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </div>
    </main>
</template>
