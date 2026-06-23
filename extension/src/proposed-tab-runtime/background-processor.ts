import { createAtlasApiHeaders, createAtlasFetchAuthOptions } from '../atlas-auth';
import {
    emptyProposedReferrerFileState,
    PROPOSED_REFERRER_REACTION_REQUEST,
    type ProposedReferrerLifecycleTarget,
    type ProposedReactionType,
    type ProposedReferrerFileState,
    type ProposedReferrerProcessorRequest,
    type ProposedReferrerProcessorRequestMessage,
    type ProposedReferrerProcessorResponse,
} from './types';

type ProposedBackgroundProcessorExecutor = {
    executeReferrerReaction: (request: ProposedReferrerProcessorRequest) => Promise<ProposedReferrerProcessorResponse>;
};

type ProposedRuntimeMessageSender = {
    tab?: {
        id?: number;
    };
};

type ProposedRuntimeSendResponse = (response?: unknown) => void;

type ProposedBackgroundProcessorOptions = ProposedBackgroundProcessorExecutor;
type ProposedBackgroundFetch = (input: string, init: RequestInit) => Promise<Response>;

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}

function numberOrNull(value: unknown): number | null {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }

    if (typeof value !== 'string') {
        return null;
    }

    const parsed = Number(value.trim());
    return Number.isFinite(parsed) ? parsed : null;
}

function reactionOrNull(value: unknown): ProposedReactionType | null {
    return value === 'love' || value === 'like' || value === 'funny' ? value : null;
}

function normalizeTarget(value: unknown): ProposedReferrerLifecycleTarget | null {
    if (!value || typeof value !== 'object') {
        return null;
    }

    const row = value as Partial<ProposedReferrerLifecycleTarget>;
    const referrerUrl = stringOrNull(row.referrerUrl);
    if (referrerUrl === null) {
        return null;
    }

    return {
        referrerUrl,
        pageUrl: stringOrNull(row.pageUrl),
        sourceUrl: stringOrNull(row.sourceUrl),
    };
}

function parseTargets(payload: Partial<ProposedReferrerProcessorRequestMessage>): ProposedReferrerLifecycleTarget[] {
    const targets = Array.isArray(payload.targets)
        ? payload.targets
            .map(normalizeTarget)
            .filter((target): target is ProposedReferrerLifecycleTarget => target !== null)
        : [];

    if (targets.length > 0) {
        return targets;
    }

    const referrerUrl = stringOrNull(payload.referrerUrl);
    if (referrerUrl === null) {
        return [];
    }

    return [{
        referrerUrl,
        pageUrl: stringOrNull(payload.pageUrl),
        sourceUrl: null,
    }];
}

function uniqueTargets(targets: ProposedReferrerLifecycleTarget[]): ProposedReferrerLifecycleTarget[] {
    const seen = new Set<string>();
    const output: ProposedReferrerLifecycleTarget[] = [];

    for (const target of targets) {
        if (seen.has(target.referrerUrl)) {
            continue;
        }

        seen.add(target.referrerUrl);
        output.push(target);
    }

    return output;
}

