<script setup lang="ts">
/* global chrome */
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Badge from '@/components/ui/Badge.vue';
import { getStoredOptions, setSiteCustomizationEnabledForDomain } from './atlas-options';
import { resolveStoredSiteCustomizationForHostname } from './site-customizations';
import { formatTabCountSummary, summarizeTabCounts, type BrowserTabLike, type TabCountSummary } from './tab-counts';

const extensionVersion = chrome.runtime.getManifest().version || __ATLAS_EXTENSION_VERSION__;

const statusLabel = ref('Checking');
const statusDetail = ref('Validating extension API access.');
const reverbStatusLabel = ref('Checking');
const reverbStatusDetail = ref('Checking Reverb connection.');
const reverbEndpoint = ref<string | null>(null);
const tabCountSummary = ref<TabCountSummary | null>(null);
const currentSiteHostname = ref<string | null>(null);
const currentSiteProfileDomain = ref<string | null>(null);
const currentSiteEnabled = ref<boolean | null>(null);
const currentSiteError = ref<string | null>(null);
const isDiscardingTabs = ref(false);
const isUpdatingCurrentSite = ref(false);
const discardTabsResult = ref<string | null>(null);
let statusRefreshHandle: number | null = null;
let tabCountRefreshHandle: number | null = null;
let isPopupActive = true;
const tabCountLabel = computed(() => formatTabCountSummary(tabCountSummary.value));
const currentSiteStatusLabel = computed(() => {
    if (currentSiteHostname.value === null) {
        return 'Unavailable';
    }

    return currentSiteEnabled.value === true ? 'Enabled' : 'Disabled';
});
const currentSiteDetail = computed(() => {
    if (currentSiteHostname.value === null) {
        return 'Open a regular http or https page to manage Atlas site access.';
    }

    if (currentSiteProfileDomain.value !== null && currentSiteProfileDomain.value !== currentSiteHostname.value) {
        return `Using the ${currentSiteProfileDomain.value} profile for ${currentSiteHostname.value}.`;
    }

    return `Manage Atlas access for ${currentSiteHostname.value}.`;
});
const currentSiteToggleLabel = computed(() => currentSiteEnabled.value === true
    ? 'Disable on This Site'
    : 'Enable on This Site');

type DiscardInactiveTabsResponse = {
    ok?: unknown;
    discardedCount?: unknown;
    failedCount?: unknown;
    skippedCount?: unknown;
};

type BrowserTab = BrowserTabLike & {
    active?: boolean;
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

function resolveManageableHostname(url: string | undefined): string | null {
    if (typeof url !== 'string' || url.trim() === '') {
        return null;
    }

    try {
        const parsed = new URL(url);
        if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') {
            return null;
        }

        return parsed.hostname.toLowerCase();
    } catch {
        return null;
    }
}

function queryTabs(queryInfo: Record<string, unknown>): Promise<BrowserTab[] | null> {
    if (!chrome.tabs?.query) {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        chrome.tabs.query(queryInfo, (tabs: unknown) => {
            if (chrome.runtime.lastError || !Array.isArray(tabs)) {
                resolve(null);
                return;
            }

            resolve(tabs as BrowserTab[]);
        });
    });
}

async function refreshCurrentSiteState(activeTabUrl: string | undefined): Promise<void> {
    const hostname = resolveManageableHostname(activeTabUrl);
    if (hostname === null) {
        currentSiteHostname.value = null;
        currentSiteProfileDomain.value = null;
        currentSiteEnabled.value = null;
        currentSiteError.value = null;
        return;
    }

    try {
        const stored = await getStoredOptions();
        if (!isPopupActive) {
            return;
        }

        const customization = resolveStoredSiteCustomizationForHostname(stored.siteCustomizations, hostname);
        currentSiteHostname.value = hostname;
        currentSiteProfileDomain.value = customization?.domain ?? hostname;
        currentSiteEnabled.value = customization?.enabled === true;
        currentSiteError.value = null;
    } catch (error) {
        currentSiteHostname.value = hostname;
        currentSiteProfileDomain.value = hostname;
        currentSiteEnabled.value = false;
        currentSiteError.value = error instanceof Error ? error.message : 'Failed to load the current site profile.';
    }
}

