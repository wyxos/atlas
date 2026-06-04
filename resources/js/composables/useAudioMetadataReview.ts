import { computed, onBeforeUnmount, ref, type Ref } from 'vue';
import type {
    AudioMetadataProposal,
    AudioMetadataRun,
    AudioMetadataRunResponse,
    AudioSourceFilter,
} from '@/types/audio';

const METADATA_RUN_POLL_MS = 1500;

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

type AudioMetadataRunSnapshot = {
    run: AudioMetadataRun;
    proposal?: AudioMetadataProposal | null;
    proposals?: AudioMetadataProposal[];
};

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => void;
};

export function useAudioMetadataReview(options: Options) {
    const detailsSheetAudioId = ref<number | null>(null);
    const isTrackDetailsSheetOpen = ref(false);
    const metadataProposalById = ref<Record<number, AudioMetadataProposal | null>>({});
    const isMetadataProposalLoading = ref(false);
    const isMetadataRunStarting = ref(false);
    const activeMetadataRunId = ref<number | null>(null);
    const activeMetadataRunAudioId = ref<number | null>(null);
    const isMetadataProposalReviewing = ref(false);
    const metadataReviewMessage = ref<string | null>(null);
    const metadataReviewError = ref<string | null>(null);
    const batchMetadataMessage = ref<string | null>(null);
    const batchMetadataError = ref<string | null>(null);
    let metadataRunPollTimer: ReturnType<typeof setTimeout> | null = null;
    let activeMetadataRunChannel: string | null = null;

    const isTrackMetadataRunBusy = computed(() => isMetadataRunStarting.value || activeMetadataRunId.value !== null);

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
            applyMetadataRunSnapshot({ run: data.run, proposal }, audioId);
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

    function applyMetadataRunSnapshot(snapshot: AudioMetadataRunSnapshot, audioId: number | null = activeMetadataRunAudioId.value): void {
        const proposal = pendingProposal(snapshot.proposal ?? snapshot.proposals?.[0] ?? null);

        if (audioId !== null) {
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: proposal,
            };
        }

        if (isMetadataRunTerminal(snapshot.run)) {
            stopMetadataRunTracking(snapshot.run.id);
            metadataReviewMessage.value = snapshot.run.status === 'completed'
                ? proposal ? 'Metadata proposal ready for review.' : 'No metadata changes found.'
                : null;
            metadataReviewError.value = snapshot.run.status === 'failed'
                ? snapshot.run.error ?? 'Metadata scan failed.'
                : metadataReviewError.value;

            return;
        }

        activeMetadataRunId.value = snapshot.run.id;
        activeMetadataRunAudioId.value = audioId;
        metadataReviewMessage.value = snapshot.run.status === 'pending'
            ? 'Metadata scan queued.'
            : metadataRunProgressMessage(snapshot.run);
        startMetadataRunEcho(snapshot.run.id);
        scheduleMetadataRunPoll();
    }

    function metadataRunProgressMessage(run: AudioMetadataRun): string {
        const total = Math.max(0, run.total_files);
        if (total <= 0) {
            return 'Scanning metadata...';
        }

        return `Scanning metadata ${run.processed_files}/${total}...`;
    }

    function isMetadataRunTerminal(run: AudioMetadataRun): boolean {
        return ['completed', 'failed'].includes(run.status);
    }

    function startMetadataRunEcho(runId: number): void {
        const channelName = `audio-metadata-runs.${runId}`;
        if (activeMetadataRunChannel === channelName) {
            return;
        }

        leaveMetadataRunEcho();

        const echo = window.Echo as undefined | {
            private: (channel: string) => EchoChannel;
        };
        if (!echo) {
            return;
        }

        activeMetadataRunChannel = channelName;
        echo.private(channelName).listen('.AudioMetadataRunUpdated', (payload: unknown) => {
            if (!payload || typeof payload !== 'object') {
                return;
            }

            const snapshot = payload as AudioMetadataRunSnapshot;
            if (snapshot.run?.id !== activeMetadataRunId.value) {
                return;
            }

            applyMetadataRunSnapshot(snapshot);
        });
    }

    function scheduleMetadataRunPoll(): void {
        if (metadataRunPollTimer) {
            clearTimeout(metadataRunPollTimer);
        }

        if (activeMetadataRunId.value === null) {
            return;
        }

        metadataRunPollTimer = setTimeout(() => {
            metadataRunPollTimer = null;
            void pollMetadataRun();
        }, METADATA_RUN_POLL_MS);
    }

    async function pollMetadataRun(): Promise<void> {
        const runId = activeMetadataRunId.value;
        if (runId === null) {
            return;
        }

        try {
            const { data } = await window.axios.get<AudioMetadataRunSnapshot>(`/api/audio/metadata-runs/${runId}`);
            applyMetadataRunSnapshot(data);
        } catch (runError) {
            console.error('Failed to poll audio metadata run:', runError);
            metadataReviewError.value = 'Failed to refresh metadata scan progress.';
            stopMetadataRunTracking(runId);
        }
    }

    function stopMetadataRunTracking(runId: number | null = activeMetadataRunId.value): void {
        if (runId !== null && activeMetadataRunId.value !== runId) {
            return;
        }

        if (metadataRunPollTimer) {
            clearTimeout(metadataRunPollTimer);
            metadataRunPollTimer = null;
        }

        activeMetadataRunId.value = null;
        activeMetadataRunAudioId.value = null;
        leaveMetadataRunEcho();
    }

    function leaveMetadataRunEcho(): void {
        if (!activeMetadataRunChannel) {
            return;
        }

        const echo = window.Echo as undefined | {
            leave: (channel: string) => void;
        };
        echo?.leave(activeMetadataRunChannel);
        activeMetadataRunChannel = null;
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

    onBeforeUnmount(() => {
        stopMetadataRunTracking();
    });

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
        isMetadataRunStarting: isTrackMetadataRunBusy,
        isTrackDetailsSheetOpen,
        metadataReviewError,
        metadataReviewMessage,
    };
}
