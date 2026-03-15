import { beforeEach, describe, expect, it, vi } from 'vitest';

type RuntimeMessageListener = (message: unknown, sender?: unknown, sendResponse?: (response?: unknown) => void) => void;

describe('download-progress-bus', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('subscribes once with the background worker and fans out runtime events', async () => {
        let runtimeMessageListener: RuntimeMessageListener | null = null;
        const sendMessage = vi.fn((_: unknown, callback?: (response: unknown) => void) => {
            callback?.({ ok: true });
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage,
                onMessage: {
                    addListener: vi.fn((listener: RuntimeMessageListener) => {
                        runtimeMessageListener = listener;
                    }),
                },
            },
        });

        const bus = await import('./download-progress-bus');

        const listenerOne = vi.fn();
        const listenerTwo = vi.fn();

        const offOne = bus.subscribeToDownloadProgress(listenerOne);
        const offTwo = bus.subscribeToDownloadProgress(listenerTwo);

        expect(sendMessage).toHaveBeenCalledTimes(1);
        expect(sendMessage).toHaveBeenCalledWith(
            { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' },
            expect.any(Function),
        );
        expect(runtimeMessageListener).toBeTypeOf('function');
        const sendResponse = vi.fn();

        runtimeMessageListener?.({
            type: 'ATLAS_DOWNLOAD_PROGRESS_EVENT',
            event: {
                event: 'DownloadTransferQueued',
                fileId: 12,
                transferId: 25,
                sourceUrl: 'https://cdn.example.com/video.mp4#frag',
                referrerUrl: 'https://example.com/page#top',
                status: 'queued',
                percent: 10,
                reaction: 'funny',
                reactedAt: undefined,
                downloadedAt: undefined,
                blacklistedAt: undefined,
                payload: {
                    file_id: 12,
                    downloadTransferId: 25,
                },
            },
        }, undefined, sendResponse);

        expect(listenerOne).toHaveBeenCalledWith({
            event: 'DownloadTransferQueued',
            fileId: 12,
            transferId: 25,
            sourceUrl: 'https://cdn.example.com/video.mp4#frag',
            referrerUrl: 'https://example.com/page#top',
            status: 'queued',
            percent: 10,
            reaction: 'funny',
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {
                file_id: 12,
                downloadTransferId: 25,
            },
        });
        expect(listenerTwo).toHaveBeenCalledWith({
            event: 'DownloadTransferQueued',
            fileId: 12,
            transferId: 25,
            sourceUrl: 'https://cdn.example.com/video.mp4#frag',
            referrerUrl: 'https://example.com/page#top',
            status: 'queued',
            percent: 10,
            reaction: 'funny',
            reactedAt: undefined,
            downloadedAt: undefined,
            blacklistedAt: undefined,
            payload: {
                file_id: 12,
                downloadTransferId: 25,
            },
        });
        expect(sendResponse).toHaveBeenCalledWith({ ok: true });

        offOne();
        expect(sendMessage).toHaveBeenCalledTimes(1);

        offTwo();
        expect(sendMessage).toHaveBeenCalledTimes(2);
        expect(sendMessage).toHaveBeenLastCalledWith(
            { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' },
            expect.any(Function),
        );
    });

    it('reasserts the background subscription while listeners remain active', async () => {
        vi.useFakeTimers();

        const sendMessage = vi.fn((_: unknown, callback?: (response: unknown) => void) => {
            callback?.({ ok: true });
        });

        vi.stubGlobal('chrome', {
            runtime: {
                lastError: null,
                sendMessage,
                onMessage: {
                    addListener: vi.fn(),
                },
            },
        });

        const bus = await import('./download-progress-bus');

        const off = bus.subscribeToDownloadProgress(vi.fn());

        expect(sendMessage).toHaveBeenCalledTimes(1);
        expect(sendMessage).toHaveBeenNthCalledWith(
            1,
            { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' },
            expect.any(Function),
        );

        vi.advanceTimersByTime(15000);

        expect(sendMessage).toHaveBeenCalledTimes(2);
        expect(sendMessage).toHaveBeenNthCalledWith(
            2,
            { type: 'ATLAS_SUBSCRIBE_DOWNLOAD_PROGRESS' },
            expect.any(Function),
        );

        off();

        expect(sendMessage).toHaveBeenCalledTimes(3);
        expect(sendMessage).toHaveBeenLastCalledWith(
            { type: 'ATLAS_UNSUBSCRIBE_DOWNLOAD_PROGRESS' },
            expect.any(Function),
        );
    });
});
