import { beforeEach, describe, expect, it, vi } from 'vitest';

const storageState: Record<string, unknown> = {};

function installChromeStorageMock(): void {
    const runtime = {
        lastError: null as { message: string } | null,
    };

    const local = {
        get: (
            keys: string[] | string,
            callback: (items: Record<string, unknown>) => void,
        ) => {
            runtime.lastError = null;

            const requestedKeys = Array.isArray(keys) ? keys : [keys];
            const result: Record<string, unknown> = {};
            for (const key of requestedKeys) {
                result[key] = storageState[key];
            }

            callback(result);
        },
        set: (
            items: Record<string, unknown>,
            callback: () => void,
        ) => {
            runtime.lastError = null;
            Object.assign(storageState, items);
            callback();
        },
    };

    vi.stubGlobal('chrome', {
        runtime,
        storage: {
            local,
        },
    });
}

describe('atlas-options close-tab-after-queue preferences', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        vi.unstubAllGlobals();

        for (const key of Object.keys(storageState)) {
            delete storageState[key];
        }

        installChromeStorageMock();
    });

    it('stores and reads enabled preference per hostname', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueuePreferenceForHostname, setCloseTabAfterQueuePreferenceForHostname } = await import('./atlas-options');

        await setCloseTabAfterQueuePreferenceForHostname('WWW.Example.com', true);

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'www.example.com': true,
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('www.example.com')).resolves.toBe(true);
    });

    it('removes stored preference when disabling domain option', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueuePreferenceForHostname, setCloseTabAfterQueuePreferenceForHostname } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            'example.com': true,
            'other.example': true,
        };

        await setCloseTabAfterQueuePreferenceForHostname('example.com', false);

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'other.example': true,
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('example.com')).resolves.toBe(false);
    });

    it('sanitizes stored per-domain map to valid host keys and boolean values', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueueByDomain } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            '': true,
            'https://sub.example.com/path': true,
            '.valid.example.': true,
            'ignored.example': 'yes',
            'disabled.example': false,
        };

        await expect(getCloseTabAfterQueueByDomain()).resolves.toEqual({
            'sub.example.com': true,
            'valid.example': true,
            'disabled.example': false,
        });
    });

    it('stores and reads the global react-all-items preference', async () => {
        const { STORAGE_KEYS, getReactAllItemsInPostPreference, setReactAllItemsInPostPreference } = await import('./atlas-options');

        await setReactAllItemsInPostPreference(true);

        expect(storageState[STORAGE_KEYS.reactAllItemsInPostEnabled]).toBe(true);
        await expect(getReactAllItemsInPostPreference()).resolves.toBe(true);
    });

    it('defaults the global react-all-items preference to false', async () => {
        const { STORAGE_KEYS, getReactAllItemsInPostPreference } = await import('./atlas-options');

        storageState[STORAGE_KEYS.reactAllItemsInPostEnabled] = 'yes';

        await expect(getReactAllItemsInPostPreference()).resolves.toBe(false);
    });
});
