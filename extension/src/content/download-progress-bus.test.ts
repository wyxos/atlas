import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockConnectRuntimeReverb = vi.fn();

vi.mock('../reverb-runtime', () => ({
    connectRuntimeReverb: mockConnectRuntimeReverb,
}));

describe('download-progress-bus', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
    });

    it('reuses a single reverb connection for multiple subscribers and tears down once', async () => {
        const unsubscribe = vi.fn();
        const disconnect = vi.fn();
        let eventHandler: ((event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated', payload: Record<string, unknown>) => void) | null = null;

        mockConnectRuntimeReverb.mockResolvedValue({
            kind: 'connected',
            domain: 'https://atlas.test',
            endpoint: 'https://atlas.test:443',
            client: {
                onEvent: (handler: typeof eventHandler) => {
                    eventHandler = handler;
                    return { unsubscribe };
                },
                disconnect,
            },
        });

        const bus = await import('./download-progress-bus');

        const listenerOne = vi.fn();
        const listenerTwo = vi.fn();

        const offOne = bus.subscribeToDownloadProgress(listenerOne);
        const offTwo = bus.subscribeToDownloadProgress(listenerTwo);

        await vi.waitFor(() => {
            expect(mockConnectRuntimeReverb).toHaveBeenCalledTimes(1);
        });
        expect(eventHandler).not.toBeNull();

        eventHandler?.('DownloadTransferQueued', {
            file_id: 12,
            downloadTransferId: 25,
            original: 'https://cdn.example.com/video.mp4#frag',
            referrer_url: 'https://example.com/page#top',
            status: 'queued',
            percent: 10,
            reaction_type: 'funny',
        });

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
                original: 'https://cdn.example.com/video.mp4#frag',
                referrer_url: 'https://example.com/page#top',
                status: 'queued',
                percent: 10,
                reaction_type: 'funny',
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
                original: 'https://cdn.example.com/video.mp4#frag',
                referrer_url: 'https://example.com/page#top',
                status: 'queued',
                percent: 10,
                reaction_type: 'funny',
            },
        });

        offOne();
        expect(unsubscribe).not.toHaveBeenCalled();
        expect(disconnect).not.toHaveBeenCalled();

        offTwo();
        expect(unsubscribe).toHaveBeenCalledTimes(1);
        expect(disconnect).toHaveBeenCalledTimes(1);
    });
});
