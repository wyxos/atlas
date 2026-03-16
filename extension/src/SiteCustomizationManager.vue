<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    CUSTOMIZATION_TABS,
    type CustomizationTab,
    type SiteCustomizationForm,
} from './options-site-customization-form';
import type { MediaCleanerStrategy } from './site-customizations';

const props = defineProps<{
    customizations: SiteCustomizationForm[];
    selectedCustomizationIndex: number;
    activeCustomizationTab: CustomizationTab;
    newCustomizationDomain: string;
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
    'export-customizations': [];
    'import-customizations': [event: Event];
}>();

const importFileInput = ref<HTMLInputElement | null>(null);
const selectedCustomization = computed<SiteCustomizationForm | null>(() =>
    props.customizations[props.selectedCustomizationIndex] ?? null);

function triggerImportCustomizations(): void {
    importFileInput.value?.click();
}

function handleImportCustomizations(event: Event): void {
    emit('import-customizations', event);
}
</script>

<template>
    <section class="space-y-4 rounded-lg border border-smart-blue-500/30 bg-prussian-blue-800/40 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-regal-navy-100">Customizations</h2>
                <p class="text-xs text-twilight-indigo-300">
                    Configure one site profile per page domain, then switch between match rules,
                    referrer cleaning, and media cleaning.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    data-test-export-customizations
                    @click="emit('export-customizations')"
                >
                    Export Customizations
                </button>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                    data-test-import-customizations
                    @click="triggerImportCustomizations"
                >
                    Import Customizations
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
        </div>

        <div class="grid gap-4 xl:grid-cols-[260px_minmax(0,1fr)]">
            <aside class="space-y-3">
                <div class="space-y-2">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Domains</span>
                    <div class="flex items-center gap-2">
                        <input
                            :value="newCustomizationDomain"
                            type="text"
                            placeholder="example.com"
                            data-test-new-customization-domain
                            class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                            @input="emit('update:newCustomizationDomain', ($event.target as HTMLInputElement).value)"
                        />
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                            data-test-add-customization-domain
                            @click="emit('add-customization-domain')"
                        >
                            Add
                        </button>
                    </div>
                </div>

                <div
                    v-if="customizations.length > 0"
                    class="space-y-2"
                    data-test-customization-domain-list
                >
                    <div
                        v-for="(customization, index) in customizations"
                        :key="customization.domain || `customization-${index}`"
                        class="flex items-center gap-2"
                    >
                        <button
                            type="button"
                            class="flex-1 rounded-md border px-3 py-2 text-left text-sm transition"
                            :class="index === selectedCustomizationIndex
                                ? 'border-smart-blue-300 bg-smart-blue-500/20 text-smart-blue-100'
                                : 'border-smart-blue-500/30 bg-prussian-blue-800/50 text-regal-navy-100 hover:border-smart-blue-400/60'"
                            :data-test-customization-domain-button="customization.domain"
                            @click="emit('update:selectedCustomizationIndex', index)"
                        >
                            {{ customization.domain }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center justify-center rounded-md border border-danger-500/60 bg-danger-500/20 px-2 py-2 text-xs font-medium text-danger-100 transition hover:bg-danger-500/30"
                            :data-test-remove-customization-domain="customization.domain"
                            @click="emit('remove-customization', index)"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                <p v-else class="rounded-md border border-dashed border-smart-blue-500/30 px-3 py-4 text-sm text-twilight-indigo-300">
                    Add a domain to start building a site profile.
                </p>
            </aside>

            <section
                v-if="selectedCustomization"
                class="space-y-4 rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/30 p-4"
                data-test-customization-editor
            >
                <label class="block space-y-1">
                    <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Domain</span>
                    <input
                        v-model="selectedCustomization.domain"
                        type="text"
                        data-test-selected-customization-domain
                        class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                    />
                </label>

                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tab in CUSTOMIZATION_TABS"
                        :key="tab.id"
                        type="button"
                        class="rounded-md border px-3 py-2 text-xs font-medium transition"
                        :class="activeCustomizationTab === tab.id
                            ? 'border-smart-blue-300 bg-smart-blue-500/20 text-smart-blue-100'
                            : 'border-smart-blue-500/30 bg-prussian-blue-800/50 text-twilight-indigo-200 hover:border-smart-blue-400/60'"
                        :data-test-customization-tab="tab.id"
                        @click="emit('update:activeCustomizationTab', tab.id)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <div
                    v-if="activeCustomizationTab === 'matchRules'"
                    class="space-y-3"
                    data-test-customization-panel="matchRules"
                >
                    <div class="space-y-2">
                        <div
                            v-for="(_, regexIndex) in selectedCustomization.matchRules"
                            :key="`regex-${regexIndex}`"
                            class="flex items-center gap-2"
                        >
                            <input
                                v-model="selectedCustomization.matchRules[regexIndex]"
                                type="text"
                                placeholder="Regex pattern (e.g. .*\\/art\\/.*)"
                                class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                            />
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-danger-500/60 bg-danger-500/20 px-2 py-2 text-xs font-medium text-danger-100 transition hover:bg-danger-500/30"
                                @click="emit('remove-match-rule', regexIndex)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                        data-test-add-match-rule
                        @click="emit('add-match-rule')"
                    >
                        Add Regex
                    </button>

                    <p class="text-xs text-twilight-indigo-300">
                        Leave this empty to keep Atlas permissive for the selected page host. When
                        match rules exist for this site, at least one regex must match before widgets render.
                    </p>
                </div>

                <div
                    v-else-if="activeCustomizationTab === 'referrerCleaner'"
                    class="space-y-3"
                    data-test-customization-panel="referrerCleaner"
                >
                    <label class="block space-y-1">
                        <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Strip Query Params</span>
                        <textarea
                            v-model="selectedCustomization.referrerCleanerQueryParamsText"
                            rows="4"
                            placeholder='Comma or newline separated query params (e.g. tag, tags, or "*")'
                            data-test-referrer-cleaner-query-params
                            class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                        />
                    </label>

                    <p class="text-xs text-twilight-indigo-300">
                        Referrer cleaner settings apply to page and anchor URLs encountered on this site.
                        Use <span class="font-mono">*</span> to strip every query param.
                    </p>
                </div>

                <div
                    v-else
                    class="space-y-4"
                    data-test-customization-panel="mediaCleaner"
                >
                    <div class="space-y-2">
                        <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Strategies</span>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="strategy in mediaCleanerStrategies"
                                :key="strategy"
                                type="button"
                                class="rounded-md border px-3 py-2 text-xs font-medium transition"
                                :class="selectedCustomization.mediaCleanerStrategies.includes(strategy)
                                    ? 'border-smart-blue-300 bg-smart-blue-500/20 text-smart-blue-100'
                                    : 'border-smart-blue-500/30 bg-prussian-blue-800/50 text-twilight-indigo-200 hover:border-smart-blue-400/60'"
                                :data-test-media-cleaner-strategy="strategy"
                                @click="emit('toggle-media-cleaner-strategy', strategy)"
                            >
                                {{ strategy }}
                            </button>
                        </div>
                    </div>

                    <label class="block space-y-1">
                        <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Strip Query Params</span>
                        <textarea
                            v-model="selectedCustomization.mediaCleanerQueryParamsText"
                            rows="3"
                            placeholder='Comma or newline separated media query params (e.g. width, quality, or "*")'
                            data-test-media-cleaner-query-params
                            class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                        />
                    </label>

                    <div class="space-y-2">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Rewrite Rules</span>
                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-smart-blue-400/60 bg-smart-blue-500/20 px-3 py-2 text-xs font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/30"
                                data-test-add-media-rewrite-rule
                                @click="emit('add-media-rewrite-rule')"
                            >
                                Add Rewrite
                            </button>
                        </div>

                        <div
                            v-for="(rewriteRule, rewriteIndex) in selectedCustomization.mediaCleanerRewriteRules"
                            :key="`rewrite-${rewriteIndex}`"
                            class="space-y-2 rounded-md border border-smart-blue-500/30 bg-prussian-blue-800/40 p-3"
                        >
                            <label class="block space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Pattern</span>
                                <input
                                    v-model="rewriteRule.pattern"
                                    type="text"
                                    placeholder="Regex pattern"
                                    class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                                />
                            </label>

                            <label class="block space-y-1">
                                <span class="text-xs font-medium uppercase tracking-wide text-smart-blue-200">Replace</span>
                                <input
                                    v-model="rewriteRule.replace"
                                    type="text"
                                    placeholder="Replacement string"
                                    class="w-full rounded-md border border-smart-blue-500/40 bg-prussian-blue-800/70 px-3 py-2 text-sm text-regal-navy-100 outline-none transition focus:border-smart-blue-300"
                                />
                            </label>

                            <button
                                type="button"
                                class="inline-flex items-center justify-center rounded-md border border-danger-500/60 bg-danger-500/20 px-3 py-2 text-xs font-medium text-danger-100 transition hover:bg-danger-500/30"
                                @click="emit('remove-media-rewrite-rule', rewriteIndex)"
                            >
                                Delete Rewrite
                            </button>
                        </div>
                    </div>

                    <p class="text-xs text-twilight-indigo-300">
                        Media cleaner order is strategy, then query stripping, then the first matching rewrite rule.
                    </p>
                </div>
            </section>

            <section
                v-else
                class="flex min-h-48 items-center justify-center rounded-md border border-dashed border-smart-blue-500/30 bg-prussian-blue-800/20 px-4 py-8 text-center text-sm text-twilight-indigo-300"
            >
                Select a domain to edit its customization tabs.
            </section>
        </div>
    </section>
</template>
