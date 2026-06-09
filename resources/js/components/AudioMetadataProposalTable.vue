<script setup lang="ts">
import { computed } from 'vue';
import { Tags } from 'lucide-vue-next';
import {
    audioMetadataCoverPreviewUrl,
    audioMetadataFieldLabel,
    audioMetadataOptionSourceLabel,
    audioMetadataOptionSourceUrl,
    audioMetadataProviderLabel,
    audioMetadataSourceLinks,
    formatAudioMetadataValue,
    isAudioMetadataCoverField,
} from '@/lib/audioMetadataDisplay';
import type { AudioMetadataFieldOption, AudioMetadataProposal } from '@/types/audio';

const props = defineProps<{
    proposal: AudioMetadataProposal;
    fields: string[];
    selectedFields: string[];
    selectedFieldOptions: Record<string, string>;
}>();

const emit = defineEmits<{
    toggleField: [field: string, checked: boolean];
    selectFieldOption: [field: string, optionId: string];
}>();

const repeatedOptionReasons = computed(() => {
    const counts = new Map<string, number>();

    for (const field of props.fields) {
        for (const option of fieldOptions(field)) {
            if (option.reason && option.reason_scope !== 'field') {
                counts.set(option.reason, (counts.get(option.reason) ?? 0) + 1);
            }
        }
    }

    return new Set([...counts.entries()]
        .filter(([, count]) => count > 1)
        .map(([reason]) => reason));
});

function fieldOptions(field: string): AudioMetadataFieldOption[] {
    return props.proposal.field_options?.[field] ?? [];
}

function hasFieldOptions(field: string): boolean {
    return fieldOptions(field).length > 0;
}

function isFieldSelected(field: string): boolean {
    return props.selectedFields.includes(field);
}

function currentValueForField(field: string): unknown {
    return props.proposal.current_values[field] ?? props.proposal.changes[field]?.current ?? null;
}

function proposedValueForField(field: string): unknown {
    return props.proposal.changes[field]?.proposed ?? props.proposal.proposed_values[field] ?? null;
}

function optionNote(option: AudioMetadataFieldOption): string | null {
    if (option.reason && (option.reason_scope === 'field' || !repeatedOptionReasons.value.has(option.reason))) {
        return option.reason;
    }

    if (option.review_verdict && !option.recommended) {
        return `AI ${option.review_verdict}`;
    }

    return null;
}

function proposalSourceLink(): { label: string; url: string } | null {
    return audioMetadataSourceLinks(props.proposal.evidence)[0] ?? null;
}

function eventChecked(event: Event): boolean {
    return Boolean((event.target as HTMLInputElement | null)?.checked);
}
</script>

