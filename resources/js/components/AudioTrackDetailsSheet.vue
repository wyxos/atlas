<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { RefreshCw, Tags } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import type { AudioMetadataProposal } from '@/types/audio';

type TrackDetails = {
    id: number;
    title: string;
    artists: string;
    album: string;
    coverUrl: string | null;
    source: string | null;
    duration: string;
};

const props = defineProps<{
    open: boolean;
    track: TrackDetails | null;
    proposal: AudioMetadataProposal | null;
    isProposalLoading: boolean;
    isRunning: boolean;
    isReviewing: boolean;
    message: string | null;
    error: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    runMetadata: [];
    applyProposal: [fields: string[]];
    ignoreProposal: [];
}>();

const selectedFields = ref<string[]>([]);

const isOpen = computed({
    get: () => props.open,
    set: (value: boolean) => emit('update:open', value),
});

const isSpotifyTrack = computed(() => props.track?.source?.trim().toLowerCase() === 'spotify');

const pendingProposal = computed(() => props.proposal?.status === 'pending' ? props.proposal : null);

const proposalFields = computed(() => {
    const changes = pendingProposal.value?.changes ?? {};

    return Object.keys(changes);
});

watch(() => props.proposal?.id, () => {
    selectedFields.value = [...proposalFields.value];
});

watch(proposalFields, (fields) => {
    selectedFields.value = selectedFields.value.filter((field) => fields.includes(field));
    if (selectedFields.value.length === 0) {
        selectedFields.value = [...fields];
    }
});

function metadataActionLabel(): string {
    return isSpotifyTrack.value ? 'Refresh Spotify metadata' : 'Find metadata';
}

function fieldLabel(field: string): string {
    return {
        title: 'Title',
        artists: 'Artists',
        album: 'Album',
        duration_seconds: 'Duration',
        cover_url: 'Cover',
        spotify_uri: 'Spotify URI',
    }[field] ?? field;
}

function formatValue(value: unknown): string {
    if (Array.isArray(value)) {
        return value.length > 0 ? value.join(', ') : 'None';
    }

    if (value === null || value === undefined || value === '') {
        return 'None';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return String(value);
}

function isCoverField(field: string): boolean {
    return field === 'cover_url';
}

function coverPreviewUrl(value: unknown): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    return trimmed.replace(/^http:\/\/coverartarchive\.org\//i, 'https://coverartarchive.org/');
}

function providerLabel(provider: string): string {
    if (provider === 'acoustid_musicbrainz') {
        return 'AcoustID / MusicBrainz';
    }

    if (provider === 'musicbrainz_cover_art') {
        return 'MusicBrainz Cover Art';
    }

    return provider
        .split(/[_\s-]+/)
        .filter(Boolean)
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
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
    } else if (source === 'filename') {
        items.push('Filename fallback');
    } else if (source === 'musicbrainz_release_search') {
        items.push('MusicBrainz release search');
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

    if (evidence.cover_source === 'cover_art_archive') {
        items.push('Cover Art Archive');
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
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetContent side="right" class="flex w-full flex-col overflow-hidden p-0 sm:max-w-xl">
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
                        <Button
                            type="button"
                            size="sm"
                            :disabled="props.isRunning"
                            data-test="audio-track-metadata-run"
                            @click="emit('runMetadata')"
                        >
                            <RefreshCw class="mr-2 size-4" :class="props.isRunning ? 'animate-spin' : ''" aria-hidden="true" />
                            {{ metadataActionLabel() }}
                        </Button>
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
                                {{ providerLabel(pendingProposal.provider) }} - {{ pendingProposal.confidence }}%
                            </span>
                        </div>
                        <div
                            v-if="evidenceItems(pendingProposal).length > 0"
                            class="rounded border border-twilight-indigo-500/60 bg-prussian-blue-900/40 px-3 py-2 text-xs text-blue-slate-200"
                            data-test="audio-metadata-proposal-evidence"
                        >
                            {{ evidenceItems(pendingProposal).join(' / ') }}
                        </div>

                        <label
                            v-for="field in proposalFields"
                            :key="field"
                            class="grid gap-2 border-b border-twilight-indigo-500/50 pb-3"
                            :data-test="`audio-metadata-proposal-field-${field}`"
                        >
                            <span class="flex items-center gap-2 text-sm font-medium text-regal-navy-100">
                                <input
                                    v-model="selectedFields"
                                    type="checkbox"
                                    :value="field"
                                    class="size-4 accent-smart-blue-500"
                                >
                                {{ fieldLabel(field) }}
                            </span>
                            <span v-if="isCoverField(field)" class="grid grid-cols-2 gap-3 pl-6 text-xs sm:max-w-sm">
                                <span class="grid gap-1">
                                    <span class="text-blue-slate-300">Current</span>
                                    <span class="flex aspect-square w-20 items-center justify-center overflow-hidden rounded border border-twilight-indigo-500 bg-prussian-blue-900 sm:w-24">
                                        <img
                                            v-if="coverPreviewUrl(pendingProposal.changes[field]?.current)"
                                            :src="coverPreviewUrl(pendingProposal.changes[field]?.current) ?? ''"
                                            alt="Current cover"
                                            class="h-full w-full object-cover"
                                            data-test="audio-metadata-cover-current"
                                        >
                                        <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                                    </span>
                                </span>
                                <span class="grid gap-1">
                                    <span class="text-smart-blue-100">Proposed</span>
                                    <span class="flex aspect-square w-20 items-center justify-center overflow-hidden rounded border border-smart-blue-400/60 bg-prussian-blue-900 sm:w-24">
                                        <img
                                            v-if="coverPreviewUrl(pendingProposal.changes[field]?.proposed)"
                                            :src="coverPreviewUrl(pendingProposal.changes[field]?.proposed) ?? ''"
                                            alt="Proposed cover"
                                            class="h-full w-full object-cover"
                                            data-test="audio-metadata-cover-proposed"
                                        >
                                        <Tags v-else class="size-5 text-blue-slate-400" aria-hidden="true" />
                                    </span>
                                </span>
                            </span>
                            <span v-else class="grid gap-1 pl-6 text-xs">
                                <span class="text-blue-slate-300">Current: {{ formatValue(pendingProposal.changes[field]?.current) }}</span>
                                <span class="text-smart-blue-100">Proposed: {{ formatValue(pendingProposal.changes[field]?.proposed) }}</span>
                            </span>
                        </label>
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
                        :disabled="props.isReviewing || selectedFields.length === 0"
                        data-test="audio-metadata-apply"
                        @click="emit('applyProposal', selectedFields)"
                    >
                        Apply selected
                    </Button>
                </div>
            </div>
        </SheetContent>
    </Sheet>
</template>
