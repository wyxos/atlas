import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetStoredOptions = vi.fn();
const mockConnectReverb = vi.fn();

vi.mock('../atlas-options', () => ({
    getStoredOptions: mockGetStoredOptions,
}));

vi.mock('../reverb-client', () => ({
    connectReverb: mockConnectReverb,
}));

describe('download-progress-bus', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('reuses a single reverb connection for multiple subscribers and tears down once', async () => {
        const unsubscribe = vi.fn();
        const disconnect = vi.fn();
        let eventHandler: ((event: 'DownloadTransferCreated' | 'DownloadTransferQueued' | 'DownloadTransferProgressUpdated', payload: Record<string, unknown>) => void) | null = null;

        mockGetStoredOptions.mockResolvedValue({
            atlasDomain: 'https://atlas-v2.test',
            apiToken: 'atlas-token',
        });

        (fetch as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: true,
            json: async () => ({
                reverb: {
                    enabled: true,
                    key: 'k',
                    host: 'h',
                    port: 443,
                    scheme: 'https',
                    channel: 'extension-downloads.test',
                },
            }),
        });

        mockConnectReverb.mockResolvedValue({
            onEvent: (handler: typeof eventHandler) => {
                eventHandler = handler;
                return { unsubscribe };
            },
            disconnect,
        });

        const bus = await import('./download-progress-bus');

        const listenerOne = vi.fn();
        const listenerTwo = vi.fn();

        const offOne = bus.subscribeToDownloadProgress(listenerOne);
        const offTwo = bus.subscribeToDownloadProgress(listenerTwo);

        await vi.waitFor(() => {
            expect(mockConnectReverb).toHaveBeenCalledTimes(1);
        });
        expect(eventHandler).not.toBeNull();

        eventHandler?.('DownloadTransferQueued', {
            file_id: 12,
            downloadTransferId: 25,
            status: 'queued',
            percent: 10,
        });

        expect(listenerOne).toHaveBeenCalledWith({
            event: 'DownloadTransferQueued',
            fileId: 12,
            transferId: 25,
            status: 'queued',
            percent: 10,
        });
        expect(listenerTwo).toHaveBeenCalledWith({
            event: 'DownloadTransferQueued',
            fileId: 12,
            transferId: 25,
            status: 'queued',
            percent: 10,
        });

        offOne();
        expect(unsubscribe).not.toHaveBeenCalled();
        expect(disconnect).not.toHaveBeenCalled();

        offTwo();
        expect(unsubscribe).toHaveBeenCalledTimes(1);
        expect(disconnect).toHaveBeenCalledTimes(1);
    });
});
