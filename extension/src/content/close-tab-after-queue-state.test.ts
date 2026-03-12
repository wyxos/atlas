import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetCloseTabAfterQueuePreferenceForHostname = vi.fn();
const mockSetCloseTabAfterQueuePreferenceForHostname = vi.fn();

vi.mock('../atlas-options', () => ({
    STORAGE_KEYS: {
        closeTabAfterQueueByDomain: 'closeTabAfterQueueByDomain',
    },
    getCloseTabAfterQueuePreferenceForHostname: mockGetCloseTabAfterQueuePreferenceForHostname,
    setCloseTabAfterQueuePreferenceForHostname: mockSetCloseTabAfterQueuePreferenceForHostname,
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('close-tab-after-queue-state', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('shares the same hostname preference across callers and refreshes on storage changes', async () => {
        let persistedMode: 'off' | 'queued' | 'completed' = 'off';
        let storageChangeListener: ((changes: Record<string, { newValue?: unknown }>, areaName: string) => void) | null = null;

        mockGetCloseTabAfterQueuePreferenceForHostname.mockImplementation(async () => persistedMode);
        mockSetCloseTabAfterQueuePreferenceForHostname.mockImplementation(
            async (_hostname: string, mode: 'off' | 'queued' | 'completed') => {
                persistedMode = mode;
                storageChangeListener?.({
                    closeTabAfterQueueByDomain: {
                        newValue: mode === 'off' ? {} : { 'example.com': mode },
                    },
                }, 'local');
            },
        );

        vi.stubGlobal('chrome', {
            storage: {
                onChanged: {
                    addListener: vi.fn((listener: (changes: Record<string, { newValue?: unknown }>, areaName: string) => void) => {
                        storageChangeListener = listener;
                    }),
                },
            },
        });

        const { useCloseTabAfterQueuePreference } = await import('./close-tab-after-queue-state');

        const first = useCloseTabAfterQueuePreference('example.com');
        const second = useCloseTabAfterQueuePreference('example.com');

        await flushPromises();

        expect(mockGetCloseTabAfterQueuePreferenceForHostname).toHaveBeenCalledTimes(1);
        expect(first.mode.value).toBe('off');
        expect(second.mode.value).toBe('off');

        await first.cycleMode();
        await flushPromises();

        expect(first.mode.value).toBe('queued');
        expect(second.mode.value).toBe('queued');

        persistedMode = 'completed';
        storageChangeListener?.({
            closeTabAfterQueueByDomain: {
                newValue: { 'example.com': 'completed' },
            },
        }, 'local');
        await flushPromises();

        expect(first.mode.value).toBe('completed');
        expect(second.mode.value).toBe('completed');
    });
});
