import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockGetReactAllItemsInPostPreferenceForHostname = vi.fn();
const mockSetReactAllItemsInPostPreferenceForHostname = vi.fn();

vi.mock('../atlas-options', () => ({
    STORAGE_KEYS: {
        reactAllItemsInPostByDomain: 'reactAllItemsInPostByDomain',
        reactAllItemsInPostEnabled: 'reactAllItemsInPostEnabled',
    },
    getReactAllItemsInPostPreferenceForHostname: mockGetReactAllItemsInPostPreferenceForHostname,
    setReactAllItemsInPostPreferenceForHostname: mockSetReactAllItemsInPostPreferenceForHostname,
}));

function flushPromises(): Promise<void> {
    return new Promise((resolve) => {
        setTimeout(resolve, 0);
    });
}

describe('react-all-items-in-post-state', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('shares the same hostname preference across callers and refreshes on storage changes', async () => {
        let persistedEnabled = false;
        let storageChangeListener: ((changes: Record<string, { newValue?: unknown }>, areaName: string) => void) | null = null;

        mockGetReactAllItemsInPostPreferenceForHostname.mockImplementation(async () => persistedEnabled);
        mockSetReactAllItemsInPostPreferenceForHostname.mockImplementation(async (_hostname: string, enabled: boolean) => {
            persistedEnabled = enabled;
            storageChangeListener?.({
                reactAllItemsInPostByDomain: {
                    newValue: { 'example.com': enabled },
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

        const { useReactAllItemsInPostPreference } = await import('./react-all-items-in-post-state');

        const first = useReactAllItemsInPostPreference('example.com');
        const second = useReactAllItemsInPostPreference('example.com');

        await flushPromises();

        expect(mockGetReactAllItemsInPostPreferenceForHostname).toHaveBeenCalledTimes(1);
        expect(first.enabled.value).toBe(false);
        expect(second.enabled.value).toBe(false);

        await first.toggle();
        await flushPromises();

        expect(first.enabled.value).toBe(true);
        expect(second.enabled.value).toBe(true);

        persistedEnabled = false;
        storageChangeListener?.({
            reactAllItemsInPostByDomain: {
                newValue: { 'example.com': false },
            },
        }, 'local');
        await flushPromises();

        expect(first.enabled.value).toBe(false);
        expect(second.enabled.value).toBe(false);
    });
});
