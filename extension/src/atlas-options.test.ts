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

        await setCloseTabAfterQueuePreferenceForHostname('WWW.Example.com', 'queued');

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'www.example.com': 'queued',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('www.example.com')).resolves.toBe('queued');
    });

    it('removes stored preference when disabling domain option', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueuePreferenceForHostname, setCloseTabAfterQueuePreferenceForHostname } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            'example.com': 'queued',
            'other.example': 'completed',
        };

        await setCloseTabAfterQueuePreferenceForHostname('example.com', 'off');

        expect(storageState[STORAGE_KEYS.closeTabAfterQueueByDomain]).toEqual({
            'other.example': 'completed',
        });
        await expect(getCloseTabAfterQueuePreferenceForHostname('example.com')).resolves.toBe('off');
    });

    it('sanitizes stored per-domain map to valid host keys and supported mode values', async () => {
        const { STORAGE_KEYS, getCloseTabAfterQueueByDomain } = await import('./atlas-options');

        storageState[STORAGE_KEYS.closeTabAfterQueueByDomain] = {
            '': true,
            'https://sub.example.com/path': true,
            '.valid.example.': 'completed',
            'ignored.example': 'yes',
            'disabled.example': false,
        };

        await expect(getCloseTabAfterQueueByDomain()).resolves.toEqual({
            'sub.example.com': 'queued',
            'valid.example': 'completed',
        });
    });

    it('stores and reads the react-all-items preference per hostname', async () => {
        const {
            STORAGE_KEYS,
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');

        await setReactAllItemsInPostPreferenceForHostname('WWW.Example.com', true);

        expect(storageState[STORAGE_KEYS.reactAllItemsInPostByDomain]).toEqual({
            'www.example.com': true,
        });
        await expect(getReactAllItemsInPostPreferenceForHostname('www.example.com')).resolves.toBe(true);
    });

    it('keeps react-all-items disabled for other hostnames when one hostname is enabled', async () => {
        const {
            getReactAllItemsInPostPreferenceForHostname,
            setReactAllItemsInPostPreferenceForHostname,
        } = await import('./atlas-options');

        await setReactAllItemsInPostPreferenceForHostname('deviantart.com', true);

        await expect(getReactAllItemsInPostPreferenceForHostname('deviantart.com')).resolves.toBe(true);
        await expect(getReactAllItemsInPostPreferenceForHostname('x.com')).resolves.toBe(false);
    });

    it('stores and reads referrer query params to strip by domain', async () => {
        const { STORAGE_KEYS, getStoredOptions, saveStoredOptions } = await import('./atlas-options');

        await saveStoredOptions('https://atlas.test', 'token', [], {
            'https://www.example.com/path': ['tag', 'tags', 'tag'],
            '.sub.example.com.': ['*', 'Filter'],
            'empty.example.com': [],
        });

        expect(storageState[STORAGE_KEYS.referrerQueryParamsToStripByDomain]).toEqual({
            'www.example.com': ['tag', 'tags'],
            'sub.example.com': ['*'],
        });

        await expect(getStoredOptions()).resolves.toMatchObject({
            referrerQueryParamsToStripByDomain: {
                'www.example.com': ['tag', 'tags'],
                'sub.example.com': ['*'],
            },
        });
    });

    it('sanitizes legacy stored referrer cleanup values', async () => {
        const { STORAGE_KEYS, getStoredOptions } = await import('./atlas-options');

        storageState[STORAGE_KEYS.referrerQueryParamsToStripByDomain] = {
            'https://example.com/path': 'tag, tags',
            'sub.example.com': ['tag', 123, 'filter'],
            'invalid.example': [],
        };

        await expect(getStoredOptions()).resolves.toMatchObject({
            referrerQueryParamsToStripByDomain: {
                'example.com': ['tag', 'tags'],
                'sub.example.com': ['tag', 'filter'],
            },
        });
    });
});
