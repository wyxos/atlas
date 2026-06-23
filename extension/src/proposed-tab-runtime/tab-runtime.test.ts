import { describe, expect, it, vi } from 'vitest';
import { createProposedTabRuntime, type ProposedReferrerProcessor } from './tab-runtime';
import {
    PROPOSED_TAB_RUNTIME_EVENT_STRATEGY,
    PROPOSED_TAB_RUNTIME_FIRST_CUTOVER_SCOPE,
    type ProposedReferrerProcessorResponse,
} from './types';

function successfulProcessorResponse(overrides: Partial<ProposedReferrerProcessorResponse> = {}): ProposedReferrerProcessorResponse {
    return {
        requestId: 'request-1',
        ok: true,
        status: 200,
        result: {
            exists: true,
            reaction: 'like',
            reactedAt: '2026-06-23T10:00:00.000Z',
            downloadedAt: null,
            blacklistedAt: null,
            referrerUrl: 'https://civitai.com/images/1',
            sourceUrl: 'https://image.civitai.com/example.jpeg',
            fileId: 123,
            transferId: null,
            status: null,
            percent: null,
        },
        ...overrides,
    };
}

function createProcessor(response = successfulProcessorResponse()): ProposedReferrerProcessor {
    return {
        executeReferrerReaction: vi.fn(async () => response),
    };
}

