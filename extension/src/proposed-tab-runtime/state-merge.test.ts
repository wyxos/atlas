import { describe, expect, it } from 'vitest';
import {
    createInitialProposedTabState,
    mergeProcessorResultIntoTabState,
    mergeReverbEventIntoTabState,
} from './state-merge';

describe('proposed tab state merge', () => {
    it('merges the initial processor result into local tab state', () => {
        const state = createInitialProposedTabState({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            createdAt: 1000,
        });

        const next = mergeProcessorResultIntoTabState(state, {
            requestId: 'request-1',
            ok: true,
            status: 200,
            result: {
                exists: true,
                reaction: 'love',
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
            results: [
                {
                    exists: true,
                    reaction: 'love',
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
                {
                    exists: false,
                    reaction: null,
                    reactedAt: null,
                    downloadedAt: null,
                    blacklistedAt: null,
                    referrerUrl: 'https://civitai.com/images/2',
                    sourceUrl: null,
                    fileId: null,
                    transferId: null,
                    status: null,
                    percent: null,
                },
            ],
        }, 2000);

        expect(next).toMatchObject({
            phase: 'ready',
            lifecycleRunCount: 1,
            referrerResult: {
                exists: true,
                reaction: 'love',
                sourceUrl: 'https://image.civitai.com/example.jpeg',
            },
            referrerResultsByUrl: {
                'https://civitai.com/images/1': {
                    reaction: 'love',
                },
                'https://civitai.com/images/2': {
                    exists: false,
                },
            },
        });
    });

    it('ignores Reverb events that are unrelated to the tab referrer or source file', () => {
        const state = mergeProcessorResultIntoTabState(createInitialProposedTabState({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            createdAt: 1000,
        }), {
            requestId: 'request-1',
            ok: true,
            status: 200,
            result: {
                exists: true,
                reaction: 'like',
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
        }, 2000);

        const next = mergeReverbEventIntoTabState(state, {
            eventName: 'DownloadTransferProgressUpdated',
            referrerUrl: 'https://civitai.com/images/2',
            sourceUrl: 'https://image.civitai.com/other.jpeg',
            reaction: 'funny',
            reactedAt: null,
            downloadedAt: '2026-06-23T11:00:00.000Z',
            blacklistedAt: null,
            fileId: 999,
            transferId: 888,
            status: 'completed',
            percent: 100,
            payload: {},
        }, 3000);

        expect(next).toBe(state);
    });

    it('merges relevant Reverb events into the tab-local result without starting a new lifecycle', () => {
        const state = mergeProcessorResultIntoTabState(createInitialProposedTabState({
            instanceId: 'tab-7:document-1',
            documentId: 'document-1',
            pageUrl: 'https://civitai.com/images/1',
            referrerUrl: 'https://civitai.com/images/1',
            createdAt: 1000,
        }), {
            requestId: 'request-1',
            ok: true,
            status: 200,
            result: {
                exists: true,
                reaction: 'like',
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
        }, 2000);

        const next = mergeReverbEventIntoTabState(state, {
            eventName: 'DownloadTransferProgressUpdated',
            referrerUrl: 'https://civitai.com/images/1',
            sourceUrl: 'https://image.civitai.com/example.jpeg',
            reaction: 'funny',
            reactedAt: '2026-06-23T11:00:00.000Z',
            downloadedAt: '2026-06-23T11:05:00.000Z',
            blacklistedAt: null,
            fileId: 123,
            transferId: 888,
            status: 'completed',
            percent: 100,
            payload: {},
        }, 3000);

        expect(next).toMatchObject({
            lifecycleRunCount: 1,
            referrerResult: {
                exists: true,
                reaction: 'funny',
                downloadedAt: '2026-06-23T11:05:00.000Z',
                transferId: 888,
                percent: 100,
            },
        });
    });
});
