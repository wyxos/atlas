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

type MetadataRunKind = 'track' | 'batch';

type EchoChannel = {
    listen: (event: string, callback: (payload: unknown) => void) => void;
};

export function useAudioMetadataReview(options: Options) {
    const detailsSheetAudioId = ref<number | null>(null);
    const isTrackDetailsSheetOpen = ref(false);
    const metadataProposalById = ref<Record<number, AudioMetadataProposal | null>>({});
    const isMetadataProposalLoading = ref(false);
    const isMetadataRunStarting = ref(false);
    const metadataRunStartingAudioId = ref<number | null>(null);
    const activeMetadataRunId = ref<number | null>(null);
    const activeMetadataRunAudioId = ref<number | null>(null);
    const activeMetadataRunKind = ref<MetadataRunKind | null>(null);
    const batchMetadataRun = ref<AudioMetadataRun | null>(null);
    const isMetadataProposalReviewing = ref(false);
    const isMetadataRestoring = ref(false);
    const metadataRestoringAudioId = ref<number | null>(null);
    const metadataReviewMessage = ref<string | null>(null);
    const metadataReviewMessageAudioId = ref<number | null>(null);
    const metadataReviewError = ref<string | null>(null);
    const metadataReviewErrorAudioId = ref<number | null>(null);
    const batchMetadataMessage = ref<string | null>(null);
    const batchMetadataError = ref<string | null>(null);
    let metadataRunPollTimer: ReturnType<typeof setTimeout> | null = null;
    let activeMetadataRunChannel: string | null = null;

    const batchMetadataProgressPercent = computed(() => (
        batchMetadataRun.value ? metadataRunProgressPercent(batchMetadataRun.value) : null
    ));

    const batchMetadataProgressLabel = computed(() => (
        batchMetadataRun.value ? metadataRunFilesProgressLabel(batchMetadataRun.value) : null
    ));

    const isBatchMetadataRunActive = computed(() => (
        batchMetadataRun.value !== null && !isMetadataRunTerminal(batchMetadataRun.value)
    ));

    const isTrackMetadataRunBusy = computed(() => {
        const audioId = detailsSheetAudioId.value;

        return audioId !== null && (
            (isMetadataRunStarting.value && metadataRunStartingAudioId.value === audioId)
            || (activeMetadataRunId.value !== null && activeMetadataRunAudioId.value === audioId)
        );
    });

    const isTrackMetadataRestoring = computed(() => {
        const audioId = detailsSheetAudioId.value;

        return audioId !== null && isMetadataRestoring.value && metadataRestoringAudioId.value === audioId;
    });

    const visibleMetadataReviewMessage = computed(() => (
        metadataReviewMessageAudioId.value === detailsSheetAudioId.value ? metadataReviewMessage.value : null
    ));

    const visibleMetadataReviewError = computed(() => (
        metadataReviewErrorAudioId.value === detailsSheetAudioId.value ? metadataReviewError.value : null
    ));

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

    function setMetadataReviewMessage(audioId: number | null, message: string | null): void {
        metadataReviewMessageAudioId.value = audioId;
        metadataReviewMessage.value = message;
    }

    function setMetadataReviewError(audioId: number | null, error: string | null): void {
        metadataReviewErrorAudioId.value = audioId;
        metadataReviewError.value = error;
    }

    async function fetchLatestMetadataProposal(audioId: number): Promise<void> {
        isMetadataProposalLoading.value = true;
        setMetadataReviewError(audioId, null);

        try {
            const { data } = await window.axios.get<{ proposal: AudioMetadataProposal | null }>(`/api/audio/${audioId}/metadata-proposals/latest`);
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
        } catch (proposalError) {
            console.error('Failed to load audio metadata proposal:', proposalError);
            setMetadataReviewError(audioId, 'Failed to load metadata proposal.');
        } finally {
            isMetadataProposalLoading.value = false;
        }
    }

    async function handleAudioDetailsOpen(audioId: number): Promise<void> {
        options.selectedAudioId.value = audioId;
        detailsSheetAudioId.value = audioId;
        isTrackDetailsSheetOpen.value = true;
        setMetadataReviewMessage(audioId, null);
        setMetadataReviewError(audioId, null);

        if (!options.hasDetails(audioId)) {
            await options.fetchAudioDetails([audioId], true);
        }

        await fetchLatestMetadataProposal(audioId);
    }

    function closeTrackDetailsForAudioIds(audioIds: number[]): void {
        const audioId = detailsSheetAudioId.value;
        if (audioId === null || !audioIds.includes(audioId)) {
            return;
        }

        isTrackDetailsSheetOpen.value = false;
        detailsSheetAudioId.value = null;
        metadataProposalById.value = {
            ...metadataProposalById.value,
            [audioId]: null,
        };

        if (options.selectedAudioId.value === audioId) {
            options.selectedAudioId.value = null;
        }

        setMetadataReviewMessage(null, null);
        setMetadataReviewError(null, null);

        if (activeMetadataRunAudioId.value === audioId) {
            stopMetadataRunTracking();
        }
    }

    async function handleTrackMetadataRun(): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        if (audioId === null || isTrackMetadataRunBusy.value) {
            return;
        }

        isMetadataRunStarting.value = true;
        metadataRunStartingAudioId.value = audioId;
        setMetadataReviewMessage(audioId, null);
        setMetadataReviewError(audioId, null);

        try {
            const { data } = await window.axios.post<AudioMetadataRunResponse>(`/api/audio/${audioId}/metadata-runs`);
            const proposal = pendingProposal(data.proposal);
            applyMetadataRunSnapshot({ run: data.run, proposal }, audioId, 'track');
        } catch (runError) {
            console.error('Failed to start audio metadata run:', runError);
            setMetadataReviewError(audioId, 'Failed to start metadata scan.');
        } finally {
            isMetadataRunStarting.value = false;
            if (metadataRunStartingAudioId.value === audioId) {
                metadataRunStartingAudioId.value = null;
            }
        }
    }

    async function handleMetadataProposalApply(fields: string[], fieldOptions: Record<string, string> = {}): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        const proposal = detailsSheetProposal.value;
        if (audioId === null || !proposal) {
            return;
        }

        isMetadataProposalReviewing.value = true;
        setMetadataReviewMessage(audioId, null);
        setMetadataReviewError(audioId, null);

        try {
            const payload: { action: 'apply'; fields: string[]; field_options?: Record<string, string> } = {
                action: 'apply',
                fields,
            };
            if (Object.keys(fieldOptions).length > 0) {
                payload.field_options = fieldOptions;
            }

            const { data } = await window.axios.patch<{ proposal: AudioMetadataProposal }>(`/api/audio/metadata-proposals/${proposal.id}`, {
                ...payload,
            });
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
            await options.fetchAudioDetails([audioId], true);
            setMetadataReviewMessage(audioId, 'Metadata applied.');
        } catch (reviewError) {
            console.error('Failed to apply audio metadata proposal:', reviewError);
            setMetadataReviewError(audioId, 'Failed to apply metadata proposal.');
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
        setMetadataReviewMessage(audioId, null);
        setMetadataReviewError(audioId, null);

        try {
            const { data } = await window.axios.patch<{ proposal: AudioMetadataProposal }>(`/api/audio/metadata-proposals/${proposal.id}`, {
                action: 'ignore',
            });
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: pendingProposal(data.proposal),
            };
            setMetadataReviewMessage(audioId, 'Metadata proposal ignored.');
        } catch (reviewError) {
            console.error('Failed to ignore audio metadata proposal:', reviewError);
            setMetadataReviewError(audioId, 'Failed to ignore metadata proposal.');
        } finally {
            isMetadataProposalReviewing.value = false;
        }
    }

    async function handleRestoreMetadataFromFile(): Promise<void> {
        const audioId = detailsSheetAudioId.value;
        if (audioId === null || isTrackMetadataRestoring.value || isTrackMetadataRunBusy.value) {
            return;
        }

        isMetadataRestoring.value = true;
        metadataRestoringAudioId.value = audioId;
        setMetadataReviewMessage(audioId, null);
        setMetadataReviewError(audioId, null);

        try {
            await window.axios.post(`/api/audio/${audioId}/metadata/restore-from-file`);
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [audioId]: null,
            };
            await options.fetchAudioDetails([audioId], true);
            setMetadataReviewMessage(audioId, 'Metadata restored from file.');
        } catch (restoreError) {
            console.error('Failed to restore audio metadata from file:', restoreError);
            setMetadataReviewError(audioId, 'Failed to restore metadata from file.');
        } finally {
            isMetadataRestoring.value = false;
            if (metadataRestoringAudioId.value === audioId) {
                metadataRestoringAudioId.value = null;
            }
        }
    }

    function applyMetadataRunSnapshot(
        snapshot: AudioMetadataRunSnapshot,
        audioId: number | null = activeMetadataRunAudioId.value,
        runKind: MetadataRunKind = activeMetadataRunKind.value ?? 'track',
    ): void {
        const snapshotAudioId = audioId ?? snapshot.run.current_file_id ?? null;
        const proposal = pendingProposal(snapshot.proposal ?? snapshot.proposals?.[0] ?? null);
        const proposalAudioId = proposal?.file_id ?? snapshotAudioId;
        const shouldRefreshCompactProposal = proposalAudioId !== null && proposal?.is_compact === true;

        if (proposalAudioId !== null) {
            metadataProposalById.value = {
                ...metadataProposalById.value,
                [proposalAudioId]: proposal,
            };
        }

        if (shouldRefreshCompactProposal) {
            void fetchLatestMetadataProposal(proposalAudioId);
        }

        if (runKind === 'batch') {
            batchMetadataRun.value = snapshot.run;
            batchMetadataMessage.value = batchMetadataRunMessage(snapshot.run);
        }

        if (isMetadataRunTerminal(snapshot.run)) {
            stopMetadataRunTracking(snapshot.run.id);

            if (runKind === 'batch') {
                batchMetadataRun.value = snapshot.run;
                batchMetadataMessage.value = batchMetadataRunMessage(snapshot.run);

                if (snapshot.run.status === 'failed') {
                    batchMetadataError.value = snapshot.run.error ?? 'Metadata scan failed.';
                }

                return;
            }

            setMetadataReviewMessage(snapshotAudioId, metadataRunTerminalMessage(snapshot.run, proposal));

            if (snapshot.run.status === 'failed' || (snapshot.run.failed_files > 0 && snapshot.run.error)) {
                setMetadataReviewError(snapshotAudioId, snapshot.run.error ?? 'Metadata scan failed.');
            }

            return;
        }

        activeMetadataRunId.value = snapshot.run.id;
        activeMetadataRunAudioId.value = runKind === 'track' ? snapshotAudioId : null;
        activeMetadataRunKind.value = runKind;

        if (runKind === 'track') {
            setMetadataReviewMessage(snapshotAudioId, snapshot.run.status === 'pending'
                ? 'Metadata scan queued.'
                : metadataRunProgressMessage(snapshot.run));
        }

        startMetadataRunEcho(snapshot.run.id);
        scheduleMetadataRunPoll();
    }

    function metadataRunTerminalMessage(run: AudioMetadataRun, proposal: AudioMetadataProposal | null): string | null {
        if (run.status !== 'completed') {
            return null;
        }

        if (proposal) {
            return 'Metadata proposal ready for review.';
        }

        if (run.failed_files > 0) {
            return 'Metadata lookup failed.';
        }

        return 'No metadata changes found.';
    }

    function metadataRunProgressMessage(run: AudioMetadataRun): string {
        const label = typeof run.current_step_label === 'string' ? run.current_step_label.trim() : '';
        const total = Math.max(0, run.total_files);

        if (label !== '') {
            return total > 1
                ? `${label} (${run.processed_files}/${total})...`
                : `${label}...`;
        }

        if (total <= 0) {
            return 'Scanning metadata...';
        }

        return `Scanning metadata ${run.processed_files}/${total}...`;
    }

    function batchMetadataRunMessage(run: AudioMetadataRun): string {
        const progressLabel = metadataRunFilesProgressLabel(run);

        if (run.status === 'completed') {
            const proposalSummary = run.proposal_count === 1
                ? ' 1 proposal ready.'
                : run.proposal_count > 1
                    ? ` ${run.proposal_count} proposals ready.`
                    : '';

            return `Metadata scan completed: ${progressLabel}.${proposalSummary}`;
        }

        if (run.status === 'failed') {
            return `Metadata scan failed: ${progressLabel}.`;
        }

        if (run.status === 'pending') {
            return `Metadata scan queued: ${progressLabel}.`;
        }

        return `Metadata scan running: ${progressLabel}.`;
    }

    function metadataRunFilesProgressLabel(run: AudioMetadataRun): string {
        const processed = Math.max(0, run.processed_files);
        const total = Math.max(0, run.total_files);

        return `${processed}/${total} files (${metadataRunProgressPercent(run)}%)`;
    }

    function metadataRunProgressPercent(run: AudioMetadataRun): number {
        const total = Math.max(0, run.total_files);

        if (total === 0) {
            return isMetadataRunTerminal(run) ? 100 : 0;
        }

        return Math.min(100, Math.max(0, Math.round((Math.max(0, run.processed_files) / total) * 100)));
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
            setMetadataReviewError(activeMetadataRunAudioId.value, 'Failed to refresh metadata scan progress.');
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
        activeMetadataRunKind.value = null;
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

    async function startBatchMetadataRun(sourceFilter: AudioSourceFilter): Promise<void> {
        batchMetadataMessage.value = null;
        batchMetadataError.value = null;
        batchMetadataRun.value = null;

        try {
            const { data } = await window.axios.post<AudioMetadataRunResponse>('/api/audio/metadata-runs', {
                scope: 'all',
                source_filter: sourceFilter,
            });

            applyMetadataRunSnapshot({ run: data.run, proposal: data.proposal ?? null }, null, 'batch');
        } catch (runError) {
            console.error('Failed to queue audio metadata run:', runError);
            batchMetadataError.value = 'Failed to queue metadata scan.';
        }
    }

    async function handleBatchMetadataRun(): Promise<void> {
        const sourceFilter = options.activeFilter.value === 'all' ? 'all' : options.activeFilter.value;

        await startBatchMetadataRun(sourceFilter);
    }

    async function handleLibraryMetadataRun(): Promise<void> {
        await startBatchMetadataRun('all');
    }

    onBeforeUnmount(() => {
        stopMetadataRunTracking();
    });

    return {
        batchMetadataError,
        batchMetadataMessage,
        batchMetadataProgressLabel,
        batchMetadataProgressPercent,
        closeTrackDetailsForAudioIds,
        detailsSheetProposal,
        detailsSheetTrack,
        handleAudioDetailsOpen,
        handleBatchMetadataRun,
        handleLibraryMetadataRun,
        handleMetadataProposalApply,
        handleMetadataProposalIgnore,
        handleRestoreMetadataFromFile,
        handleTrackMetadataRun,
        isMetadataProposalLoading,
        isMetadataProposalReviewing,
        isMetadataRestoring: isTrackMetadataRestoring,
        isMetadataRunStarting: isTrackMetadataRunBusy,
        isBatchMetadataRunActive,
        isTrackDetailsSheetOpen,
        metadataReviewError: visibleMetadataReviewError,
        metadataReviewMessage: visibleMetadataReviewMessage,
    };
}
