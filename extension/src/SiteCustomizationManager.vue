<script setup lang="ts">
import { computed, ref } from 'vue';
import { Ban, Check, Trash2 } from 'lucide-vue-next';
import {
    CUSTOMIZATION_TABS,
    type CustomizationTab,
    type SiteCustomizationForm,
} from './options-site-customization-form';
import {
    CUSTOMIZATION_TAB_META,
} from './site-customization-manager-meta';
import type { MediaCleanerStrategy } from './site-customizations';
import SiteCustomizationMediaCleanerPanel from './SiteCustomizationMediaCleanerPanel.vue';
import SiteCustomizationWidgetPanel from './SiteCustomizationWidgetPanel.vue';

type ProfileStatusFilter = 'all' | 'enabled' | 'disabled';

const props = defineProps<{
    customizations: SiteCustomizationForm[];
    selectedCustomizationIndex: number;
    activeCustomizationTab: CustomizationTab;
    newCustomizationDomain: string;
    isCustomizationJsonCopied: boolean;
    mediaCleanerStrategies: readonly MediaCleanerStrategy[];
}>();

const emit = defineEmits<{
    'update:selectedCustomizationIndex': [value: number];
    'update:activeCustomizationTab': [value: CustomizationTab];
    'update:newCustomizationDomain': [value: string];
    'add-customization-domain': [];
    'remove-customization': [index: number];
    'add-match-rule': [];
    'remove-match-rule': [index: number];
    'add-media-rewrite-rule': [];
    'remove-media-rewrite-rule': [index: number];
    'toggle-media-cleaner-strategy': [strategy: MediaCleanerStrategy];
    'copy-customizations': [];
    'export-customizations': [];
    'import-customizations': [event: Event];
}>();

const importFileInput = ref<HTMLInputElement | null>(null);
const profileSearchQuery = ref('');
const profileStatusFilter = ref<ProfileStatusFilter>('all');
const selectedCustomization = computed<SiteCustomizationForm | null>(() =>
    props.customizations[props.selectedCustomizationIndex] ?? null);
const activeTabMeta = computed(() => CUSTOMIZATION_TAB_META[props.activeCustomizationTab]);
const normalizedProfileSearchQuery = computed(() => profileSearchQuery.value.trim().toLowerCase());
const profileStatusFilters: Array<{ id: ProfileStatusFilter; label: string }> = [
    { id: 'all', label: 'All' },
    { id: 'enabled', label: 'Enabled' },
    { id: 'disabled', label: 'Disabled' },
];
const filteredProfileEntries = computed(() => props.customizations
    .map((customization, index) => ({ customization, index }))
    .filter(({ customization }) => {
        const matchesSearch = normalizedProfileSearchQuery.value === ''
            || customization.domain.toLowerCase().includes(normalizedProfileSearchQuery.value);
        const matchesStatus = profileStatusFilter.value === 'all'
            || (profileStatusFilter.value === 'enabled' && customization.enabled)
            || (profileStatusFilter.value === 'disabled' && !customization.enabled);

        return matchesSearch && matchesStatus;
    }));
function triggerImportCustomizations(): void {
    importFileInput.value?.click();
}

function handleImportCustomizations(event: Event): void {
    emit('import-customizations', event);
}

function updateMediaCleanerRewriteRule(
    index: number,
    field: 'pattern' | 'replace',
    value: string,
): void {
    const rewriteRule = selectedCustomization.value?.mediaCleanerRewriteRules[index];
    if (!rewriteRule) {
        return;
    }

    rewriteRule[field] = value;
}
</script>

