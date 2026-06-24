export const PROPOSED_REFERRER_REACTION_REQUEST = 'ATLAS_PROPOSED_EXECUTE_REFERRER_REACTION';
export const PROPOSED_TAB_RUNTIME_EVENT_STRATEGY = 'reverb-only-raw-event-relay';
export const PROPOSED_TAB_RUNTIME_FIRST_CUTOVER_SCOPE = 'anchor-referrer-and-file-reaction-state';

export type ProposedReactionType = 'love' | 'like' | 'funny';

export type ProposedReverbEventName =
    | 'DownloadTransferCreated'
    | 'DownloadTransferQueued'
    | 'DownloadTransferProgressUpdated';

export type ProposedReferrerFileState = {
    exists: boolean;
    reaction: ProposedReactionType | null;
    reactedAt: string | null;
    downloadedAt: string | null;
    blacklistedAt: string | null;
    referrerUrl: string | null;
    sourceUrl: string | null;
    fileId: number | null;
    transferId: number | null;
    status: string | null;
    percent: number | null;
};

export type ProposedOpenReferrerTabState = {
    referrerUrl: string;
    comparableUrl: string;
    openTabCount: number;
    isOpenInAnotherTab: boolean;
    updatedAt: number;
};

export type ProposedReferrerLifecycleTarget = {
    referrerUrl: string;
    pageUrl: string | null;
    sourceUrl: string | null;
};

export type ProposedTabRuntimePhase = 'idle' | 'checking' | 'ready' | 'failed' | 'destroyed';

export type ProposedTabRuntimeState = {
    instanceId: string;
    documentId: string;
    pageUrl: string;
    referrerUrl: string | null;
    phase: ProposedTabRuntimePhase;
    lifecycleRunCount: number;
    referrerResult: ProposedReferrerFileState | null;
    referrerResultsByUrl: Record<string, ProposedReferrerFileState>;
    openReferrerTabsByUrl: Record<string, ProposedOpenReferrerTabState>;
    lastRequestId: string | null;
    lastError: string | null;
    destroyReason: string | null;
    createdAt: number;
    updatedAt: number;
};

export type ProposedTabPresencePayload = {
    urls?: unknown;
    counts?: unknown;
};

export type ProposedReferrerPresentationKind = 'file-state' | 'same-page' | 'opened-elsewhere' | 'empty';

export type ProposedReferrerPresentation = {
    kind: ProposedReferrerPresentationKind;
    referrerUrl: string | null;
    fileState: ProposedReferrerFileState | null;
    openTabState: ProposedOpenReferrerTabState | null;
};

export type ProposedReferrerProcessorRequest = {
    requestId: string;
    instanceId: string;
    documentId: string;
    pageUrl: string;
    referrerUrl: string | null;
    targets: ProposedReferrerLifecycleTarget[];
    atlasDomain: string;
    apiToken: string;
};

export type ProposedReferrerProcessorRequestMessage = ProposedReferrerProcessorRequest & {
    type: typeof PROPOSED_REFERRER_REACTION_REQUEST;
};

export type ProposedReferrerProcessorResponse = {
    requestId: string;
    ok: boolean;
    status: number;
    result: ProposedReferrerFileState;
    results?: ProposedReferrerFileState[];
    error?: string | null;
};

export type ProposedReverbEvent = {
    eventName: ProposedReverbEventName;
    referrerUrl: string | null;
    sourceUrl: string | null;
    reaction?: ProposedReactionType | null;
    reactedAt?: string | null;
    downloadedAt?: string | null;
    blacklistedAt?: string | null;
    fileId?: number | null;
    transferId?: number | null;
    status?: string | null;
    percent?: number | null;
    payload: Record<string, unknown>;
};

export function emptyProposedReferrerFileState(referrerUrl: string | null = null): ProposedReferrerFileState {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
        referrerUrl,
        sourceUrl: null,
        fileId: null,
        transferId: null,
        status: null,
        percent: null,
    };
}
