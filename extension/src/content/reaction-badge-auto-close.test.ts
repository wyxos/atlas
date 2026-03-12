import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockSubscribeToDownloadProgress = vi.fn();
const mockRequestCloseCurrentTab = vi.fn();

vi.mock('./download-progress-bus', () => ({
    subscribeToDownloadProgress: mockSubscribeToDownloadProgress,
}));

vi.mock('./reaction-badge-tab-runtime', () => ({
    requestCloseCurrentTab: mockRequestCloseCurrentTab,
}));

describe('reaction-badge-auto-close', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
    });

    it('waits for all tracked downloads to complete before closing the tab', async () => {
        const listeners = new Set<(event: {
            fileId: number | null;
            transferId: number | null;
            sourceUrl: string | null;
            referrerUrl: string | null;
            status: string | null;
            percent: number | null;
            reaction: null;
            downloadedAt?: string | null;
            blacklistedAt?: string | null;
            reactedAt?: string | null;
            payload: Record<string, unknown>;
        }) => void>();

        mockSubscribeToDownloadProgress.mockImplementation((listener) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        });

        const { queueCloseCurrentTabAfterDownloadComplete } = await import('./reaction-badge-auto-close');

        queueCloseCurrentTabAfterDownloadComplete([
            {
                fileId: 11,
                transferId: 101,
                status: 'queued',
                downloadedAt: null,
            },
            {
                fileId: 12,
                transferId: 102,
                status: 'queued',
                downloadedAt: null,
            },
        ]);

        expect(mockRequestCloseCurrentTab).not.toHaveBeenCalled();

        for (const listener of listeners) {
            listener({
                fileId: 11,
                transferId: 101,
                sourceUrl: null,
                referrerUrl: null,
                status: 'completed',
                percent: 100,
                reaction: null,
                downloadedAt: '2026-03-12T00:00:00Z',
                blacklistedAt: null,
                reactedAt: null,
                payload: {},
            });
        }

        expect(mockRequestCloseCurrentTab).not.toHaveBeenCalled();

        for (const listener of listeners) {
            listener({
                fileId: 12,
                transferId: 102,
                sourceUrl: null,
                referrerUrl: null,
                status: 'completed',
                percent: 100,
                reaction: null,
                downloadedAt: '2026-03-12T00:00:01Z',
                blacklistedAt: null,
                reactedAt: null,
                payload: {},
            });
        }

        expect(mockRequestCloseCurrentTab).toHaveBeenCalledTimes(1);
    });

    it('does not close the tab when a tracked download fails', async () => {
        const listeners = new Set<(event: {
            fileId: number | null;
            transferId: number | null;
            sourceUrl: string | null;
            referrerUrl: string | null;
            status: string | null;
            percent: number | null;
            reaction: null;
            downloadedAt?: string | null;
            blacklistedAt?: string | null;
            reactedAt?: string | null;
            payload: Record<string, unknown>;
        }) => void>();

        mockSubscribeToDownloadProgress.mockImplementation((listener) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        });

        const { queueCloseCurrentTabAfterDownloadComplete } = await import('./reaction-badge-auto-close');

        queueCloseCurrentTabAfterDownloadComplete([
            {
                fileId: 11,
                transferId: 101,
                status: 'queued',
                downloadedAt: null,
            },
        ]);

        for (const listener of listeners) {
            listener({
                fileId: 11,
                transferId: 101,
                sourceUrl: null,
                referrerUrl: null,
                status: 'failed',
                percent: 17,
                reaction: null,
                downloadedAt: null,
                blacklistedAt: null,
                reactedAt: null,
                payload: {},
            });
        }

        expect(mockRequestCloseCurrentTab).not.toHaveBeenCalled();
    });

    it('closes immediately when every tracked target is already complete', async () => {
        const { queueCloseCurrentTabAfterDownloadComplete } = await import('./reaction-badge-auto-close');

        queueCloseCurrentTabAfterDownloadComplete([
            {
                fileId: 11,
                transferId: 101,
                status: 'completed',
                downloadedAt: '2026-03-12T00:00:00Z',
            },
        ]);

        expect(mockSubscribeToDownloadProgress).not.toHaveBeenCalled();
        expect(mockRequestCloseCurrentTab).toHaveBeenCalledTimes(1);
    });

    it('keeps the tab open when a pending target cannot be tracked yet', async () => {
        const { queueCloseCurrentTabAfterDownloadComplete } = await import('./reaction-badge-auto-close');

        queueCloseCurrentTabAfterDownloadComplete([
            {
                fileId: null,
                transferId: null,
                status: 'queued',
                downloadedAt: null,
            },
        ]);

        expect(mockSubscribeToDownloadProgress).not.toHaveBeenCalled();
        expect(mockRequestCloseCurrentTab).not.toHaveBeenCalled();
    });
});