<template>
    <section class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-lg font-semibold text-regal-navy-100">Site profiles</h2>
                <p class="text-sm text-blue-slate-300">
                    Keep one profile per page domain, then decide whether Atlas is active there before tuning matching,
                    referrer cleanup, and media URL normalization for that site.
                </p>
            </div>

            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
                        data-test-copy-customizations
                        @click="emit('copy-customizations')"
                    >
                        Copy JSON
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
                        data-test-export-customizations
                        @click="emit('export-customizations')"
                    >
                        Export JSON
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
                        data-test-import-customizations
                        @click="triggerImportCustomizations"
                    >
                        Import JSON
                    </button>
                    <input
                        ref="importFileInput"
                        type="file"
                        accept="application/json,.json"
                        class="hidden"
                        data-test-import-file-input
                        @change="handleImportCustomizations"
                    />
                </div>
                <p v-if="isCustomizationJsonCopied" class="text-right text-xs text-emerald-300">
                    Copied to clipboard.
                </p>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <label class="block grow space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Add Domain</span>
                    <input
                        :value="newCustomizationDomain"
                        type="text"
                        placeholder="example.com"
                        data-test-new-customization-domain
                        class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        @input="emit('update:newCustomizationDomain', ($event.target as HTMLInputElement).value)"
                    />
                </label>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                    data-test-add-customization-domain
                    @click="emit('add-customization-domain')"
                >
                    Add Profile
                </button>
            </div>
            <p class="text-xs text-twilight-indigo-300">
                Use the page hostname that owns the widget context, such as
                <span class="font-mono text-smart-blue-100">civitai.com</span>.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(260px,320px)_minmax(0,1fr)] 2xl:grid-cols-[minmax(320px,360px)_minmax(0,1fr)]">
            <aside class="flex max-h-[36rem] min-h-0 flex-col gap-3 rounded-sm border border-smart-blue-500/25 bg-prussian-blue-800/35 p-4 shadow-lg shadow-prussian-blue-950/10">
                <div class="flex flex-col gap-3 border-b border-smart-blue-500/20 pb-3">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-smart-blue-200">Profiles</h3>

                    <label class="block">
                        <span class="sr-only">Search profiles</span>
                        <input
                            v-model="profileSearchQuery"
                            type="search"
                            placeholder="Search profiles"
                            data-test-profile-search
                            class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-3 py-2 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        />
                    </label>

                    <div class="grid grid-cols-3 gap-1" data-test-profile-status-filters>
                        <button
                            v-for="filter in profileStatusFilters"
                            :key="filter.id"
                            type="button"
                            class="rounded-sm border px-2 py-1.5 text-xs font-medium transition"
                            :class="profileStatusFilter === filter.id
                                ? 'border-smart-blue-300 bg-smart-blue-500/16 text-smart-blue-100'
                                : 'border-smart-blue-500/20 bg-prussian-blue-900/35 text-twilight-indigo-200 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/50'"
                            :data-test-profile-status-filter="filter.id"
                            @click="profileStatusFilter = filter.id"
                        >
                            {{ filter.label }}
                        </button>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto pr-1" data-test-profile-list-scroll>
                    <div
                        v-if="filteredProfileEntries.length > 0"
                        class="flex flex-col gap-2"
                        data-test-customization-domain-list
                    >
                        <button
                            v-for="{ customization, index } in filteredProfileEntries"
                            :key="customization.domain || `customization-${index}`"
                            type="button"
                            class="w-full rounded-sm border p-3 text-left transition"
                            :class="index === selectedCustomizationIndex
                                ? 'border-smart-blue-300 bg-smart-blue-500/16 shadow-lg shadow-smart-blue-900/15'
                                : 'border-smart-blue-500/20 bg-prussian-blue-900/35 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/50'"
                            :data-test-customization-domain-button="customization.domain"
                            @click="emit('update:selectedCustomizationIndex', index)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-regal-navy-100">
                                        {{ customization.domain }}
                                    </p>
                                </div>
                                <span
                                    class="mt-0.5 inline-flex size-5 items-center justify-center rounded-sm border"
                                    :class="customization.enabled
                                        ? index === selectedCustomizationIndex
                                            ? 'border-emerald-300/70 text-emerald-100'
                                            : 'border-emerald-400/30 text-emerald-200'
                                        : index === selectedCustomizationIndex
                                            ? 'border-danger-300/70 text-danger-100'
                                            : 'border-danger-500/25 text-red-200'"
                                    :aria-label="customization.enabled ? 'Enabled profile' : 'Disabled profile'"
                                    :data-test-profile-status-icon="customization.domain"
                                >
                                    <Check
                                        v-if="customization.enabled"
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    <Ban
                                        v-else
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                </span>
                            </div>
                        </button>
                    </div>

                    <p
                        v-else-if="customizations.length === 0"
                        class="rounded-sm border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/25 px-4 py-6 text-sm text-twilight-indigo-300"
                    >
                        Add a domain to start building a site profile.
                    </p>

                    <p
                        v-else
                        class="rounded-sm border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/25 px-4 py-6 text-sm text-twilight-indigo-300"
                    >
                        No profiles match the current filter.
                    </p>
                </div>
            </aside>

            <section
                v-if="selectedCustomization"
                class="space-y-5 rounded-sm border border-smart-blue-500/25 bg-prussian-blue-800/35 p-4 shadow-lg shadow-prussian-blue-950/10"
                data-test-customization-editor
            >
                <div class="flex flex-col gap-4 border-b border-smart-blue-500/20 pb-4 xl:flex-row xl:items-start xl:justify-between">
                    <label class="block grow space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Page Domain</span>
                        <input
                            v-model="selectedCustomization.domain"
                            type="text"
                            data-test-selected-customization-domain
                            class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        />
                    </label>

                    <div
                        class="flex flex-wrap items-center justify-end gap-2"
                        data-test-profile-actions
                    >
                        <button
                            type="button"
                            role="switch"
                            class="inline-flex h-8 w-14 items-center rounded-sm border p-1 transition"
                            :class="selectedCustomization.enabled
                                ? 'border-emerald-300/60 bg-emerald-500/25'
                                : 'border-danger-400/50 bg-danger-500/20'"
                            :aria-checked="String(selectedCustomization.enabled)"
                            :aria-label="selectedCustomization.enabled ? 'Disable profile' : 'Enable profile'"
                            :title="selectedCustomization.enabled ? 'Disable profile' : 'Enable profile'"
                            data-test-toggle-customization-enabled
                            @click="selectedCustomization.enabled = !selectedCustomization.enabled"
                        >
                            <span
                                class="block size-5 rounded-sm bg-regal-navy-100 transition"
                                :class="selectedCustomization.enabled ? 'translate-x-6' : 'translate-x-0'"
                                aria-hidden="true"
                            />
                        </button>

                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-sm border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
                            :data-test-remove-customization-domain="selectedCustomization.domain"
                            aria-label="Delete profile"
                            title="Delete profile"
                            @click="emit('remove-customization', selectedCustomizationIndex)"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                            <span class="sr-only">Delete profile</span>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tab in CUSTOMIZATION_TABS"
                        :key="tab.id"
                        type="button"
                        class="rounded-sm border px-4 py-2 text-sm font-medium transition"
                        :class="activeCustomizationTab === tab.id
                            ? 'border-smart-blue-300 bg-smart-blue-500/16 text-smart-blue-100'
                            : 'border-smart-blue-500/20 bg-prussian-blue-900/35 text-twilight-indigo-200 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/50'"
                        :data-test-customization-tab="tab.id"
                        @click="emit('update:activeCustomizationTab', tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div
                    class="flex flex-col gap-4"
                    data-test-customization-active-panel
                >
                    <div class="border-b border-smart-blue-500/20 pb-4">
                        <p class="text-sm text-blue-slate-300">
                            {{ activeTabMeta.description }}
                        </p>
                    </div>

                    <div
                        v-if="activeCustomizationTab === 'matchRules'"
                        class="space-y-4"
                        data-test-customization-panel="matchRules"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-twilight-indigo-300">
                                When this list is populated, at least one regex must match before Atlas renders the widget.
                            </p>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                                data-test-add-match-rule
                                @click="emit('add-match-rule')"
                            >
                                Add Regex
                            </button>
                        </div>

                        <div
                            v-if="selectedCustomization.matchRules.length === 0"
                            class="rounded-sm border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-4 py-6 text-sm text-twilight-indigo-300"
                        >
                            No match rules yet. This profile stays permissive until you add one.
                        </div>

                        <div v-else class="space-y-3">
                            <p
                                class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200"
                                data-test-match-rule-list-label
                            >
                                Regex pattern
                            </p>
                            <div
                                v-for="(_, regexIndex) in selectedCustomization.matchRules"
                                :key="`regex-${regexIndex}`"
                                class="flex flex-col gap-3 lg:flex-row lg:items-start"
                                data-test-match-rule-row
                            >
                                <input
                                    v-model="selectedCustomization.matchRules[regexIndex]"
                                    type="text"
                                    placeholder=".*\\/art\\/.*"
                                    aria-label="Regex pattern"
                                    class="w-full grow rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                />
                                <button
                                    type="button"
                                    class="inline-flex size-12 items-center justify-center rounded-sm border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
                                    aria-label="Delete match rule"
                                    title="Delete match rule"
                                    @click="emit('remove-match-rule', regexIndex)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    <span class="sr-only">Delete match rule</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <SiteCustomizationWidgetPanel
                        v-else-if="activeCustomizationTab === 'widget'"
                        v-model="selectedCustomization.widgetMinImageWidthText"
                    />

                    <div
                        v-else-if="activeCustomizationTab === 'referrerCleaner'"
                        class="space-y-4"
                        data-test-customization-panel="referrerCleaner"
                    >
                        <label class="block space-y-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Strip Query Params</span>
                            <textarea
                                v-model="selectedCustomization.referrerCleanerQueryParamsText"
                                rows="6"
                                placeholder='Comma or newline separated query params, for example tag, tags, or "*"'
                                data-test-referrer-cleaner-query-params
                                class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                            />
                        </label>

                        <div class="rounded-sm border border-smart-blue-500/20 bg-prussian-blue-900/25 px-4 py-3 text-sm text-twilight-indigo-300">
                            These params are stripped from page and anchor referrers on this site before Atlas checks or stores them.
                            Use <span class="font-mono text-smart-blue-100">*</span> to remove every query param.
                        </div>
                    </div>

                    <SiteCustomizationMediaCleanerPanel
                        v-else
                        :active-media-cleaner-strategies="selectedCustomization.mediaCleanerStrategies"
                        :domain="selectedCustomization.domain"
                        :media-cleaner-query-params-text="selectedCustomization.mediaCleanerQueryParamsText"
                        :media-cleaner-rewrite-rules="selectedCustomization.mediaCleanerRewriteRules"
                        :media-cleaner-strategies="mediaCleanerStrategies"
                        @add-media-rewrite-rule="emit('add-media-rewrite-rule')"
                        @remove-media-rewrite-rule="emit('remove-media-rewrite-rule', $event)"
                        @toggle-media-cleaner-strategy="emit('toggle-media-cleaner-strategy', $event)"
                        @update-media-cleaner-query-params-text="selectedCustomization.mediaCleanerQueryParamsText = $event"
                        @update-media-rewrite-rule="updateMediaCleanerRewriteRule"
                    />
                </div>
            </section>

            <section
                v-else
                class="flex min-h-72 items-center justify-center rounded-sm border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-6 py-10 text-center text-sm text-twilight-indigo-300"
            >
                Select a domain to edit its customization tabs.
            </section>
        </div>
    </section>
</template>
