import {
    cloneProposedTabState,
    createInitialProposedTabState,
    markProposedTabStateChecking,
    markProposedTabStateDestroyed,
    mergeProcessorResultIntoTabState,
    mergeReverbEventIntoTabState,
} from './state-merge';
import {
    emptyProposedReferrerFileState,
    type ProposedReferrerLifecycleTarget,
    type ProposedReferrerProcessorRequest,
    type ProposedReferrerProcessorResponse,
    type ProposedReverbEvent,
    type ProposedTabRuntimeState,
} from './types';

export type ProposedReferrerProcessor = {
    executeReferrerReaction: (request: ProposedReferrerProcessorRequest) => Promise<ProposedReferrerProcessorResponse>;
};

type DomReadyDocument = {
    readyState: string;
    addEventListener: (
        eventName: string,
        listener: (event: Event) => void,
        options?: AddEventListenerOptions,
    ) => void;
};

type ProposedTabRuntimeOptions = {
    instanceId: string;
    documentId: string;
    pageUrl: string;
    referrerUrl: string | null;
    referrerTargets?: ProposedReferrerLifecycleTarget[];
    atlasDomain: string;
    apiToken: string;
    processor: ProposedReferrerProcessor;
    now?: () => number;
    createRequestId?: () => string;
    onStateChanged?: (state: ProposedTabRuntimeState) => void;
};

export type ProposedTabRuntime = {
    startWhenDomReady: (documentLike?: DomReadyDocument) => void;
    startReferrerLifecycle: () => Promise<ProposedTabRuntimeState>;
    handleTabVisibilityChanged: (visibilityState: 'hidden' | 'visible' | string) => ProposedTabRuntimeState;
    handleReverbEvent: (event: ProposedReverbEvent) => ProposedTabRuntimeState;
    destroy: (reason: string) => ProposedTabRuntimeState;
    getState: () => ProposedTabRuntimeState;
    handleTabActivated?: undefined;
};

function fallbackRequestId(input: { instanceId: string; documentId: string }): string {
    return `${input.instanceId}:${input.documentId}:referrer-check`;
}

function errorMessageFor(error: unknown): string {
    return error instanceof Error && error.message.trim() !== ''
        ? error.message
        : 'Referrer processor failed.';
}

function failedProcessorResponse(
    requestId: string,
    referrerUrl: string | null,
    error: unknown,
): ProposedReferrerProcessorResponse {
    const result = emptyProposedReferrerFileState(referrerUrl);

    return {
        requestId,
        ok: false,
        status: 0,
        result,
        results: [result],
        error: errorMessageFor(error),
    };
}

function normalizeTarget(value: ProposedReferrerLifecycleTarget): ProposedReferrerLifecycleTarget | null {
    const referrerUrl = typeof value.referrerUrl === 'string' ? value.referrerUrl.trim() : '';
    if (referrerUrl === '') {
        return null;
    }

    return {
        referrerUrl,
        pageUrl: typeof value.pageUrl === 'string' && value.pageUrl.trim() !== '' ? value.pageUrl : null,
        sourceUrl: typeof value.sourceUrl === 'string' && value.sourceUrl.trim() !== '' ? value.sourceUrl : null,
    };
}

function resolveReferrerTargets(options: ProposedTabRuntimeOptions): ProposedReferrerLifecycleTarget[] {
    const explicitTargets = (options.referrerTargets ?? [])
        .map(normalizeTarget)
        .filter((target): target is ProposedReferrerLifecycleTarget => target !== null);

    if (explicitTargets.length > 0) {
        return explicitTargets;
    }

    if (options.referrerUrl === null || options.referrerUrl.trim() === '') {
        return [];
    }

    return [{
        referrerUrl: options.referrerUrl,
        pageUrl: options.pageUrl,
        sourceUrl: null,
    }];
}

export function createProposedTabRuntime(options: ProposedTabRuntimeOptions): ProposedTabRuntime {
    const now = options.now ?? Date.now;
    let state = createInitialProposedTabState({
        instanceId: options.instanceId,
        documentId: options.documentId,
        pageUrl: options.pageUrl,
        referrerUrl: options.referrerUrl,
        createdAt: now(),
    });
    const referrerTargets = resolveReferrerTargets(options);
    let lifecyclePromise: Promise<ProposedTabRuntimeState> | null = null;

    function publish(nextState: ProposedTabRuntimeState): ProposedTabRuntimeState {
        state = nextState;
        options.onStateChanged?.(cloneProposedTabState(state));

        return cloneProposedTabState(state);
    }

    async function startReferrerLifecycle(): Promise<ProposedTabRuntimeState> {
        if (state.phase === 'destroyed' || state.lifecycleRunCount > 0) {
            return cloneProposedTabState(state);
        }

        if (lifecyclePromise !== null) {
            return lifecyclePromise;
        }

        const requestId = options.createRequestId?.() ?? fallbackRequestId(options);
        publish(markProposedTabStateChecking(state, requestId, now()));

        lifecyclePromise = options.processor.executeReferrerReaction({
            requestId,
            instanceId: options.instanceId,
            documentId: options.documentId,
            pageUrl: options.pageUrl,
            referrerUrl: state.referrerUrl,
            targets: referrerTargets,
            atlasDomain: options.atlasDomain,
            apiToken: options.apiToken,
        })
            .catch((error: unknown) => failedProcessorResponse(requestId, state.referrerUrl, error))
            .then((response) => publish(mergeProcessorResultIntoTabState(state, response, now())))
            .finally(() => {
                lifecyclePromise = null;
            });

        return lifecyclePromise;
    }

    function startWhenDomReady(documentLike: DomReadyDocument = document): void {
        if (documentLike.readyState === 'loading') {
            documentLike.addEventListener('DOMContentLoaded', () => {
                void startReferrerLifecycle();
            }, { once: true });
            return;
        }

        void startReferrerLifecycle();
    }

    function handleReverbEvent(event: ProposedReverbEvent): ProposedTabRuntimeState {
        const nextState = mergeReverbEventIntoTabState(state, event, now());
        if (nextState === state) {
            return cloneProposedTabState(state);
        }

        return publish(nextState);
    }

    return {
        startWhenDomReady,
        startReferrerLifecycle,
        handleTabVisibilityChanged: () => cloneProposedTabState(state),
        handleReverbEvent,
        destroy: (reason: string) => publish(markProposedTabStateDestroyed(state, reason, now())),
        getState: () => cloneProposedTabState(state),
    };
}

export type {
    ProposedTabRuntimeOptions,
};
