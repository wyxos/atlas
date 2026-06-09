<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RefreshCw, RotateCcw, Tags } from 'lucide-vue-next';
import AudioMetadataProposalTable from '@/components/AudioMetadataProposalTable.vue';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { audioMetadataFieldOrder, audioMetadataProviderLabel, audioMetadataSourceLinks } from '@/lib/audioMetadataDisplay';
import type { AudioMetadataFieldOption, AudioMetadataProposal } from '@/types/audio';

type TrackDetails = {
    id: number;
    title: string;
    artists: string;
    album: string;
    coverUrl: string | null;
    source: string | null;
    duration: string;
};

const HIDDEN_METADATA_FIELDS = [
    'title_aliases',
    'artist_aliases',
    'album_aliases',
    'artist_alias_map',
];

const props = defineProps<{
    open: boolean;
    track: TrackDetails | null;
    proposal: AudioMetadataProposal | null;
    isProposalLoading: boolean;
    isRunning: boolean;
    isReviewing: boolean;
    isRestoring: boolean;
    message: string | null;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    runMetadata: [];
    restoreFromFile: [];
    applyProposal: [fields: string[], fieldOptions: Record<string, string>];
    ignoreProposal: [];
}>();

const selectedFields = ref<string[]>([]);
const selectedFieldOptions = ref<Record<string, string>>({});
const selectionTouched = ref(false);

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const isSpotifyTrack = computed(() => props.track?.source?.trim().toLowerCase() === 'spotify');

const pendingProposal = computed(() => props.proposal?.status === 'pending' ? props.proposal : null);

const proposalFields = computed(() => {
    const changes = pendingProposal.value?.changes ?? {};
    const fieldOptions = pendingProposal.value?.field_options ?? {};

    return [...Object.keys(changes), ...Object.keys(fieldOptions)]
        .filter((field) => !HIDDEN_METADATA_FIELDS.includes(field))
        .filter((field, index, fields) => fields.indexOf(field) === index)
        .sort((left, right) => audioMetadataFieldOrder(left) - audioMetadataFieldOrder(right));
});

const proposalOptionSignature = computed(() => proposalFields.value.map((field) => {
    const options = fieldOptions(field)
        .map((option) => `${option.id}:${option.recommended ? '1' : '0'}`)
        .join(',');

    return `${field}:${options}`;
}).join('|'));

watch(() => props.proposal?.id, () => {
    resetFieldSelections();
}, { immediate: true });

watch(proposalOptionSignature, () => {
    syncSelectionsWithProposalFields();
});

function metadataActionLabel(): string {
    return isSpotifyTrack.value ? 'Refresh Spotify metadata' : 'Find metadata';
}

function fieldOptions(field: string): AudioMetadataFieldOption[] {
    return pendingProposal.value?.field_options?.[field] ?? [];
}

function hasFieldOptions(field: string): boolean {
    return fieldOptions(field).length > 0;
}

function recommendedOption(field: string): AudioMetadataFieldOption | null {
    return fieldOptions(field).find((option) => option.recommended) ?? null;
}

function defaultSelectedFields(): string[] {
    return proposalFields.value.filter((field) => {
        if (pendingProposal.value?.changes?.[field]) {
            return true;
        }

        return recommendedOption(field) !== null;
    });
}

function resetFieldSelections(): void {
    const nextOptions: Record<string, string> = {};

    for (const field of proposalFields.value) {
        const recommended = recommendedOption(field);
        if (recommended) {
            nextOptions[field] = recommended.id;
        }
    }

    selectedFieldOptions.value = nextOptions;
    selectedFields.value = defaultSelectedFields();
    selectionTouched.value = false;
}

function syncSelectionsWithProposalFields(): void {
    const validFields = new Set(proposalFields.value);

    selectedFields.value = selectedFields.value.filter((field) => validFields.has(field));
    seedRecommendedFieldOptions(validFields);

    if (!selectionTouched.value) {
        selectedFields.value = defaultSelectedFields();
    }
}

