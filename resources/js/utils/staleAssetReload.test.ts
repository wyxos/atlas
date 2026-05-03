import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    isStaleAssetLoadError,
    registerStaleAssetReload,
    reloadForStaleAsset,
    shouldReloadForStaleAsset,
} from './staleAssetReload';

function createStorage(): Storage {
    const items = new Map<string, string>();

    return {
        get length() {
            return items.size;
        },
        clear: vi.fn(() => items.clear()),
        getItem: vi.fn((key: string) => items.get(key) ?? null),
        key: vi.fn((index: number) => Array.from(items.keys())[index] ?? null),
        removeItem: vi.fn((key: string) => items.delete(key)),
        setItem: vi.fn((key: string, value: string) => items.set(key, value)),
    };
}

describe('stale asset reload recovery', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('detects stale Vite chunk load errors', () => {
        expect(isStaleAssetLoadError(new TypeError('Failed to fetch dynamically imported module: https://atlas.wyxos.com/build/assets/BrowseV2.js'))).toBe(true);
        expect(isStaleAssetLoadError('Expected a JavaScript-or-Wasm module script but the server responded with a MIME type of "text/html".')).toBe(true);
        expect(isStaleAssetLoadError({ payload: new Error('Importing a module script failed.') })).toBe(true);
        expect(isStaleAssetLoadError(new Error('Validation failed'))).toBe(false);
    });

    it('throttles reload attempts in session storage', () => {
        const storage = createStorage();

        expect(shouldReloadForStaleAsset({ storage, now: () => 1_000 })).toBe(true);
        expect(shouldReloadForStaleAsset({ storage, now: () => 5_000 })).toBe(false);
        expect(shouldReloadForStaleAsset({ storage, now: () => 12_000 })).toBe(true);
    });

    it('reloads once when a stale asset failure is handled', () => {
        const reload = vi.fn();
        const storage = createStorage();

        reloadForStaleAsset({ storage, reload, now: () => 1_000 });
        reloadForStaleAsset({ storage, reload, now: () => 2_000 });

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it('registers Vite and router stale asset handlers', () => {
        const reload = vi.fn();
        const storage = createStorage();
        const removeRouterHandler = vi.fn();
        const router = {
            onError: vi.fn((handler: (error: unknown) => void) => {
                handler(new TypeError('Failed to fetch dynamically imported module: https://atlas.wyxos.com/build/assets/BrowseV2.js'));

                return removeRouterHandler;
            }),
        };

        const removeHandlers = registerStaleAssetReload(router, { storage, reload, now: () => 1_000 });
        const event = new Event('vite:preloadError', { cancelable: true });

        window.dispatchEvent(event);

        expect(reload).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);

        removeHandlers();

        expect(removeRouterHandler).toHaveBeenCalledTimes(1);
    });
});
