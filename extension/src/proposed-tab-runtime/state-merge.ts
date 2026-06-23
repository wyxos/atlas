import {
    emptyProposedReferrerFileState,
    type ProposedReferrerFileState,
    type ProposedReferrerProcessorResponse,
    type ProposedReverbEvent,
    type ProposedTabRuntimeState,
} from './types';

type InitialStateInput = {
    instanceId: string;
    documentId: string;
    pageUrl: string;
    referrerUrl: string | null;
    createdAt: number;
};

function normalizeRuntimeUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    return trimmed === '' ? null : trimmed;
}

function cloneReferrerFileState(state: ProposedReferrerFileState): ProposedReferrerFileState {
    return { ...state };
}

function cloneReferrerResultsByUrl(
    referrerResultsByUrl: Record<string, ProposedReferrerFileState>,
): Record<string, ProposedReferrerFileState> {
    return Object.fromEntries(
        Object.entries(referrerResultsByUrl).map(([url, result]) => [url, cloneReferrerFileState(result)]),
    );
}

export function cloneProposedTabState(state: ProposedTabRuntimeState): ProposedTabRuntimeState {
    return {
        ...state,
        referrerResult: state.referrerResult === null ? null : cloneReferrerFileState(state.referrerResult),
        referrerResultsByUrl: cloneReferrerResultsByUrl(state.referrerResultsByUrl),
    };
}

export function createInitialProposedTabState(input: InitialStateInput): ProposedTabRuntimeState {
    const referrerUrl = normalizeRuntimeUrl(input.referrerUrl);

    return {
        instanceId: input.instanceId,
        documentId: input.documentId,
        pageUrl: input.pageUrl,
        referrerUrl,
        phase: 'idle',
        lifecycleRunCount: 0,
        referrerResult: null,
        referrerResultsByUrl: {},
        lastRequestId: null,
        lastError: null,
        destroyReason: null,
        createdAt: input.createdAt,
        updatedAt: input.createdAt,
    };
}

export function markProposedTabStateChecking(
    state: ProposedTabRuntimeState,
    requestId: string,
    now: number,
): ProposedTabRuntimeState {
    if (state.phase === 'destroyed') {
        return state;
    }

    return {
        ...state,
        phase: 'checking',
        lastRequestId: requestId,
        lastError: null,
        updatedAt: now,
    };
}

function normalizeProcessorResult(
    result: ProposedReferrerFileState,
    fallbackReferrerUrl: string | null,
): ProposedReferrerFileState {
    return {
        ...emptyProposedReferrerFileState(normalizeRuntimeUrl(result.referrerUrl) ?? fallbackReferrerUrl),
        ...result,
        referrerUrl: normalizeRuntimeUrl(result.referrerUrl) ?? fallbackReferrerUrl,
        sourceUrl: normalizeRuntimeUrl(result.sourceUrl),
    };
}

function withResultByReferrerUrl(
    state: ProposedTabRuntimeState,
    result: ProposedReferrerFileState,
): Record<string, ProposedReferrerFileState> {
    const referrerResultsByUrl = cloneReferrerResultsByUrl(state.referrerResultsByUrl);
    const referrerUrl = normalizeRuntimeUrl(result.referrerUrl);
    if (referrerUrl !== null) {
        referrerResultsByUrl[referrerUrl] = cloneReferrerFileState(result);
    }

    return referrerResultsByUrl;
}

function withResultsByReferrerUrl(
    state: ProposedTabRuntimeState,
    results: ProposedReferrerFileState[],
): Record<string, ProposedReferrerFileState> {
    return results.reduce(
        (referrerResultsByUrl, result) => withResultByReferrerUrl({
            ...state,
            referrerResultsByUrl,
        }, result),
        cloneReferrerResultsByUrl(state.referrerResultsByUrl),
    );
}

export function mergeProcessorResultIntoTabState(
    state: ProposedTabRuntimeState,
    response: ProposedReferrerProcessorResponse,
    now: number,
): ProposedTabRuntimeState {
    if (state.phase === 'destroyed') {
        return state;
    }

    const results = Array.isArray(response.results) && response.results.length > 0
        ? response.results.map((result) => normalizeProcessorResult(result, result.referrerUrl ?? state.referrerUrl))
        : [normalizeProcessorResult(response.result, state.referrerUrl)];
    const result = results[0] ?? normalizeProcessorResult(response.result, state.referrerUrl);

    return {
        ...state,
        phase: response.ok ? 'ready' : 'failed',
        lifecycleRunCount: state.lifecycleRunCount + 1,
        referrerResult: result,
        referrerResultsByUrl: withResultsByReferrerUrl(state, results),
        lastRequestId: response.requestId,
        lastError: response.ok ? null : response.error ?? `Referrer processor failed with status ${response.status}.`,
        updatedAt: now,
    };
}