function seedRecommendedFieldOptions(validFields = new Set(proposalFields.value)): void {
    const nextOptions: Record<string, string> = {};
    let changed = false;

    for (const [field, optionId] of Object.entries(selectedFieldOptions.value)) {
        if (!validFields.has(field)) {
            changed = true;

            continue;
        }

        nextOptions[field] = optionId;
    }

    for (const field of proposalFields.value) {
        if (nextOptions[field]) {
            continue;
        }

        const recommended = recommendedOption(field);
        if (recommended) {
            nextOptions[field] = recommended.id;
            changed = true;
        }
    }

    if (changed) {
        selectedFieldOptions.value = nextOptions;
    }
}

function toggleFieldSelection(field: string, checked: boolean): void {
    selectionTouched.value = true;

    if (checked) {
        if (!selectedFields.value.includes(field)) {
            selectedFields.value = [...selectedFields.value, field];
        }

        return;
    }

    selectedFields.value = selectedFields.value.filter((selectedField) => selectedField !== field);
}

function selectFieldOption(field: string, optionId: string): void {
    selectionTouched.value = true;
    selectedFieldOptions.value = {
        ...selectedFieldOptions.value,
        [field]: optionId,
    };

    if (!selectedFields.value.includes(field)) {
        selectedFields.value = [...selectedFields.value, field];
    }
}

function applyFieldOptions(): Record<string, string> {
    return Object.fromEntries(
        selectedFields.value
            .filter((field) => selectedFieldOptions.value[field])
            .map((field) => [field, selectedFieldOptions.value[field]]),
    );
}

function canApplySelected(): boolean {
    return selectedFields.value.length > 0
        && selectedFields.value.every((field) => !hasFieldOptions(field) || Boolean(selectedFieldOptions.value[field]));
}

function evidenceItems(proposal: AudioMetadataProposal): string[] {
    const evidence = proposal.evidence ?? {};
    const items: string[] = [];
    const source = typeof evidence.source === 'string' ? evidence.source : null;
    const acoustidScore = typeof evidence.acoustid_score === 'number' ? evidence.acoustid_score : null;
    const durationDelta = typeof evidence.duration_delta_seconds === 'number' ? evidence.duration_delta_seconds : null;
    const matchedFields = Array.isArray(evidence.matched_existing_fields)
        ? evidence.matched_existing_fields.filter((field): field is string => typeof field === 'string')
        : [];

    if (acoustidScore !== null) {
        items.push(`Fingerprint ${acoustidScore}%`);
    } else if (source === 'embedded_tags') {
        items.push('Embedded tags');
    } else if (source === 'multi_source_metadata_review') {
        items.push('Multiple metadata candidates');
    } else if (source === 'filename') {
        items.push('Filename fallback');
    } else if (source === 'musicbrainz_release_search') {
        items.push('MusicBrainz release search');
    } else if (source === 'discogs_release_search') {
        items.push('Discogs release search');
    } else if (source === 'existing_album_cover') {
        items.push('Existing album cover');
    }

    if (matchedFields.length > 0) {
        items.push(`Matched ${matchedFields.join(', ')}`);
    }

    if (typeof evidence.musicbrainz_recording_id === 'string') {
        items.push('MusicBrainz recording');
    }

    if (durationDelta !== null) {
        items.push(`Duration delta ${durationDelta}s`);
    }

    if (evidence.release_detail_source === 'musicbrainz_release_lookup') {
        items.push('Release details');
    }

    if (evidence.cover_source === 'cover_art_archive') {
        items.push('Cover Art Archive');
    } else if (evidence.cover_source === 'existing_album_cover') {
        items.push('Existing Atlas cover');
    }

    const aiReview = evidence.ai_review;
    if (
        aiReview !== null
        && typeof aiReview === 'object'
        && 'verdict' in aiReview
        && typeof aiReview.verdict === 'string'
    ) {
        items.push(`AI ${aiReview.verdict}`);
    }

    return items;
}

