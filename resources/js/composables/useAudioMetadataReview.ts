import { computed, ref, type Ref } from 'vue';
import type {
    AudioMetadataProposal,
    AudioMetadataRunResponse,
    AudioSourceFilter,
} from '@/types/audio';

type Options = {
    activeFilter: Ref<AudioSourceFilter>;
    selectedAudioId: Ref<number | null>;
    hasDetails: (audioId: number) => boolean;
    fetchAudioDetails: (ids: number[], force?: boolean) => Promise<void>;
    detailTitle: (audioId: number) => string;
    detailArtists: (audioId: number) => string;
    detailAlbum: (audioId: number) => string;
    detailCoverUrl: (audioId: number) => string | null;
    detailSource: (audioId: number) => string | null;
    detailDuration: (audioId: number) => string;
};

export function useAudioMetadataReview(options: Options) {
    const detailsSheetAudioId = ref<number | null>(null);
    const isTrackDetailsSheetOpen = ref(false);
    const metadataProposalById = ref<Record<number, AudioMetadataProposal | null>>({});
    const isMetadataProposalLoading = ref(false);
    const isMetadataRunStarting = ref(false);
    const isMetadataProposalReviewing = ref(false);
    const metadataReviewMessage = ref<string | null>(null);
    const metadataReviewError = ref<string | null>(null);
    const batchMetadataMessage = ref<string | null>(null);
    const batchMetadataError = ref<string | null>(null);

    const detailsSheetTrack = computed(() => {
        const audioId = detailsSheetAudioId.value;
        if (audioId === null) {
            return null;
        }

        return {
            id: audioId,
            title: options.detailTitle(audioId),
            artists: options.detailArtists(audioId),
            album: options.detailAlbum(audioId),
            coverUrl: options.detailCoverUrl(audioId),
            source: options.detailSource(audioId),
            duration: options.detailDuration(audioId),
        };
    });

    const detailsSheetProposal = computed(() => {
        const audioId = detailsSheetAudioId.value;

        return audioId !== null ? metadataProposalById.value[audioId] ?? null : null;
    });

    function pendingProposal(proposal: AudioMetadataProposal | null | undefined): AudioMetadataProposal | null {
        return proposal?.status === 'pending' ? proposal : null;
    }

    async function fetchLatestMetadataProposal(audioId: number): Promise<void> {
        isMetadataProposalLoading.value = true;
        metadataReviewError.value = null;

        try {
            const { data } = await window.axios.get<{ proposal: AudioMetadataProposal | null }>(`/api/audio/${audioId}/metadata-proposals/latest`);
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
        } catch (proposalError) {
            console.error('Failed to load audio metadata proposal:', proposalError);
            metadataReviewError.value = 'Failed to load metadata proposal.';
        } finally {
            isMetadataProposalLoading.value = false;
        }
    }

    async function handleAudioDetailsOpen(audioId: number): Promise<void> {
        options.selectedAudioId.value = audioId;
        detailsSheetAudioId.value = audioId;
        isTrackDetailsSheetOpen.value = true;
        metadataReviewMessage.value = null;
        metadataReviewError.value = null;

        if (!options.hasDetails(audioId)) {
            await options.fetchAudioDetails([audioId], true);
        }

        await fetchLatestMetadataProposal(audioId);
    }

    async function handleTrackMetadataRun(): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        if (audioId === null) {
            return;
        }

        isMetadataRunStarting.value = true;
        metadataReviewMessage.value = null;
        metadataReviewError.value = null;

        try {
            const { data } = await window.axios.post<AudioMetadataRunResponse>(`/api/audio/${audioId}/metadata-runs`);
            const proposal = pendingProposal(data.proposal);
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: proposal,
            };
            metadataReviewMessage.value = proposal ? 'Metadata proposal ready for review.' : 'No metadata changes found.';
        } catch (runError) {
            console.error('Failed to start audio metadata run:', runError);
            metadataReviewError.value = 'Failed to start metadata scan.';
        } finally {
            isMetadataRunStarting.value = false;
        }
    }

    async function handleMetadataProposalApply(fields: string[]): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        const proposal = detailsSheetProposal.value;
        if (audioId === null || !proposal) {
            return;
        }

        isMetadataProposalReviewing.value = true;
        metadataReviewMessage.value = null;
        metadataReviewError.value = null;

        try {
            const { data } = await window.axios.patch<{ proposal: AudioMetadataProposal }>(`/api/audio/metadata-proposals/${proposal.id}`, {
                action: 'apply',
                fields,
            });
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
            await options.fetchAudioDetails([audioId], true);
            metadataReviewMessage.value = 'Metadata applied.';
        } catch (reviewError) {
            console.error('Failed to apply audio metadata proposal:', reviewError);
            metadataReviewError.value = 'Failed to apply metadata proposal.';
        } finally {
            isMetadataProposalReviewing.value = false;
        }
    }

    async function handleMetadataProposalIgnore(): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        const proposal = detailsSheetProposal.value;
        if (audioId === null || !proposal) {
            return;
        }

        isMetadataProposalReviewing.value = true;
        metadataReviewMessage.value = null;
        metadataReviewError.value = null;

        try {
            const { data } = await window.axios.patch<{ proposal: AudioMetadataProposal }>(`/api/audio/metadata-proposals/${proposal.id}`, {
                action: 'ignore',
            });
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
            metadataReviewMessage.value = 'Metadata proposal ignored.';
        } catch (reviewError) {
            console.error('Failed to ignore audio metadata proposal:', reviewError);
            metadataReviewError.value = 'Failed to ignore metadata proposal.';
        } finally {
            isMetadataProposalReviewing.value = false;
        }
    }

    async function handleBatchMetadataRun(): Promise<void> {
        batchMetadataMessage.value = null;
        batchMetadataError.value = null;

        try {
            const sourceFilter = options.activeFilter.value === 'all' ? 'all' : options.activeFilter.value;
            const { data } = await window.axios.post<AudioMetadataRunResponse>('/api/audio/metadata-runs', {
                scope: 'all',
                source_filter: sourceFilter,
            });

            batchMetadataMessage.value = `Metadata scan queued for ${data.run.total_files} tracks.`;
        } catch (runError) {
            console.error('Failed to queue audio metadata run:', runError);
            batchMetadataError.value = 'Failed to queue metadata scan.';
        }
    }

    return {
        batchMetadataError,
        batchMetadataMessage,
        detailsSheetProposal,
        detailsSheetTrack,
        handleAudioDetailsOpen,
        handleBatchMetadataRun,
        handleMetadataProposalApply,
        handleMetadataProposalIgnore,
        handleTrackMetadataRun,
        isMetadataProposalLoading,
        isMetadataProposalReviewing,
        isMetadataRunStarting,
        isTrackDetailsSheetOpen,
        metadataReviewError,
        metadataReviewMessage,
    };
}
