<script setup lang="ts">
import { computed, ref } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import {
    CUSTOMIZATION_TABS,
    type CustomizationTab,
    type SiteCustomizationForm,
} from './options-site-customization-form';
import {
    CUSTOMIZATION_TAB_META,
    describeCustomization,
    MEDIA_CLEANER_STRATEGY_META,
} from './site-customization-manager-meta';
import type { MediaCleanerStrategy } from './site-customizations';

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
const selectedCustomization = computed<SiteCustomizationForm | null>(() =>
    props.customizations[props.selectedCustomizationIndex] ?? null);
const activeTabMeta = computed(() => CUSTOMIZATION_TAB_META[props.activeCustomizationTab]);
const selectedCustomizationSummary = computed(() =>
    selectedCustomization.value === null ? [] : describeCustomization(selectedCustomization.value));
const enabledCustomizationCount = computed(() => props.customizations.filter((customization) => customization.enabled).length);
const disabledCustomizationCount = computed(() => props.customizations.length - enabledCustomizationCount.value);

function triggerImportCustomizations(): void {
    importFileInput.value?.click();
}

function handleImportCustomizations(event: Event): void {
    emit('import-customizations', event);
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
                        class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
                        data-test-copy-customizations
                        @click="emit('copy-customizations')"
                    >
                        Copy JSON
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
                        data-test-export-customizations
                        @click="emit('export-customizations')"
                    >
                        Export JSON
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/15 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/25"
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

        <div class="rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-800/35 p-4 shadow-lg shadow-prussian-blue-950/10">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <label class="block grow space-y-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Add Domain</span>
                    <input
                        :value="newCustomizationDomain"
                        type="text"
                        placeholder="example.com"
                        data-test-new-customization-domain
                        class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        @input="emit('update:newCustomizationDomain', ($event.target as HTMLInputElement).value)"
                    />
                </label>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-3 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                    data-test-add-customization-domain
                    @click="emit('add-customization-domain')"
                >
                    Add Profile
                </button>
            </div>
            <p class="mt-3 text-xs text-twilight-indigo-300">
                Use the page hostname that owns the widget context, such as
                <span class="font-mono text-smart-blue-100">civitai.com</span>.
            </p>
        </div>

        <div class="grid gap-4 lg:grid-cols-[minmax(260px,320px)_minmax(0,1fr)] 2xl:grid-cols-[minmax(320px,360px)_minmax(0,1fr)]">
            <aside class="space-y-3 rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-800/35 p-4 shadow-lg shadow-prussian-blue-950/10">
                <div class="border-b border-smart-blue-500/20 pb-3">
                    <h3 class="text-sm font-semibold uppercase tracking-[0.18em] text-smart-blue-200">Profiles</h3>
                    <p class="mt-1 text-xs text-twilight-indigo-300">
                        {{ enabledCustomizationCount }} enabled · {{ disabledCustomizationCount }} disabled
                    </p>
                </div>

                <div class="space-y-2">
                    <div
                        v-if="customizations.length > 0"
                        class="space-y-2"
                        data-test-customization-domain-list
                    >
                        <button
                            v-for="(customization, index) in customizations"
                            :key="customization.domain || `customization-${index}`"
                            type="button"
                            class="w-full rounded-2xl border p-3 text-left transition"
                            :class="index === selectedCustomizationIndex
                                ? 'border-smart-blue-300 bg-smart-blue-500/16 shadow-lg shadow-smart-blue-900/15'
                                : 'border-smart-blue-500/20 bg-prussian-blue-900/35 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/50'"
                            :data-test-customization-domain-button="customization.domain"
                            @click="emit('update:selectedCustomizationIndex', index)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0 space-y-2">
                                    <p class="truncate text-sm font-semibold text-regal-navy-100">
                                        {{ customization.domain }}
                                    </p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span
                                            v-for="summary in describeCustomization(customization)"
                                            :key="`${customization.domain}-${summary}`"
                                            class="rounded-full border border-smart-blue-500/25 bg-prussian-blue-800/60 px-2.5 py-1 text-[11px] text-blue-slate-200"
                                        >
                                            {{ summary }}
                                        </span>
                                    </div>
                                </div>
                                <span
                                    class="mt-0.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em]"
                                    :class="customization.enabled
                                        ? index === selectedCustomizationIndex
                                            ? 'border-emerald-300/70 text-emerald-100'
                                            : 'border-emerald-400/30 text-emerald-200'
                                        : index === selectedCustomizationIndex
                                            ? 'border-danger-300/70 text-danger-100'
                                            : 'border-danger-500/25 text-red-200'"
                                >
                                    {{ customization.enabled ? 'Enabled' : 'Disabled' }}
                                </span>
                            </div>
                        </button>
                    </div>

                    <p
                        v-else
                        class="rounded-2xl border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/25 px-4 py-6 text-sm text-twilight-indigo-300"
                    >
                        Add a domain to start building a site profile.
                    </p>
                </div>
            </aside>

            <section
                v-if="selectedCustomization"
                class="space-y-5 rounded-2xl border border-smart-blue-500/25 bg-prussian-blue-800/35 p-4 shadow-lg shadow-prussian-blue-950/10"
                data-test-customization-editor
            >
                <div class="flex flex-col gap-4 border-b border-smart-blue-500/20 pb-4 xl:flex-row xl:items-start xl:justify-between">
                    <label class="block grow space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Page Domain</span>
                        <input
                            v-model="selectedCustomization.domain"
                            type="text"
                            data-test-selected-customization-domain
                            class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                        />
                    </label>

                    <div class="flex flex-col gap-3 xl:items-end">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-xl border px-4 py-3 text-sm font-medium transition"
                            :class="selectedCustomization.enabled
                                ? 'border-emerald-400/60 bg-emerald-500/15 text-emerald-100 hover:bg-emerald-500/22'
                                : 'border-danger-500/55 bg-danger-500/12 text-danger-100 hover:bg-danger-500/20'"
                            data-test-toggle-customization-enabled
                            @click="selectedCustomization.enabled = !selectedCustomization.enabled"
                        >
                            {{ selectedCustomization.enabled ? 'Disable Profile' : 'Enable Profile' }}
                        </button>
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <span
                                v-for="summary in selectedCustomizationSummary"
                                :key="`selected-${summary}`"
                                class="rounded-full border border-smart-blue-500/25 bg-prussian-blue-900/45 px-2.5 py-1 text-[11px] text-blue-slate-200"
                            >
                                {{ summary }}
                            </span>
                        </div>
                        <p class="text-right text-xs text-twilight-indigo-300">
                            Disabled profiles stay saved, but Atlas ignores them until you turn them back on.
                        </p>
                        <button
                            type="button"
                            class="inline-flex size-11 items-center justify-center rounded-xl border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
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
                        class="rounded-full border px-4 py-2 text-sm font-medium transition"
                        :class="activeCustomizationTab === tab.id
                            ? 'border-smart-blue-300 bg-smart-blue-500/16 text-smart-blue-100'
                            : 'border-smart-blue-500/20 bg-prussian-blue-900/35 text-twilight-indigo-200 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/50'"
                        :data-test-customization-tab="tab.id"
                        @click="emit('update:activeCustomizationTab', tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div class="rounded-2xl border border-smart-blue-500/20 bg-prussian-blue-900/25 p-4">
                    <div class="border-b border-smart-blue-500/20 pb-4">
                        <h4 class="text-base font-semibold text-regal-navy-100">
                            {{ activeTabMeta.title }}
                        </h4>
                        <p class="mt-1 text-sm text-blue-slate-300">
                            {{ activeTabMeta.description }}
                        </p>
                    </div>

                    <div
                        v-if="activeCustomizationTab === 'matchRules'"
                        class="space-y-4 pt-4"
                        data-test-customization-panel="matchRules"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-twilight-indigo-300">
                                When this list is populated, at least one regex must match before Atlas renders the widget.
                            </p>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                                data-test-add-match-rule
                                @click="emit('add-match-rule')"
                            >
                                Add Regex
                            </button>
                        </div>

                        <div
                            v-if="selectedCustomization.matchRules.length === 0"
                            class="rounded-2xl border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-4 py-6 text-sm text-twilight-indigo-300"
                        >
                            No match rules yet. This profile stays permissive until you add one.
                        </div>

                        <div v-else class="space-y-3">
                            <div
                                v-for="(_, regexIndex) in selectedCustomization.matchRules"
                                :key="`regex-${regexIndex}`"
                                class="rounded-2xl border border-smart-blue-500/20 bg-prussian-blue-900/30 p-3"
                            >
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start">
                                    <label class="block grow space-y-2">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Regex Pattern</span>
                                        <input
                                            v-model="selectedCustomization.matchRules[regexIndex]"
                                            type="text"
                                            placeholder=".*\\/art\\/.*"
                                            class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                        />
                                    </label>
                                    <button
                                        type="button"
                                        class="inline-flex size-12 items-center justify-center rounded-xl border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
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
                    </div>

                    <div
                        v-else-if="activeCustomizationTab === 'referrerCleaner'"
                        class="space-y-4 pt-4"
                        data-test-customization-panel="referrerCleaner"
                    >
                        <label class="block space-y-2">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-smart-blue-200">Strip Query Params</span>
                            <textarea
                                v-model="selectedCustomization.referrerCleanerQueryParamsText"
                                rows="6"
                                placeholder='Comma or newline separated query params, for example tag, tags, or "*"'
                                data-test-referrer-cleaner-query-params
                                class="w-full rounded-2xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                            />
                        </label>

                        <div class="rounded-2xl border border-smart-blue-500/20 bg-prussian-blue-900/25 px-4 py-3 text-sm text-twilight-indigo-300">
                            These params are stripped from page and anchor referrers on this site before Atlas checks or stores them.
                            Use <span class="font-mono text-smart-blue-100">*</span> to remove every query param.
                        </div>
                    </div>

                    <div
                        v-else
                        class="space-y-5 pt-4"
                        data-test-customization-panel="mediaCleaner"
                    >
                        <section class="space-y-3">
                            <div class="space-y-1">
                                <h5 class="text-sm font-semibold text-regal-navy-100">Named Strategies</h5>
                                <p class="text-sm text-twilight-indigo-300">
                                    Use built-in strategies when a site needs contextual normalization that a plain regex cannot express.
                                </p>
                            </div>

                            <div class="grid gap-3 md:grid-cols-2">
                                <button
                                    v-for="strategy in mediaCleanerStrategies"
                                    :key="strategy"
                                    type="button"
                                    class="rounded-2xl border p-4 text-left transition"
                                    :class="selectedCustomization.mediaCleanerStrategies.includes(strategy)
                                        ? 'border-smart-blue-300 bg-smart-blue-500/16'
                                        : 'border-smart-blue-500/20 bg-prussian-blue-900/30 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/45'"
                                    :data-test-media-cleaner-strategy="strategy"
                                    @click="emit('toggle-media-cleaner-strategy', strategy)"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="space-y-1">
                                            <p class="text-sm font-semibold text-regal-navy-100">
                                                {{ MEDIA_CLEANER_STRATEGY_META[strategy]?.title ?? strategy }}
                                            </p>
                                            <p class="text-xs text-twilight-indigo-300">
                                                {{ MEDIA_CLEANER_STRATEGY_META[strategy]?.description }}
                                            </p>
                                            <p class="font-mono text-[11px] text-smart-blue-100">
                                                {{ strategy }}
                                            </p>
                                        </div>
                                        <span
                                            class="rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em]"
                                            :class="selectedCustomization.mediaCleanerStrategies.includes(strategy)
                                                ? 'border-smart-blue-300/70 text-smart-blue-100'
                                                : 'border-smart-blue-500/25 text-twilight-indigo-300'"
                                        >
                                            {{ selectedCustomization.mediaCleanerStrategies.includes(strategy) ? 'Enabled' : 'Off' }}
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </section>

                        <section class="space-y-2">
                            <div class="space-y-1">
                                <h5 class="text-sm font-semibold text-regal-navy-100">Query Param Stripping</h5>
                                <p class="text-sm text-twilight-indigo-300">
                                    Remove unstable media query params before Atlas runs rewrite rules.
                                </p>
                            </div>
                            <textarea
                                v-model="selectedCustomization.mediaCleanerQueryParamsText"
                                rows="4"
                                placeholder='Comma or newline separated params, for example width, quality, or "*"'
                                data-test-media-cleaner-query-params
                                class="w-full rounded-2xl border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                            />
                        </section>

                        <section class="space-y-3">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="space-y-1">
                                    <h5 class="text-sm font-semibold text-regal-navy-100">Rewrite Rules</h5>
                                    <p class="text-sm text-twilight-indigo-300">
                                        Atlas applies the first matching rewrite after strategies and query stripping.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center rounded-xl border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                                    data-test-add-media-rewrite-rule
                                    @click="emit('add-media-rewrite-rule')"
                                >
                                    Add Rewrite
                                </button>
                            </div>

                            <div
                                v-if="selectedCustomization.mediaCleanerRewriteRules.length === 0"
                                class="rounded-2xl border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-4 py-6 text-sm text-twilight-indigo-300"
                            >
                                No rewrite rules yet. Atlas will stop after strategies and query stripping.
                            </div>

                            <div v-else class="space-y-3">
                                <div
                                    v-for="(rewriteRule, rewriteIndex) in selectedCustomization.mediaCleanerRewriteRules"
                                    :key="`rewrite-${rewriteIndex}`"
                                    class="rounded-2xl border border-smart-blue-500/20 bg-prussian-blue-900/30 p-4"
                                >
                                    <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end">
                                        <label class="block space-y-2">
                                            <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Pattern</span>
                                            <input
                                                v-model="rewriteRule.pattern"
                                                type="text"
                                                placeholder="Regex pattern"
                                                class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                            />
                                        </label>

                                        <label class="block space-y-2">
                                            <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Replace</span>
                                            <input
                                                v-model="rewriteRule.replace"
                                                type="text"
                                                placeholder="Replacement string"
                                                class="w-full rounded-xl border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                                            />
                                        </label>

                                        <button
                                            type="button"
                                            class="inline-flex size-12 items-center justify-center rounded-xl border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
                                            aria-label="Delete rewrite rule"
                                            title="Delete rewrite rule"
                                            @click="emit('remove-media-rewrite-rule', rewriteIndex)"
                                        >
                                            <Trash2 class="size-4" aria-hidden="true" />
                                            <span class="sr-only">Delete rewrite rule</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <div class="rounded-2xl border border-smart-blue-500/20 bg-prussian-blue-900/25 px-4 py-3 text-sm text-twilight-indigo-300">
                            Cleaner order is strategy, then query stripping, then the first matching rewrite rule.
                        </div>
                    </div>
                </div>
            </section>

            <section
                v-else
                class="flex min-h-72 items-center justify-center rounded-2xl border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-6 py-10 text-center text-sm text-twilight-indigo-300"
            >
                Select a domain to edit its customization tabs.
            </section>
        </div>
    </section>
</template>
