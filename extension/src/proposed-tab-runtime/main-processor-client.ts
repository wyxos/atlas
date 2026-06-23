import {
    emptyProposedReferrerFileState,
    PROPOSED_REFERRER_REACTION_REQUEST,
    type ProposedReferrerFileState,
    type ProposedReferrerProcessorRequest,
    type ProposedReferrerProcessorRequestMessage,
    type ProposedReferrerProcessorResponse,
} from './types';

type ProposedRuntimeSendMessage = (
    message: ProposedReferrerProcessorRequestMessage,
    callback: (response: unknown) => void,
) => void;

type ProposedMainProcessorClientOptions = {
    sendMessage?: ProposedRuntimeSendMessage;
    createRequestId?: () => string;
};

type ProposedMainProcessorClientRequest = Omit<ProposedReferrerProcessorRequest, 'requestId'>;

function createRequestId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return `atlas-proposed-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function defaultSendMessage(
    message: ProposedReferrerProcessorRequestMessage,
    callback: (response: unknown) => void,
): void {
    chrome.runtime.sendMessage(message, callback);
}

function numberOrZero(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

function parseProcessorResponse(
    requestId: string,
    referrerUrl: string | null,
    response: unknown,
): ProposedReferrerProcessorResponse {
    if (!response || typeof response !== 'object') {
        return failedResponse(requestId, referrerUrl);
    }

    const row = response as Partial<ProposedReferrerProcessorResponse>;
    if (!row.result || typeof row.result !== 'object') {
        return failedResponse(requestId, referrerUrl);
    }

    const result: ProposedReferrerFileState = {
        ...emptyProposedReferrerFileState(referrerUrl),
        ...row.result,
    };
    const results = Array.isArray(row.results)
        ? row.results.map((entry) => ({
            ...emptyProposedReferrerFileState(referrerUrl),
            ...entry,
        }))
        : [result];

    return {
        requestId: typeof row.requestId === 'string' && row.requestId.trim() !== '' ? row.requestId : requestId,
        ok: row.ok === true,
        status: numberOrZero(row.status),
        result,
        results,
        error: typeof row.error === 'string' ? row.error : null,
    };
}

function failedResponse(
    requestId: string,
    referrerUrl: string | null,
    error: string | null = null,
): ProposedReferrerProcessorResponse {
    const result = emptyProposedReferrerFileState(referrerUrl);

    return {
        requestId,
        ok: false,
        status: 0,
        result,
        results: [result],
        error,
    };
}

export function createProposedMainProcessorClient(options: ProposedMainProcessorClientOptions = {}) {
    const sendMessage = options.sendMessage ?? defaultSendMessage;
    const requestIdFactory = options.createRequestId ?? createRequestId;

    return {
        executeReferrerReaction(request: ProposedMainProcessorClientRequest): Promise<ProposedReferrerProcessorResponse> {
            const requestId = requestIdFactory();
            const message: ProposedReferrerProcessorRequestMessage = {
                type: PROPOSED_REFERRER_REACTION_REQUEST,
                requestId,
                ...request,
            };

            return new Promise((resolve) => {
                try {
                    sendMessage(message, (response) => {
                        resolve(parseProcessorResponse(requestId, request.referrerUrl, response));
                    });
                } catch (error) {
                    resolve(failedResponse(
                        requestId,
                        request.referrerUrl,
                        error instanceof Error ? error.message : 'Runtime bridge unavailable.',
                    ));
                }
            });
        },
    };
}