async function refreshTabCount(): Promise<void> {
    const [tabs, activeTabs] = await Promise.all([
        queryTabs({}),
        queryTabs({ active: true, currentWindow: true }),
    ]);

    if (!isPopupActive) {
        return;
    }

    if (tabs === null) {
        tabCountSummary.value = null;
        await refreshCurrentSiteState(activeTabs?.[0]?.url);
        return;
    }

    tabCountSummary.value = summarizeTabCounts(tabs, activeTabs?.[0]?.url ?? null);
    await refreshCurrentSiteState(activeTabs?.[0]?.url);
}

function handleTabPresenceChanged(): void {
    if (tabCountRefreshHandle !== null) {
        return;
    }

    tabCountRefreshHandle = window.setTimeout(() => {
        tabCountRefreshHandle = null;
        void refreshTabCount();
    }, 0);
}

async function refreshConnectionStatus(): Promise<void> {
    const { resolveApiConnectionStatus } = await import('./atlas-api');
    const status = await resolveApiConnectionStatus();
    if (!isPopupActive) {
        return;
    }

    statusLabel.value = status.label;
    statusDetail.value = status.detail;
    reverbStatusLabel.value = status.reverbLabel;
    reverbStatusDetail.value = status.reverbDetail;
    reverbEndpoint.value = status.reverbEndpoint;
}

function scheduleConnectionStatusRefresh(): void {
    if (statusRefreshHandle !== null) {
        window.clearTimeout(statusRefreshHandle);
    }

    statusRefreshHandle = window.setTimeout(() => {
        statusRefreshHandle = null;
        void refreshConnectionStatus();
    }, 0);
}

async function toggleCurrentSite(): Promise<void> {
    const targetDomain = currentSiteProfileDomain.value ?? currentSiteHostname.value;
    if (targetDomain === null) {
        return;
    }

    isUpdatingCurrentSite.value = true;
    currentSiteError.value = null;

    try {
        await setSiteCustomizationEnabledForDomain(targetDomain, currentSiteEnabled.value !== true);
        const activeTabs = await queryTabs({ active: true, currentWindow: true });
        if (!isPopupActive) {
            return;
        }

        await refreshCurrentSiteState(activeTabs?.[0]?.url);
    } catch (error) {
        currentSiteError.value = error instanceof Error ? error.message : 'Failed to update the current site profile.';
    } finally {
        isUpdatingCurrentSite.value = false;
    }
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
    isPopupActive = true;
    void refreshTabCount();
    scheduleConnectionStatusRefresh();
    chrome.tabs?.onCreated?.addListener(handleTabPresenceChanged);
    chrome.tabs?.onRemoved?.addListener(handleTabPresenceChanged);
    chrome.tabs?.onUpdated?.addListener(handleTabPresenceChanged);
    chrome.tabs?.onActivated?.addListener(handleTabPresenceChanged);
});

onUnmounted(() => {
    isPopupActive = false;
    if (statusRefreshHandle !== null) {
        window.clearTimeout(statusRefreshHandle);
        statusRefreshHandle = null;
    }
    if (tabCountRefreshHandle !== null) {
        window.clearTimeout(tabCountRefreshHandle);
        tabCountRefreshHandle = null;
    }

    chrome.tabs?.onCreated?.removeListener(handleTabPresenceChanged);
    chrome.tabs?.onRemoved?.removeListener(handleTabPresenceChanged);
    chrome.tabs?.onUpdated?.removeListener(handleTabPresenceChanged);
    chrome.tabs?.onActivated?.removeListener(handleTabPresenceChanged);
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
                <span class="font-medium text-smart-blue-200">{{ tabCountLabel }}</span>
            </p>
            <p class="text-sm text-twilight-indigo-200">
                This Site
                <span class="font-medium text-smart-blue-200">{{ currentSiteStatusLabel }}</span>
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
            <p class="text-xs text-twilight-indigo-300">
                {{ currentSiteDetail }}
            </p>
            <p v-if="currentSiteError" class="text-xs text-red-200">
                {{ currentSiteError }}
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border px-3 py-2 text-sm font-medium transition disabled:cursor-not-allowed disabled:opacity-50"
                    :class="currentSiteEnabled === true
                        ? 'border-emerald-400/60 bg-emerald-500/15 text-emerald-100 hover:bg-emerald-500/25'
                        : 'border-smart-blue-400/60 bg-smart-blue-500/20 text-smart-blue-100 hover:bg-smart-blue-500/30'"
                    data-test="toggle-current-site"
                    :disabled="currentSiteHostname === null || isUpdatingCurrentSite"
                    @click="toggleCurrentSite"
                >
                    {{ isUpdatingCurrentSite ? 'Saving…' : currentSiteToggleLabel }}
                </button>
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