function sameRuntimeUrl(first: string | null | undefined, second: string | null | undefined): boolean {
    const normalizedFirst = normalizeRuntimeUrl(first);
    const normalizedSecond = normalizeRuntimeUrl(second);

    return normalizedFirst !== null && normalizedSecond !== null && normalizedFirst === normalizedSecond;
}

function isEventRelevantToState(state: ProposedTabRuntimeState, event: ProposedReverbEvent): boolean {
    if (sameRuntimeUrl(event.referrerUrl, state.referrerUrl)) {
        return true;
    }

    if (event.referrerUrl !== null && state.referrerResultsByUrl[normalizeRuntimeUrl(event.referrerUrl) ?? ''] !== undefined) {
        return true;
    }

    const current = state.referrerResult;
    if (current === null) {
        return false;
    }

    if (sameRuntimeUrl(event.sourceUrl, current.sourceUrl)) {
        return true;
    }

    if (typeof event.fileId === 'number' && current.fileId === event.fileId) {
        return true;
    }

    return typeof event.transferId === 'number' && current.transferId === event.transferId;
}

function mergeNullable<T>(
    nextValue: T | null | undefined,
    currentValue: T | null,
): T | null {
    return nextValue === undefined ? currentValue : nextValue;
}

function resolveCurrentResultForEvent(
    state: ProposedTabRuntimeState,
    event: ProposedReverbEvent,
): ProposedReferrerFileState {
    const referrerUrl = normalizeRuntimeUrl(event.referrerUrl);
    if (referrerUrl !== null && state.referrerResultsByUrl[referrerUrl] !== undefined) {
        return state.referrerResultsByUrl[referrerUrl];
    }

    return state.referrerResult ?? emptyProposedReferrerFileState(state.referrerUrl);
}

function shouldReplacePrimaryResult(
    state: ProposedTabRuntimeState,
    event: ProposedReverbEvent,
    next: ProposedReferrerFileState,
): boolean {
    const current = state.referrerResult;
    if (current === null) {
        return true;
    }

    return sameRuntimeUrl(event.referrerUrl, current.referrerUrl)
        || sameRuntimeUrl(event.sourceUrl, current.sourceUrl)
        || (typeof event.fileId === 'number' && current.fileId === event.fileId)
        || (typeof event.transferId === 'number' && current.transferId === event.transferId)
        || sameRuntimeUrl(next.referrerUrl, current.referrerUrl);
}

export function mergeReverbEventIntoTabState(
    state: ProposedTabRuntimeState,
    event: ProposedReverbEvent,
    now: number,
): ProposedTabRuntimeState {
    if (state.phase === 'destroyed' || !isEventRelevantToState(state, event)) {
        return state;
    }

    const current = resolveCurrentResultForEvent(state, event);
    const next: ProposedReferrerFileState = {
        ...current,
        exists: true,
        referrerUrl: normalizeRuntimeUrl(event.referrerUrl) ?? current.referrerUrl ?? state.referrerUrl,
        sourceUrl: normalizeRuntimeUrl(event.sourceUrl) ?? current.sourceUrl,
        reaction: mergeNullable(event.reaction, current.reaction),
        reactedAt: mergeNullable(event.reactedAt, current.reactedAt),
        downloadedAt: mergeNullable(event.downloadedAt, current.downloadedAt),
        blacklistedAt: mergeNullable(event.blacklistedAt, current.blacklistedAt),
        fileId: mergeNullable(event.fileId, current.fileId),
        transferId: mergeNullable(event.transferId, current.transferId),
        status: mergeNullable(event.status, current.status),
        percent: mergeNullable(event.percent, current.percent),
    };

    return {
        ...state,
        referrerResult: shouldReplacePrimaryResult(state, event, next) ? next : state.referrerResult,
        referrerResultsByUrl: withResultByReferrerUrl(state, next),
        updatedAt: now,
    };
}

export function markProposedTabStateDestroyed(
    state: ProposedTabRuntimeState,
    reason: string,
    now: number,
): ProposedTabRuntimeState {
    return {
        ...state,
        phase: 'destroyed',
        destroyReason: reason,
        updatedAt: now,
    };
}
