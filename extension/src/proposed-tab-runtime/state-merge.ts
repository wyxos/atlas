import { normalizeComparableOpenTabUrl, normalizeComparableOpenTabUrls } from '../open-tab-url';
import {
    emptyProposedReferrerFileState,
    type ProposedOpenReferrerTabState,
    type ProposedReferrerFileState,
    type ProposedReferrerPresentation,
    type ProposedReferrerProcessorResponse,
    type ProposedReverbEvent,
    type ProposedTabPresencePayload,
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

function cloneOpenReferrerTabsByUrl(
    openReferrerTabsByUrl: Record<string, ProposedOpenReferrerTabState>,
): Record<string, ProposedOpenReferrerTabState> {
    return Object.fromEntries(
        Object.entries(openReferrerTabsByUrl).map(([url, tabState]) => [url, { ...tabState }]),
    );
}

export function cloneProposedTabState(state: ProposedTabRuntimeState): ProposedTabRuntimeState {
    return {
        ...state,
        referrerResult: state.referrerResult === null ? null : cloneReferrerFileState(state.referrerResult),
        referrerResultsByUrl: cloneReferrerResultsByUrl(state.referrerResultsByUrl),
        openReferrerTabsByUrl: cloneOpenReferrerTabsByUrl(state.openReferrerTabsByUrl),
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
        openReferrerTabsByUrl: {},
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

function hasVisibleFileState(result: ProposedReferrerFileState | null): boolean {
    return result !== null
        && (
            result.exists
            || result.reaction !== null
            || result.reactedAt !== null
            || result.downloadedAt !== null
            || result.blacklistedAt !== null
        );
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

function buildComparableCountMap(values: unknown): Map<string, number> {
    if (!values || typeof values !== 'object') {
        return new Map<string, number>();
    }

    const counts = new Map<string, number>();
    for (const [rawUrl, rawCount] of Object.entries(values)) {
        const comparableUrl = normalizeComparableOpenTabUrl(rawUrl);
        if (comparableUrl === null || typeof rawCount !== 'number' || !Number.isFinite(rawCount)) {
            continue;
        }

        const count = Math.max(0, Math.floor(rawCount));
        if (count > 0) {
            counts.set(comparableUrl, count);
        }
    }

    return counts;
}

function isOpenInAnotherTabForUrl(state: ProposedTabRuntimeState, comparableUrl: string, openTabCount: number): boolean {
    const comparablePageUrl = normalizeComparableOpenTabUrl(state.pageUrl);

    return comparablePageUrl === comparableUrl
        ? openTabCount > 1
        : openTabCount > 0;
}

export function mergeTabPresenceIntoTabState(
    state: ProposedTabRuntimeState,
    payload: ProposedTabPresencePayload,
    now: number,
): ProposedTabRuntimeState {
    if (state.phase === 'destroyed') {
        return state;
    }

    const changedUrls = normalizeComparableOpenTabUrls(payload.urls);
    if (changedUrls.length === 0) {
        return state;
    }

    const counts = buildComparableCountMap(payload.counts);
    const openReferrerTabsByUrl = cloneOpenReferrerTabsByUrl(state.openReferrerTabsByUrl);
    for (const comparableUrl of changedUrls) {
        const openTabCount = counts.get(comparableUrl) ?? 0;
        if (openTabCount === 0) {
            delete openReferrerTabsByUrl[comparableUrl];
            continue;
        }

        openReferrerTabsByUrl[comparableUrl] = {
            referrerUrl: comparableUrl,
            comparableUrl,
            openTabCount,
            isOpenInAnotherTab: isOpenInAnotherTabForUrl(state, comparableUrl, openTabCount),
            updatedAt: now,
        };
    }

    return {
        ...state,
        openReferrerTabsByUrl,
        updatedAt: now,
    };
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

function findFileStateForReferrer(
    state: ProposedTabRuntimeState,
    referrerUrl: string | null,
): ProposedReferrerFileState | null {
    const normalizedReferrerUrl = normalizeRuntimeUrl(referrerUrl);
    if (normalizedReferrerUrl === null) {
        return state.referrerResult;
    }

    const directResult = state.referrerResultsByUrl[normalizedReferrerUrl];
    if (directResult !== undefined) {
        return directResult;
    }

    return sameRuntimeUrl(state.referrerResult?.referrerUrl, normalizedReferrerUrl)
        ? state.referrerResult
        : null;
}

export function selectProposedReferrerPresentation(
    state: ProposedTabRuntimeState,
    referrerUrl: string | null,
): ProposedReferrerPresentation {
    const normalizedReferrerUrl = normalizeRuntimeUrl(referrerUrl);
    const comparableReferrerUrl = normalizeComparableOpenTabUrl(normalizedReferrerUrl);
    const fileState = findFileStateForReferrer(state, normalizedReferrerUrl);
    const openTabState = comparableReferrerUrl === null
        ? null
        : state.openReferrerTabsByUrl[comparableReferrerUrl] ?? null;
    const comparablePageUrl = normalizeComparableOpenTabUrl(state.pageUrl);

    if (hasVisibleFileState(fileState)) {
        return {
            kind: 'file-state',
            referrerUrl: normalizedReferrerUrl,
            fileState,
            openTabState,
        };
    }

    if (comparableReferrerUrl !== null && comparablePageUrl === comparableReferrerUrl) {
        return {
            kind: 'same-page',
            referrerUrl: normalizedReferrerUrl,
            fileState,
            openTabState,
        };
    }

    if (openTabState?.isOpenInAnotherTab === true) {
        return {
            kind: 'opened-elsewhere',
            referrerUrl: normalizedReferrerUrl,
            fileState,
            openTabState,
        };
    }

    return {
        kind: 'empty',
        referrerUrl: normalizedReferrerUrl,
        fileState,
        openTabState,
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
