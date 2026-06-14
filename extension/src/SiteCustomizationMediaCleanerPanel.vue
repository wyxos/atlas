<script setup lang="ts">
import { computed } from 'vue';
import { Trash2 } from 'lucide-vue-next';
import {
    MEDIA_CLEANER_STRATEGY_META,
} from './site-customization-manager-meta';
import type { MediaCleanerStrategy } from './site-customizations';

type MediaCleanerRewriteRule = {
    pattern: string;
    replace: string;
};
type MediaCleanerRewriteRuleField = keyof MediaCleanerRewriteRule;

const props = defineProps<{
    activeMediaCleanerStrategies: readonly MediaCleanerStrategy[];
    domain: string;
    mediaCleanerQueryParamsText: string;
    mediaCleanerRewriteRules: MediaCleanerRewriteRule[];
    mediaCleanerStrategies: readonly MediaCleanerStrategy[];
}>();

const emit = defineEmits<{
    'add-media-rewrite-rule': [];
    'remove-media-rewrite-rule': [index: number];
    'toggle-media-cleaner-strategy': [strategy: MediaCleanerStrategy];
    'update-media-cleaner-query-params-text': [value: string];
    'update-media-rewrite-rule': [index: number, field: MediaCleanerRewriteRuleField, value: string];
}>();

const visibleMediaCleanerStrategies = computed(() => props.mediaCleanerStrategies.filter((strategy) =>
    isMediaCleanerStrategyRelevant(strategy)));

function isMediaCleanerStrategyRelevant(strategy: MediaCleanerStrategy): boolean {
    if (props.activeMediaCleanerStrategies.includes(strategy)) {
        return true;
    }

    if (strategy === 'civitaiCanonical') {
        return props.domain.toLowerCase().includes('civitai');
    }

    return true;
}

function updateMediaCleanerQueryParamsText(event: Event): void {
    emit('update-media-cleaner-query-params-text', (event.target as HTMLTextAreaElement).value);
}

function updateMediaRewriteRule(
    index: number,
    field: MediaCleanerRewriteRuleField,
    event: Event,
): void {
    emit('update-media-rewrite-rule', index, field, (event.target as HTMLInputElement).value);
}
</script>

<template>
    <div
        class="flex flex-col gap-5 pt-4"
        data-test-customization-panel="mediaCleaner"
    >
        <section
            v-if="visibleMediaCleanerStrategies.length > 0"
            class="flex flex-col gap-3"
        >
            <div class="flex flex-col gap-1">
                <h5 class="text-sm font-semibold text-regal-navy-100">Site presets</h5>
                <p class="text-sm text-twilight-indigo-300">
                    Apply known per-site URL fixes before matching or saving media.
                </p>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <button
                    v-for="strategy in visibleMediaCleanerStrategies"
                    :key="strategy"
                    type="button"
                    class="rounded-sm border p-4 text-left transition"
                    :class="activeMediaCleanerStrategies.includes(strategy)
                        ? 'border-smart-blue-300 bg-smart-blue-500/16'
                        : 'border-smart-blue-500/20 bg-prussian-blue-900/30 hover:border-smart-blue-400/45 hover:bg-prussian-blue-900/45'"
                    :data-test-media-cleaner-strategy="strategy"
                    @click="emit('toggle-media-cleaner-strategy', strategy)"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex flex-col gap-1">
                            <p class="text-sm font-semibold text-regal-navy-100">
                                {{ MEDIA_CLEANER_STRATEGY_META[strategy]?.title ?? strategy }}
                            </p>
                            <p class="text-xs text-twilight-indigo-300">
                                {{ MEDIA_CLEANER_STRATEGY_META[strategy]?.description }}
                            </p>
                        </div>
                        <span
                            class="rounded-sm border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em]"
                            :class="activeMediaCleanerStrategies.includes(strategy)
                                ? 'border-smart-blue-300/70 text-smart-blue-100'
                                : 'border-smart-blue-500/25 text-twilight-indigo-300'"
                        >
                            {{ activeMediaCleanerStrategies.includes(strategy) ? 'Enabled' : 'Off' }}
                        </span>
                    </div>
                </button>
            </div>
        </section>

        <section class="flex flex-col gap-2">
            <div class="flex flex-col gap-1">
                <h5 class="text-sm font-semibold text-regal-navy-100">Remove query params</h5>
                <p class="text-sm text-twilight-indigo-300">
                    Drop changing params before matching or saving. Example: width, format, token.
                </p>
            </div>
            <textarea
                :value="mediaCleanerQueryParamsText"
                rows="4"
                placeholder='width, format, token, or "*"'
                data-test-media-cleaner-query-params
                class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/55 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                @input="updateMediaCleanerQueryParamsText"
            />
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-col gap-1">
                    <h5 class="text-sm font-semibold text-regal-navy-100">Rewrite URL text</h5>
                    <p class="text-sm text-twilight-indigo-300">
                        Optional final find and replace for predictable URL text changes.
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center justify-center rounded-sm border border-smart-blue-400/60 bg-smart-blue-500/18 px-4 py-2 text-sm font-medium text-smart-blue-100 transition hover:bg-smart-blue-500/28"
                    data-test-add-media-rewrite-rule
                    @click="emit('add-media-rewrite-rule')"
                >
                    Add Rewrite
                </button>
            </div>

            <div
                v-if="mediaCleanerRewriteRules.length === 0"
                class="rounded-sm border border-dashed border-smart-blue-500/25 bg-prussian-blue-900/20 px-4 py-6 text-sm text-twilight-indigo-300"
            >
                No rewrite rules.
            </div>

            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="(rewriteRule, rewriteIndex) in mediaCleanerRewriteRules"
                    :key="`rewrite-${rewriteIndex}`"
                    class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] xl:items-end"
                    data-test-media-rewrite-rule-row
                >
                    <label class="block space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Pattern</span>
                        <input
                            :value="rewriteRule.pattern"
                            type="text"
                            placeholder="Regex pattern"
                            class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                            @input="updateMediaRewriteRule(rewriteIndex, 'pattern', $event)"
                        />
                    </label>

                    <label class="block space-y-2">
                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-smart-blue-200">Replace</span>
                        <input
                            :value="rewriteRule.replace"
                            type="text"
                            placeholder="Replacement string"
                            class="w-full rounded-sm border border-smart-blue-500/35 bg-prussian-blue-900/60 px-4 py-3 text-sm text-regal-navy-100 outline-none transition placeholder:text-twilight-indigo-400 focus:border-smart-blue-300"
                            @input="updateMediaRewriteRule(rewriteIndex, 'replace', $event)"
                        />
                    </label>

                    <button
                        type="button"
                        class="inline-flex size-12 items-center justify-center rounded-sm border border-danger-500/55 bg-danger-500/15 text-danger-100 transition hover:bg-danger-500/25"
                        aria-label="Delete rewrite rule"
                        title="Delete rewrite rule"
                        @click="emit('remove-media-rewrite-rule', rewriteIndex)"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        <span class="sr-only">Delete rewrite rule</span>
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
