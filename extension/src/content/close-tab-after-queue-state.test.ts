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
        let persistedEnabled = false;
        let storageChangeListener: ((changes: Record<string, { newValue?: unknown }>, areaName: string) => void) | null = null;

        mockGetCloseTabAfterQueuePreferenceForHostname.mockImplementation(async () => persistedEnabled);
        mockSetCloseTabAfterQueuePreferenceForHostname.mockImplementation(async (_hostname: string, enabled: boolean) => {
            persistedEnabled = enabled;
            storageChangeListener?.({
                closeTabAfterQueueByDomain: {
                    newValue: enabled ? { 'example.com': true } : {},
                },
            }, 'local');
        });

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
        expect(first.enabled.value).toBe(false);
        expect(second.enabled.value).toBe(false);

        await first.toggle();
        await flushPromises();

        expect(first.enabled.value).toBe(true);
        expect(second.enabled.value).toBe(true);

        persistedEnabled = false;
        storageChangeListener?.({
            closeTabAfterQueueByDomain: {
                newValue: {},
            },
        }, 'local');
        await flushPromises();

        expect(first.enabled.value).toBe(false);
        expect(second.enabled.value).toBe(false);
    });
});
