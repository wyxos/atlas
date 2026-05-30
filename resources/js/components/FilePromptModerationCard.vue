<script setup lang="ts">
import { ShieldAlert } from 'lucide-vue-next';
import { computed } from 'vue';
import { FEED_REMOVED_PREVIEW_COUNT } from '@/lib/feedModeration';
import type { File, FileContainer, FileModerationRuleDetails } from '@/types/file';

type ModerationRuleEntry = {
    key: string;
    label: string;
    rule: FileModerationRuleDetails;
};

const props = defineProps<{
    fileData: File;
}>();

const autoBlacklistContainers = computed(() => props.fileData.auto_blacklist_containers ?? []);
const moderationRuleEntries = computed<ModerationRuleEntry[]>(() => {
    const entries: ModerationRuleEntry[] = [];

    addModerationRuleEntry(
        entries,
        props.fileData.blacklist_rule ?? null,
        props.fileData.auto_blacklisted ? 'Auto blacklist rule' : 'Applied blacklist rule',
        'applied',
    );
    addModerationRuleEntry(entries, props.fileData.auto_blacklist_rule ?? null, 'Auto blacklist rule', 'auto');
    addModerationRuleEntry(entries, props.fileData.prompt_moderation_rule ?? null, 'Current prompt match', 'prompt');

    return entries;
});
const hasExplicitAutoBlacklistProvenance = computed(() => moderationRuleEntries.value.length > 0 || autoBlacklistContainers.value.length > 0);
const shouldShowPreviewCountProvenance = computed(() => props.fileData.auto_blacklisted && !hasExplicitAutoBlacklistProvenance.value);
const hasPromptModerationCard = computed(() => hasExplicitAutoBlacklistProvenance.value || shouldShowPreviewCountProvenance.value);
const previewCountProvenanceDetail = computed(() => {
    if (props.fileData.previewed_count === undefined) {
        return null;
    }

    if (Number(props.fileData.previewed_count) >= FEED_REMOVED_PREVIEW_COUNT) {
        return 'Reached the feed-removal threshold.';
    }

    return `Previewed ${props.fileData.previewed_count} times.`;
});
const moderationCardTitle = computed(() => {
    if (autoBlacklistContainers.value.length > 0 && moderationRuleEntries.value.length === 0) {
        return 'Container blacklist';
    }

    if (props.fileData.auto_blacklisted) {
        return 'Auto blacklist';
    }

    if (props.fileData.blacklisted_at) {
        return 'Blacklist';
    }

    return 'Moderation match';
});
const moderationCardSummary = computed(() => {
    if (autoBlacklistContainers.value.length > 0 && moderationRuleEntries.value.length === 0) {
        return 'Matched blacklisted container';
    }

    if (props.fileData.auto_blacklisted) {
        return shouldShowPreviewCountProvenance.value ? 'Likely preview threshold' : 'Applied automatically';
    }

    if (props.fileData.blacklisted_at) {
        return 'Applied to file';
    }

    return 'Prompt rule returned positive';
});

function addModerationRuleEntry(
    entries: ModerationRuleEntry[],
    rule: FileModerationRuleDetails | null,
    label: string,
    keyPrefix: string,
): void {
    if (rule === null) {
        return;
    }

    if (entries.some((entry) => isSameRule(entry.rule, rule))) {
        return;
    }

    entries.push({
        key: `${keyPrefix}-${rule.id}-${rule.name}-${rule.action_type}`,
        label,
        rule,
    });
}

function isSameRule(a: FileModerationRuleDetails, b: FileModerationRuleDetails): boolean {
    return a.id === b.id && a.name === b.name && a.action_type === b.action_type;
}

function formatActionType(actionType: string): string {
    return actionType.replace(/_/g, ' ');
}

function formatRule(rule: FileModerationRuleDetails): string {
    const actionType = formatActionType(rule.action_type);

    return actionType ? `#${rule.id} ${rule.name} (${actionType})` : `#${rule.id} ${rule.name}`;
}

function formatRuleTerms(rule: FileModerationRuleDetails): string | null {
    return rule.matched_terms.length > 0 ? rule.matched_terms.join(', ') : null;
}

function formatContainer(container: FileContainer): string {
    const label = `#${container.id} ${container.type || 'Container'}`;
    const source = [container.source, container.source_id].filter(Boolean).join(' · ');

    return source ? `${label} · ${source}` : label;
}
</script>

<template>
    <div
        v-if="hasPromptModerationCard"
        class="space-y-3 rounded border border-danger-500/55 bg-danger-900/30 p-3 text-xs text-twilight-indigo-100"
        data-test="file-prompt-moderation-card"
    >
        <div class="flex items-start gap-2">
            <ShieldAlert :size="16" class="mt-0.5 shrink-0 text-danger-300" />
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <div class="font-semibold text-white">{{ moderationCardTitle }}</div>
                    <div class="rounded border border-danger-400/45 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-danger-100">
                        {{ moderationCardSummary }}
                    </div>
                </div>
            </div>
        </div>
        <div
            v-if="shouldShowPreviewCountProvenance"
            class="space-y-1 rounded border border-danger-500/30 bg-prussian-blue-900/35 p-2"
            data-test="file-prompt-preview-count-blacklist"
        >
            <div class="text-[10px] font-semibold uppercase tracking-wide text-danger-100">
                Preview count auto blacklist
            </div>
            <div class="wrap-break-word font-semibold text-white">
                No moderation rule or blacklisted container is attached.
            </div>
            <div class="wrap-break-word text-twilight-indigo-200">
                This file was auto blacklisted after repeated previews.
                <template v-if="previewCountProvenanceDetail">
                    {{ previewCountProvenanceDetail }}
                </template>
            </div>
        </div>
        <div
            v-for="entry in moderationRuleEntries"
            :key="entry.key"
            class="space-y-1 rounded border border-danger-500/30 bg-prussian-blue-900/35 p-2"
        >
            <div class="text-[10px] font-semibold uppercase tracking-wide text-danger-100">
                {{ entry.label }}
            </div>
            <div class="wrap-break-word font-semibold text-white">
                {{ formatRule(entry.rule) }}
            </div>
            <div
                v-if="formatRuleTerms(entry.rule)"
                class="wrap-break-word"
            >
                Terms: {{ formatRuleTerms(entry.rule) }}
            </div>
            <div class="wrap-break-word text-twilight-indigo-200">
                {{ entry.rule.reason }}
            </div>
        </div>
        <div
            v-if="autoBlacklistContainers.length > 0"
            class="space-y-1 rounded border border-danger-500/30 bg-prussian-blue-900/35 p-2"
            data-test="file-prompt-container-blacklist"
        >
            <div class="text-[10px] font-semibold uppercase tracking-wide text-danger-100">
                Concerned container
            </div>
            <div
                v-for="container in autoBlacklistContainers"
                :key="container.id"
                class="wrap-break-word font-semibold text-white"
            >
                {{ formatContainer(container) }}
            </div>
        </div>
    </div>
</template>
