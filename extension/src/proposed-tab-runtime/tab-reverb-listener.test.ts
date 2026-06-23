import { describe, expect, it, vi } from 'vitest';
import { createProposedTabRuntime } from './tab-runtime';
import { createProposedTabReverbListener } from './tab-reverb-listener';

describe('createProposedTabReverbListener', () => {
    it('subscribes per tab and merges relevant Reverb events into that tab runtime', async () => {
        let eventHandler: ((eventName: string, payload: Record<string, unknown>) => void) | null = null;
        const unsubscribe = vi.fn();
        const disconnect = vi.fn();
        const client = {
            onEvent: vi.fn((handler: (eventName: string, payload: Record<string, unknown>) => void) => {
                eventHandler = handler;
                return { unsubscribe };
            }),
            disconnect,
        };
        const runtime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: {
                executeReferrerReaction: vi.fn(async () => ({
                    requestId: 'request-1',
                    ok: true,
                    status: 200,
                    result: {
                        exists: true,
                        reaction: null,
                        reactedAt: null,
                        downloadedAt: null,
                        blacklistedAt: null,
                        referrerUrl: 'https://civitai.com/images/1',
                        sourceUrl: 'https://image.civitai.com/example.jpeg',
                        fileId: 123,
                        transferId: null,
                        status: null,
                        percent: null,
                    },
                })),
            },
            now: () => 1000,
        });
        const listener = createProposedTabReverbListener({
            runtime,
            connect: vi.fn(async () => ({ kind: 'connected', client })),
        });

        await runtime.startReferrerLifecycle();
        await listener.start();
        eventHandler?.('DownloadTransferProgressUpdated', {
            referrer_url: 'https://civitai.com/images/1',
            file_url: 'https://image.civitai.com/example.jpeg',
            reaction: 'love',
            reacted_at: '2026-06-23T12:00:00.000Z',
            downloaded_at: '2026-06-23T12:05:00.000Z',
            file_id: 123,
            id: 777,
            status: 'completed',
            percent: 100,
        });

        expect(runtime.getState().referrerResult).toMatchObject({
            reaction: 'love',
            downloadedAt: '2026-06-23T12:05:00.000Z',
            transferId: 777,
            percent: 100,
        });

        listener.stop();
        expect(unsubscribe).toHaveBeenCalledTimes(1);
        expect(disconnect).toHaveBeenCalledTimes(1);
    });

    it('does not ask the background runtime to own tab-visible Reverb state', async () => {
        const sendMessage = vi.fn();
        const runtime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: {
                executeReferrerReaction: vi.fn(async () => ({
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
                })),
            },
        });
        const listener = createProposedTabReverbListener({
            runtime,
            connect: vi.fn(async () => ({ kind: 'unavailable' })),
            sendMessage,
        });

        await listener.start();

        expect(sendMessage).not.toHaveBeenCalled();
    });
});
