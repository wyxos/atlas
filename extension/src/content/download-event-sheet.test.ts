import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockClearAtlasRequestLog = vi.fn();
let requestEntries: Array<Record<string, unknown>> = [];
const requestListeners = new Set<(entries: Array<Record<string, unknown>>) => void>();

vi.mock('./atlas-request-log', () => ({
    clearAtlasRequestLog: () => {
        mockClearAtlasRequestLog();
        requestEntries = [];
        for (const listener of requestListeners) {
            listener(requestEntries);
        }
    },
    getAtlasRequestLogSnapshot: () => requestEntries,
    subscribeToAtlasRequestLog: (listener: (entries: Array<Record<string, unknown>>) => void) => {
        requestListeners.add(listener);
        listener(requestEntries);
        return () => {
            requestListeners.delete(listener);
        };
    },
    __setEntries: (entries: Array<Record<string, unknown>>) => {
        requestEntries = entries;
        for (const listener of requestListeners) {
            listener(requestEntries);
        }
    },
}));

function flushPromises(): Promise<void> {
    return Promise.resolve().then(() => Promise.resolve());
}

describe('download-event-sheet', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        requestEntries = [];
        requestListeners.clear();
        document.documentElement.querySelectorAll('[data-atlas-download-events-sheet="1"]')
            .forEach((element) => element.remove());
        document.body.innerHTML = '';
    });

    it('opens on Alt+A, renders event/request rows, and clears logged requests', async () => {
        const atlasRequestLog = await import('./atlas-request-log') as typeof import('./atlas-request-log') & {
            __setEntries: (entries: Array<Record<string, unknown>>) => void;
        };
        const { createDownloadEventSheet } = await import('./download-event-sheet');
        const sheet = createDownloadEventSheet();

        atlasRequestLog.__setEntries([
            {
                id: 1,
                timestamp: '10:00:00 AM',
                endpoint: '/api/extension/reactions',
                method: 'POST',
                requestPayload: { file_id: 7 },
                responsePayload: { ok: true },
                status: 200,
                durationMs: 15,
            },
        ]);

        sheet.push({
            event: 'DownloadTransferQueued',
            transferId: 41,
            fileId: 7,
            status: 'queued',
            percent: 15,
            payload: {
                file_id: 7,
            },
        });

        document.body.dispatchEvent(new KeyboardEvent('keydown', {
            altKey: true,
            key: 'a',
            bubbles: true,
            cancelable: true,
        }));
        await flushPromises();

        expect(document.documentElement.textContent).toContain('Download Debug Sheet');
        expect(document.documentElement.textContent).toContain('DownloadTransferQueued');
        expect(document.documentElement.textContent).toContain('POST /api/extension/reactions');

        const clearRequestsButton = Array.from(document.querySelectorAll('button'))
            .find((button) => button.textContent === 'Clear Requests');
        expect(clearRequestsButton).toBeTruthy();

        clearRequestsButton?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(mockClearAtlasRequestLog).toHaveBeenCalledTimes(1);
    });
});
