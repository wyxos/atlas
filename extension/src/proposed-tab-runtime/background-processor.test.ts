import { describe, expect, it, vi } from 'vitest';
import {
    createProposedBackgroundProcessor,
    createProposedImmediateReferrerReactionExecutor,
} from './background-processor';
import { PROPOSED_REFERRER_REACTION_REQUEST } from './types';

describe('createProposedBackgroundProcessor', () => {
    it('executes each request immediately without caching visible tab state', async () => {
        const executeReferrerReaction = vi.fn(async (request) => ({
            requestId: request.requestId,
            ok: true,
            status: 200,
            result: {
                exists: true,
                reaction: 'like',
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: null,
                referrerUrl: request.referrerUrl,
                sourceUrl: null,
                fileId: 123,
                transferId: null,
                status: null,
                percent: null,
            },
        }));
        const processor = createProposedBackgroundProcessor({ executeReferrerReaction });
        const responses: unknown[] = [];
        const message = {
            type: PROPOSED_REFERRER_REACTION_REQUEST,
            requestId: 'request-1',
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
        };

        expect(processor.handleRuntimeMessage(message, { tab: { id: 7 } }, (response) => responses.push(response))).toBe(true);
        expect(processor.handleRuntimeMessage({ ...message, requestId: 'request-2' }, { tab: { id: 7 } }, (response) => responses.push(response))).toBe(true);
        await Promise.resolve();

        expect(executeReferrerReaction).toHaveBeenCalledTimes(2);
        expect(responses).toEqual([
            expect.objectContaining({ requestId: 'request-1', ok: true }),
            expect.objectContaining({ requestId: 'request-2', ok: true }),
        ]);
        expect(processor.getDebugState()).toEqual({
            ownedTabStateCount: 0,
            pendingVisibleStateKeys: [],
        });
    });

    it('ignores unrelated runtime messages', () => {
        const executeReferrerReaction = vi.fn();
        const processor = createProposedBackgroundProcessor({ executeReferrerReaction });

        expect(processor.handleRuntimeMessage({ type: 'ATLAS_QUEUE_REFERRER_CHECK' }, {}, vi.fn())).toBe(false);
        expect(executeReferrerReaction).not.toHaveBeenCalled();
    });

    it('provides a stateless batched executor for one referrer lifecycle request', async () => {
        const fetcher = vi.fn(async () => new Response(JSON.stringify({
            matches: [
                {
                    request_id: 'request-1:0',
                    exists: true,
                    file_id: 123,
                    reaction: 'love',
                    reacted_at: '2026-06-23T10:00:00.000Z',
                    downloaded_at: null,
                    blacklisted_at: null,
                },
                {
                    request_id: 'request-1:1',
                    exists: false,
                    file_id: null,
                    reaction: null,
                    reacted_at: null,
                    downloaded_at: null,
                    blacklisted_at: null,
                },
            ],
        }), { status: 200 }));
        const executor = createProposedImmediateReferrerReactionExecutor({ fetcher });

        const response = await executor.executeReferrerReaction({
            requestId: 'request-1',
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            targets: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    pageUrl: 'https://civitai.com/feed',
                    sourceUrl: 'https://image.civitai.com/one.jpeg',
                },
                {
                    referrerUrl: 'https://civitai.com/images/2',
                    pageUrl: 'https://civitai.com/feed',
                    sourceUrl: 'https://image.civitai.com/two.jpeg',
                },
            ],
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
        });

        expect(fetcher).toHaveBeenCalledTimes(1);
        expect(fetcher).toHaveBeenCalledWith('https://atlas.test/api/extension/referrer-checks', expect.objectContaining({
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Atlas-Api-Key': 'test-token',
            },
            body: expect.any(String),
        }));
        const requestBody = JSON.parse(fetcher.mock.calls[0]?.[1]?.body as string) as { items: Array<Record<string, unknown>> };
        expect(requestBody.items).toHaveLength(2);
        expect(requestBody.items[0]).toMatchObject({
            request_id: 'request-1:0',
            page_url: 'https://civitai.com/feed',
            referrer_url: 'https://civitai.com/images/1',
            source_url: 'https://image.civitai.com/one.jpeg',
        });
        expect(requestBody.items[1]).toMatchObject({
            request_id: 'request-1:1',
            page_url: 'https://civitai.com/feed',
            referrer_url: 'https://civitai.com/images/2',
            source_url: 'https://image.civitai.com/two.jpeg',
        });
        expect(requestBody.items[0]?.referrer_hash).toMatch(/^[a-f0-9]{64}$/);
        expect(response).toMatchObject({
            requestId: 'request-1',
            ok: true,
            status: 200,
            result: {
                exists: true,
                reaction: 'love',
                reactedAt: '2026-06-23T10:00:00.000Z',
                referrerUrl: 'https://civitai.com/images/1',
                fileId: 123,
            },
            results: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    reaction: 'love',
                    fileId: 123,
                },
                {
                    referrerUrl: 'https://civitai.com/images/2',
                    reaction: null,
                    fileId: null,
                },
            ],
        });
    });
});