<template>
    <div class="overflow-x-auto rounded border border-twilight-indigo-500/60">
        <table class="min-w-[58rem] w-full table-fixed border-collapse text-left text-xs" data-test="audio-metadata-proposal-table">
            <colgroup>
                <col class="w-12">
                <col class="w-36">
                <col class="w-44">
                <col>
                <col class="w-44">
                <col class="w-24">
                <col class="w-56">
            </colgroup>
            <thead class="bg-prussian-blue-900/70 text-[0.68rem] uppercase text-blue-slate-300">
                <tr>
                    <th scope="col" class="px-3 py-2 font-semibold">Use</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Field</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Current</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Proposed</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Source</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Confidence</th>
                    <th scope="col" class="px-3 py-2 font-semibold">Notes</th>
                </tr>
            </thead>
            <template
                v-for="field in props.fields"
                :key="field"
            >
                <tbody
                    v-if="hasFieldOptions(field)"
                    class="border-t border-twilight-indigo-500/50"
                    :data-test="`audio-metadata-field-options-${field}`"
                >
                    <tr
                        v-for="(option, optionIndex) in fieldOptions(field)"
                        :key="option.id"
                        class="align-top"
                        :class="props.selectedFieldOptions[field] === option.id ? 'bg-smart-blue-950/20' : 'bg-prussian-blue-900/20'"
                        :data-test="`audio-metadata-field-option-${field}`"
                    >
                        <td
                            v-if="optionIndex === 0"
                            :rowspan="fieldOptions(field).length"
                            class="border-r border-twilight-indigo-500/40 px-3 py-3"
                        >
                            <input
                                type="checkbox"
                                :checked="isFieldSelected(field)"
                                :value="field"
                                class="size-4 accent-smart-blue-500"
                                :aria-label="`Use ${audioMetadataFieldLabel(field)}`"
                                @change="emit('toggleField', field, eventChecked($event))"
                            >
                        </td>
                        <th
                            v-if="optionIndex === 0"
                            :rowspan="fieldOptions(field).length"
                            scope="rowgroup"
                            class="border-r border-twilight-indigo-500/40 px-3 py-3 text-sm font-semibold text-regal-navy-100"
                            :data-test="`audio-metadata-proposal-field-${field}`"
                        >
                            {{ audioMetadataFieldLabel(field) }}
                        </th>
                        <td
                            v-if="optionIndex === 0"
                            :rowspan="fieldOptions(field).length"
                            class="border-r border-twilight-indigo-500/40 px-3 py-3 text-blue-slate-300"
                        >
                            <span
                                v-if="isAudioMetadataCoverField(field)"
                                class="flex aspect-square w-20 items-center justify-center overflow-hidden rounded border border-twilight-indigo-500 bg-prussian-blue-900"
                            >
                                <img
                                    v-if="audioMetadataCoverPreviewUrl(currentValueForField(field))"
                                    :src="audioMetadataCoverPreviewUrl(currentValueForField(field)) ?? ''"
                                    alt="Current cover"
                                    class="h-full w-full object-cover"
                                    data-test="audio-metadata-cover-current"
                                >
                                <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                            </span>
                            <span v-else>{{ formatAudioMetadataValue(currentValueForField(field)) }}</span>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-regal-navy-100">
                            <label class="flex min-w-0 gap-2">
                                <input
                                    type="radio"
                                    :name="`audio-metadata-option-${field}`"
                                    :value="option.id"
                                    :checked="props.selectedFieldOptions[field] === option.id"
                                    class="mt-0.5 size-4 shrink-0 accent-smart-blue-500"
                                    @change="emit('selectFieldOption', field, option.id)"
                                >
                                <span
                                    v-if="isAudioMetadataCoverField(field)"
                                    class="flex aspect-square w-20 shrink-0 items-center justify-center overflow-hidden rounded border border-smart-blue-400/60 bg-prussian-blue-900"
                                >
                                    <img
                                        v-if="audioMetadataCoverPreviewUrl(option.value)"
                                        :src="audioMetadataCoverPreviewUrl(option.value) ?? ''"
                                        alt="Provider cover"
                                        class="h-full w-full object-cover"
                                        data-test="audio-metadata-cover-option"
                                    >
                                    <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                                </span>
                                <span v-else class="min-w-0 break-words font-medium text-regal-navy-100">
                                    {{ formatAudioMetadataValue(option.value) }}
                                </span>
                            </label>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-blue-slate-200">
                            <a
                                v-if="audioMetadataOptionSourceUrl(option)"
                                :href="audioMetadataOptionSourceUrl(option) ?? undefined"
                                target="_blank"
                                rel="noopener"
                                class="text-smart-blue-100 underline-offset-2 hover:underline"
                                data-test="audio-metadata-option-source-link"
                            >
                                {{ audioMetadataOptionSourceLabel(option) }}
                            </a>
                            <span v-else>{{ audioMetadataOptionSourceLabel(option) }}</span>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-smart-blue-100">
                            {{ option.confidence }}%
                        </td>
                        <td class="px-3 py-3 text-blue-slate-300">
                            <span v-if="option.recommended" class="mb-1 inline-flex rounded border border-smart-blue-400/50 px-2 py-0.5 text-[0.68rem] font-semibold uppercase text-smart-blue-100">
                                Recommended
                            </span>
                            <span v-if="optionNote(option)" class="block break-words" data-test="audio-metadata-option-note">{{ optionNote(option) }}</span>
                        </td>
                    </tr>
                </tbody>
                <tbody
                    v-else
                    class="border-t border-twilight-indigo-500/50 bg-prussian-blue-900/20"
                >
                    <tr
                        class="align-top"
                        :data-test="`audio-metadata-proposal-field-${field}`"
                    >
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3">
                            <input
                                type="checkbox"
                                :checked="isFieldSelected(field)"
                                :value="field"
                                class="size-4 accent-smart-blue-500"
                                :aria-label="`Use ${audioMetadataFieldLabel(field)}`"
                                @change="emit('toggleField', field, eventChecked($event))"
                            >
                        </td>
                        <th scope="row" class="border-r border-twilight-indigo-500/40 px-3 py-3 text-sm font-semibold text-regal-navy-100">
                            {{ audioMetadataFieldLabel(field) }}
                        </th>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-blue-slate-300">
                            <span
                                v-if="isAudioMetadataCoverField(field)"
                                class="flex aspect-square w-20 items-center justify-center overflow-hidden rounded border border-twilight-indigo-500 bg-prussian-blue-900"
                            >
                                <img
                                    v-if="audioMetadataCoverPreviewUrl(currentValueForField(field))"
                                    :src="audioMetadataCoverPreviewUrl(currentValueForField(field)) ?? ''"
                                    alt="Current cover"
                                    class="h-full w-full object-cover"
                                    data-test="audio-metadata-cover-current"
                                >
                                <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                            </span>
                            <span v-else>{{ formatAudioMetadataValue(currentValueForField(field)) }}</span>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 font-medium text-smart-blue-100">
                            <span
                                v-if="isAudioMetadataCoverField(field)"
                                class="flex aspect-square w-20 items-center justify-center overflow-hidden rounded border border-smart-blue-400/60 bg-prussian-blue-900"
                            >
                                <img
                                    v-if="audioMetadataCoverPreviewUrl(proposedValueForField(field))"
                                    :src="audioMetadataCoverPreviewUrl(proposedValueForField(field)) ?? ''"
                                    alt="Proposed cover"
                                    class="h-full w-full object-cover"
                                    data-test="audio-metadata-cover-proposed"
                                >
                                <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                            </span>
                            <span v-else class="break-words">{{ formatAudioMetadataValue(proposedValueForField(field)) }}</span>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-blue-slate-200">
                            <a
                                v-if="proposalSourceLink()"
                                :href="proposalSourceLink()?.url"
                                target="_blank"
                                rel="noopener"
                                class="text-smart-blue-100 underline-offset-2 hover:underline"
                                data-test="audio-metadata-option-source-link"
                            >
                                {{ proposalSourceLink()?.label }}
                            </a>
                            <span v-else>{{ audioMetadataProviderLabel(props.proposal.provider) }}</span>
                        </td>
                        <td class="border-r border-twilight-indigo-500/40 px-3 py-3 text-smart-blue-100">
                            {{ props.proposal.confidence }}%
                        </td>
                        <td class="px-3 py-3 text-blue-slate-300">Recommended</td>
                    </tr>
                </tbody>
            </template>
        </table>
    </div>
</template>