function evidenceSourceLinks(proposal: AudioMetadataProposal) {
    return audioMetadataSourceLinks(proposal.evidence);
}

function proposalAiReviewNotes(proposal: AudioMetadataProposal): string[] {
    const notes = new Set<string>();
    const fieldReview = proposal.evidence.field_review;

    if (
        fieldReview !== null
        && typeof fieldReview === 'object'
        && 'reason' in fieldReview
        && typeof fieldReview.reason === 'string'
        && fieldReview.reason.trim() !== ''
    ) {
        notes.add(fieldReview.reason.trim());
    }

    for (const reason of repeatedOptionReasons(proposal)) {
        notes.add(reason);
    }

    return [...notes];
}

function repeatedOptionReasons(proposal: AudioMetadataProposal): string[] {
    const counts = new Map<string, number>();
    const fieldOptions = proposal.field_options ?? {};

    for (const options of Object.values(fieldOptions)) {
        for (const option of options) {
            if (option.reason && option.reason_scope !== 'field') {
                counts.set(option.reason, (counts.get(option.reason) ?? 0) + 1);
            }
        }
    }

    return [...counts.entries()]
        .filter(([, count]) => count > 1)
        .map(([reason]) => reason);
}
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetContent side="right" class="flex w-full flex-col overflow-hidden p-0 sm:max-w-[72rem]" data-test="audio-track-details-sheet">
            <SheetTitle class="sr-only">Track details</SheetTitle>
            <SheetDescription class="sr-only">Review audio track details and metadata proposals.</SheetDescription>

            <div v-if="props.track" class="flex min-h-0 flex-1 flex-col">
                <div class="border-b border-twilight-indigo-500 bg-prussian-blue-800 px-6 py-5">
                    <div class="flex min-w-0 items-center gap-4">
                        <div class="flex size-20 shrink-0 items-center justify-center overflow-hidden bg-prussian-blue-900 ring-1 ring-twilight-indigo-500">
                            <img
                                v-if="props.track.coverUrl"
                                :src="props.track.coverUrl"
                                alt=""
                                class="h-full w-full object-cover"
                            >
                            <Tags v-else class="size-7 text-blue-slate-300" aria-hidden="true" />
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold text-regal-navy-100">{{ props.track.title }}</p>
                            <p class="truncate text-sm text-blue-slate-200">{{ props.track.artists }}</p>
                            <p class="truncate text-sm text-blue-slate-300">{{ props.track.album }}</p>
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <div class="grid gap-3 text-sm">
                        <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 border-b border-twilight-indigo-500/60 pb-3">
                            <span class="text-blue-slate-300">Source</span>
                            <span class="truncate text-regal-navy-100">{{ props.track.source ?? 'Library' }}</span>
                        </div>
                        <div class="grid grid-cols-[7rem_minmax(0,1fr)] gap-3 border-b border-twilight-indigo-500/60 pb-3">
                            <span class="text-blue-slate-300">Duration</span>
                            <span class="text-regal-navy-100">{{ props.track.duration }}</span>
                        </div>
                    </div>

                    <div class="mt-6 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-regal-navy-100">Metadata</p>
                            <p class="text-xs text-blue-slate-300">{{ pendingProposal ? 'Proposal ready' : 'No pending proposal' }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-2">
                            <Button
                                v-if="!isSpotifyTrack"
                                type="button"
                                size="sm"
                                variant="ghost"
                                :loading="props.isRestoring"
                                :disabled="props.isRunning || props.isReviewing || props.isRestoring"
                                data-test="audio-track-metadata-restore"
                                @click="emit('restoreFromFile')"
                            >
                                <RotateCcw class="size-4" aria-hidden="true" />
                                Restore from file
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                :disabled="props.isRunning || props.isRestoring"
                                data-test="audio-track-metadata-run"
                                @click="emit('runMetadata')"
                            >
                                <RefreshCw class="size-4" :class="props.isRunning ? 'animate-spin' : ''" aria-hidden="true" />
                                {{ metadataActionLabel() }}
                            </Button>
                        </div>
                    </div>

                    <p v-if="props.message" class="mt-3 rounded border border-smart-blue-400/40 bg-smart-blue-950/40 px-3 py-2 text-xs text-smart-blue-100">
                        {{ props.message }}
                    </p>
                    <p v-if="props.error" class="mt-3 rounded border border-danger-500/70 bg-danger-950/30 px-3 py-2 text-xs text-danger-100">
                        {{ props.error }}
                    </p>

                    <div v-if="props.isProposalLoading" class="mt-5 text-sm text-blue-slate-300">
                        Loading proposal...
                    </div>

                    <div v-else-if="pendingProposal" class="mt-5 space-y-3" data-test="audio-metadata-proposal">
                        <div class="flex items-center justify-between border-b border-twilight-indigo-500/70 pb-3">
                            <span class="text-xs font-semibold uppercase text-twilight-indigo-200">
                                {{ audioMetadataProviderLabel(pendingProposal.provider) }} - {{ pendingProposal.confidence }}%
                            </span>
                        </div>
                        <div
                            v-if="evidenceItems(pendingProposal).length > 0 || evidenceSourceLinks(pendingProposal).length > 0"
                            class="rounded border border-twilight-indigo-500/60 bg-prussian-blue-900/40 px-3 py-2 text-xs text-blue-slate-200"
                            data-test="audio-metadata-proposal-evidence"
                        >
                            <span>{{ evidenceItems(pendingProposal).join(' / ') }}</span>
                            <template
                                v-for="(link, linkIndex) in evidenceSourceLinks(pendingProposal)"
                                :key="link.key"
                            >
                                <span v-if="evidenceItems(pendingProposal).length > 0 || linkIndex > 0"> / </span>
                                <a
                                    :href="link.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-smart-blue-100 underline-offset-2 hover:underline"
                                    :data-test="`audio-metadata-source-link-${link.key}`"
                                >
                                    {{ link.label }}
                                </a>
                            </template>
                        </div>
                        <div
                            v-if="proposalAiReviewNotes(pendingProposal).length > 0"
                            class="rounded border border-twilight-indigo-500/60 bg-prussian-blue-900/30 px-3 py-2 text-xs text-blue-slate-200"
                            data-test="audio-metadata-proposal-ai-review"
                        >
                            <p class="font-semibold text-regal-navy-100">AI review</p>
                            <p
                                v-for="note in proposalAiReviewNotes(pendingProposal)"
                                :key="note"
                                class="mt-1"
                            >
                                {{ note }}
                            </p>
                        </div>

                        <AudioMetadataProposalTable
                            :proposal="pendingProposal"
                            :fields="proposalFields"
                            :selected-fields="selectedFields"
                            :selected-field-options="selectedFieldOptions"
                            @toggle-field="toggleFieldSelection"
                            @select-field-option="selectFieldOption"
                        />
                    </div>
                </div>

                <div class="flex shrink-0 items-center justify-end gap-2 border-t border-twilight-indigo-500 bg-prussian-blue-800 px-6 py-4">
                    <Button
                        v-if="pendingProposal"
                        type="button"
                        variant="ghost"
                        :disabled="props.isReviewing"
                        data-test="audio-metadata-ignore"
                        @click="emit('ignoreProposal')"
                    >
                        Ignore
                    </Button>
                    <Button
                        v-if="pendingProposal"
                        type="button"
                        :disabled="props.isReviewing || !canApplySelected()"
                        data-test="audio-metadata-apply"
                        @click="emit('applyProposal', selectedFields, applyFieldOptions())"
                    >
                        Apply selected
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