describe('createProposedTabRuntime', () => {
    it('documents the accepted cutover strategy as Reverb-only events for anchor referrer decorations first', () => {
        expect(PROPOSED_TAB_RUNTIME_EVENT_STRATEGY).toBe('reverb-only-raw-event-relay');
        expect(PROPOSED_TAB_RUNTIME_FIRST_CUTOVER_SCOPE).toBe('anchor-referrer-decorations');
    });

    it('performs one referrer lifecycle and treats tab visibility changes as state no-ops', async () => {
        const processor = createProcessor();
        const runtime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor,
            now: () => 1000,
        });

        await runtime.startReferrerLifecycle();
        const stateAfterFirstRun = runtime.getState();

        await runtime.startReferrerLifecycle();
        runtime.handleTabVisibilityChanged('hidden');
        runtime.handleTabVisibilityChanged('visible');

        expect(processor.executeReferrerReaction).toHaveBeenCalledTimes(1);
        expect(processor.executeReferrerReaction).toHaveBeenCalledWith(expect.objectContaining({
            targets: [
                {
                    referrerUrl: 'https://civitai.com/images/1',
                    pageUrl: 'https://civitai.com/images/1',
                    sourceUrl: null,
                },
            ],
        }));
        expect(runtime.getState()).toEqual(stateAfterFirstRun);
        expect(runtime.getState()).toMatchObject({
            phase: 'ready',
            lifecycleRunCount: 1,
            referrerResult: {
                exists: true,
                reaction: 'like',
                fileId: 123,
            },
        });
    });

    it('keeps runtime state isolated per content-script instance', async () => {
        const firstProcessor = createProcessor(successfulProcessorResponse({
            requestId: 'request-a',
            result: {
                ...successfulProcessorResponse().result,
                reaction: 'love',
                referrerUrl: 'https://civitai.com/images/1',
            },
        }));
        const secondProcessor = createProcessor(successfulProcessorResponse({
            requestId: 'request-b',
            result: {
                ...successfulProcessorResponse().result,
                reaction: null,
                referrerUrl: 'https://civitai.com/images/2',
                sourceUrl: 'https://image.civitai.com/other.jpeg',
                fileId: 456,
            },
        }));

        const firstRuntime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: firstProcessor,
            now: () => 1000,
        });
        const secondRuntime = createProposedTabRuntime({
            instanceId: 'tab-8:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/2',
            referrerUrl: 'https://civitai.com/images/2',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: secondProcessor,
            now: () => 1000,
        });

        await firstRuntime.startReferrerLifecycle();
        await secondRuntime.startReferrerLifecycle();

        firstRuntime.handleReverbEvent({
            eventName: 'DownloadTransferProgressUpdated',
            referrerUrl: 'https://civitai.com/images/1',
            sourceUrl: 'https://image.civitai.com/example.jpeg',
            reaction: 'funny',
            reactedAt: '2026-06-23T11:00:00.000Z',
            downloadedAt: '2026-06-23T11:05:00.000Z',
            blacklistedAt: null,
            fileId: 123,
            transferId: 999,
            status: 'completed',
            percent: 100,
            payload: {},
        });

        expect(firstRuntime.getState().referrerResult).toMatchObject({
            reaction: 'funny',
            downloadedAt: '2026-06-23T11:05:00.000Z',
            transferId: 999,
        });
        expect(secondRuntime.getState().referrerResult).toMatchObject({
            reaction: null,
            downloadedAt: null,
            transferId: null,
            fileId: 456,
        });
    });

    it('starts only after DOM readiness and does not bind a tab activation lifecycle', async () => {
        const processor = createProcessor();
        const listeners = new Map<string, EventListener>();
        const documentLike = {
            readyState: 'loading',
            addEventListener: vi.fn((eventName: string, listener: EventListener) => {
                listeners.set(eventName, listener);
            }),
        };
        const runtime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor,
            now: () => 1000,
        });

        runtime.startWhenDomReady(documentLike);
        expect(processor.executeReferrerReaction).not.toHaveBeenCalled();

        listeners.get('DOMContentLoaded')?.(new Event('DOMContentLoaded'));
        await Promise.resolve();

        expect(processor.executeReferrerReaction).toHaveBeenCalledTimes(1);
        expect(documentLike.addEventListener).toHaveBeenCalledWith('DOMContentLoaded', expect.any(Function), { once: true });
        expect(runtime.handleTabActivated).toBeUndefined();
    });

    it('resets state only when the document instance is destroyed and replaced', async () => {
        const firstProcessor = createProcessor();
        const firstRuntime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: firstProcessor,
            now: () => 1000,
        });

        await firstRuntime.startReferrerLifecycle();
        firstRuntime.destroy('navigated');

        const secondProcessor = createProcessor(successfulProcessorResponse({ requestId: 'request-2' }));
        const secondRuntime = createProposedTabRuntime({
            instanceId: 'tab-7:document-2',
            documentId: 'document-2',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            atlasDomain: 'https://atlas.test',
            apiToken: 'test-token',
            processor: secondProcessor,
            now: () => 2000,
        });

        await secondRuntime.startReferrerLifecycle();

        expect(firstRuntime.getState()).toMatchObject({
            phase: 'destroyed',
            destroyReason: 'navigated',
            lifecycleRunCount: 1,
        });
        expect(secondRuntime.getState()).toMatchObject({
            phase: 'ready',
            documentId: 'document-2',
            lifecycleRunCount: 1,
        });
        expect(firstProcessor.executeReferrerReaction).toHaveBeenCalledTimes(1);
        expect(secondProcessor.executeReferrerReaction).toHaveBeenCalledTimes(1);
    });

    it('sends all anchor referrer targets through one tab lifecycle request', async () => {
        const processor = createProcessor(successfulProcessorResponse({
            results: [
                {
                    ...successfulProcessorResponse().result,
                    referrerUrl: 'https://civitai.com/images/1',
                    reaction: 'love',
                },
                {
                    ...successfulProcessorResponse().result,
                    referrerUrl: 'https://civitai.com/images/2',
                    reaction: null,
                    fileId: 456,
                },
            ],
        }));
        const runtime = createProposedTabRuntime({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/feed',
            referrerUrl: 'https://civitai.com/images/1',
            referrerTargets: [
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
            processor,
            now: () => 1000,
        });

        await runtime.startReferrerLifecycle();

        expect(processor.executeReferrerReaction).toHaveBeenCalledTimes(1);
        expect(processor.executeReferrerReaction).toHaveBeenCalledWith(expect.objectContaining({
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
        }));
        expect(runtime.getState().lifecycleRunCount).toBe(1);
        expect(runtime.getState().referrerResultsByUrl).toMatchObject({
            'https://civitai.com/images/1': {
                reaction: 'love',
            },
            'https://civitai.com/images/2': {
                reaction: null,
                fileId: 456,
            },
        });
    });
});