async function sha256Hex(value: string): Promise<string> {
    const bytes = new TextEncoder().encode(value);
    const digest = await crypto.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

function parseJsonResponse(response: Response): Promise<unknown> {
    return response.text()
        .then((bodyText) => {
            const trimmed = bodyText.trim();
            if (trimmed === '') {
                return null;
            }

            try {
                return JSON.parse(trimmed) as unknown;
            } catch {
                return bodyText;
            }
        })
        .catch(() => null);
}

function parseRequest(message: unknown): ProposedReferrerProcessorRequest | null {
    if (!message || typeof message !== 'object') {
        return null;
    }

    const payload = message as Partial<ProposedReferrerProcessorRequestMessage>;
    if (payload.type !== PROPOSED_REFERRER_REACTION_REQUEST) {
        return null;
    }

    const requestId = stringOrNull(payload.requestId);
    const instanceId = stringOrNull(payload.instanceId);
    const documentId = stringOrNull(payload.documentId);
    const pageUrl = stringOrNull(payload.pageUrl);
    const atlasDomain = stringOrNull(payload.atlasDomain);
    const apiToken = stringOrNull(payload.apiToken);
    if (
        requestId === null
        || instanceId === null
        || documentId === null
        || pageUrl === null
        || atlasDomain === null
        || apiToken === null
    ) {
        return null;
    }

    return {
        requestId,
        instanceId,
        documentId,
        pageUrl,
        referrerUrl: stringOrNull(payload.referrerUrl),
        targets: parseTargets(payload),
        atlasDomain,
        apiToken,
    };
}

function failedResponse(request: ProposedReferrerProcessorRequest, error: unknown): ProposedReferrerProcessorResponse {
    const result = emptyProposedReferrerFileState(request.referrerUrl);

    return {
        requestId: request.requestId,
        ok: false,
        status: 0,
        result,
        results: [result],
        error: error instanceof Error && error.message.trim() !== '' ? error.message : 'Background processor failed.',
    };
}

function parseReferrerMatchResult(
    request: ProposedReferrerProcessorRequest,
    target: ProposedReferrerLifecycleTarget,
    requestItemId: string,
    payload: unknown,
): ProposedReferrerFileState {
    if (!payload || typeof payload !== 'object') {
        return emptyProposedReferrerFileState(target.referrerUrl);
    }

    const rows = Array.isArray((payload as { matches?: unknown }).matches)
        ? (payload as { matches: unknown[] }).matches
        : [];
    const row = rows.find((candidate) => {
            return candidate !== null
            && typeof candidate === 'object'
            && stringOrNull((candidate as { request_id?: unknown }).request_id) === requestItemId;
    });

    if (!row || typeof row !== 'object') {
        return emptyProposedReferrerFileState(target.referrerUrl);
    }

    const match = row as Record<string, unknown>;
    return {
        exists: match.exists === true,
        reaction: reactionOrNull(match.reaction),
        reactedAt: stringOrNull(match.reacted_at ?? match.reactedAt),
        downloadedAt: stringOrNull(match.downloaded_at ?? match.downloadedAt),
        blacklistedAt: stringOrNull(match.blacklisted_at ?? match.blacklistedAt),
        referrerUrl: target.referrerUrl,
        sourceUrl: stringOrNull(match.source_url ?? match.sourceUrl ?? match.file_url ?? match.fileUrl) ?? target.sourceUrl,
        fileId: numberOrNull(match.file_id ?? match.fileId),
        transferId: numberOrNull(match.transfer_id ?? match.transferId),
        status: stringOrNull(match.status),
        percent: numberOrNull(match.percent),
    };
}

export function createProposedImmediateReferrerReactionExecutor(
    options: { fetcher?: ProposedBackgroundFetch } = {},
): ProposedBackgroundProcessorExecutor {
    const fetcher = options.fetcher ?? fetch;

    return {
        async executeReferrerReaction(request): Promise<ProposedReferrerProcessorResponse> {
            const targets = uniqueTargets(request.targets);
            if (targets.length === 0) {
                const result = emptyProposedReferrerFileState(request.referrerUrl);

                return {
                    requestId: request.requestId,
                    ok: true,
                    status: 204,
                    result,
                    results: [result],
                };
            }

            try {
                const items = await Promise.all(targets.map(async (target, index) => ({
                    request_id: `${request.requestId}:${index}`,
                    referrer_hash: await sha256Hex(target.referrerUrl),
                    referrer_url: target.referrerUrl,
                    page_url: target.pageUrl ?? request.pageUrl,
                    ...(target.sourceUrl !== null ? { source_url: target.sourceUrl } : {}),
                })));
                const response = await fetcher(`${request.atlasDomain}/api/extension/referrer-checks`, {
                    method: 'POST',
                    headers: createAtlasApiHeaders(request.apiToken, true),
                    ...createAtlasFetchAuthOptions(request.apiToken),
                    body: JSON.stringify({ items }),
                });
                const payload = await parseJsonResponse(response);
                const results = response.ok
                    ? targets.map((target, index) => parseReferrerMatchResult(request, target, `${request.requestId}:${index}`, payload))
                    : targets.map((target) => emptyProposedReferrerFileState(target.referrerUrl));
                const result = results[0] ?? emptyProposedReferrerFileState(request.referrerUrl);

                return {
                    requestId: request.requestId,
                    ok: response.ok,
                    status: response.status,
                    result,
                    results,
                };
            } catch (error) {
                return failedResponse(request, error);
            }
        },
    };
}

export function createProposedBackgroundProcessor(options: ProposedBackgroundProcessorOptions) {
    return {
        handleRuntimeMessage(
            message: unknown,
            _sender: ProposedRuntimeMessageSender,
            sendResponse: ProposedRuntimeSendResponse,
        ): boolean {
            const request = parseRequest(message);
            if (request === null) {
                return false;
            }

            void options.executeReferrerReaction(request)
                .then((response) => {
                    sendResponse(response);
                })
                .catch((error: unknown) => {
                    sendResponse(failedResponse(request, error));
                });

            return true;
        },
        getDebugState: () => ({
            ownedTabStateCount: 0,
            pendingVisibleStateKeys: [] as string[],
        }),
    };
}
