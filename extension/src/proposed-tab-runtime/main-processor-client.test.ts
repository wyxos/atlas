import { describe, expect, it, vi } from 'vitest';
import { createProposedMainProcessorClient } from './main-processor-client';
import { PROPOSED_REFERRER_REACTION_REQUEST } from './types';

describe('createProposedMainProcessorClient', () => {
    it('sends a typed referrer lifecycle request to the background processor', async () => {
        const sendMessage = vi.fn((message: unknown, callback: (response: unknown) => void) => {
            callback({
                requestId: 'request-1',
                ok: true,
                status: 200,
                result: {
                    exists: false,
                    reaction: null,
                    reactedAt: null,
                    downloadedAt: null,
                    blacklistedAt: null,
                    referrerUrl: 'https://civitai.com/images/1',
                    sourceUrl: null,
                    fileId: null,
                    transferId: null,
                    status: null,
                    percent: null,
                },
            });
        });
        const client = createProposedMainProcessorClient({
            sendMessage,
            createRequestId: () => 'request-1',
        });

        const response = await client.executeReferrerReaction({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            targets: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    pageUrl: 'https://civitai.com/images/1',
                    sourceUrl: null,
                },
            ],
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
        });

        expect(sendMessage).toHaveBeenCalledWith({
            type: PROPOSED_REFERRER_REACTION_REQUEST,
            requestId: 'request-1',
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            targets: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    pageUrl: 'https://civitai.com/images/1',
                    sourceUrl: null,
                },
            ],
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
        }, expect.any(Function));
        expect(response).toMatchObject({
            requestId: 'request-1',
            ok: true,
            status: 200,
            result: {
                exists: false,
                referrerUrl: 'https://civitai.com/images/1',
            },
        });
    });

    it('returns a failed processor response when the runtime bridge is unavailable', async () => {
        const client = createProposedMainProcessorClient({
            sendMessage: () => {
                throw new Error('runtime unavailable');
            },
            createRequestId: () => 'request-1',
        });

        await expect(client.executeReferrerReaction({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            targets: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    pageUrl: 'https://civitai.com/images/1',
                    sourceUrl: null,
                },
            ],
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
        })).resolves.toMatchObject({
            requestId: 'request-1',
            ok: false,
            status: 0,
            result: {
                exists: false,
                referrerUrl: 'https://civitai.com/images/1',
            },
        });
    });
});
