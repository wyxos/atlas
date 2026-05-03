import type { Router } from 'vue-router';

const staleAssetReloadStorageKey = 'atlas:stale-asset-reload-at';
const staleAssetReloadCooldownMs = 10_000;

const staleAssetErrorPatterns = [
    'failed to fetch dynamically imported module',
    'importing a module script failed',
    'error loading dynamically imported module',
    'expected a javascript-or-wasm module script',
    'strict mime type checking is enforced for module scripts',
];

let inMemoryLastReloadAt = 0;

type StaleAssetReloadOptions = {
    now?: () => number;
    reload?: () => void;
    storage?: Storage;
};

export function isStaleAssetLoadError(error: unknown): boolean {
    if (typeof error === 'string') {
        return staleAssetErrorPatterns.some((pattern) => error.toLowerCase().includes(pattern));
    }

    if (error instanceof Error) {
        return isStaleAssetLoadError(error.message);
    }

    if (!error || typeof error !== 'object') {
        return false;
    }

    const nestedError = error as {
        error?: unknown;
        message?: unknown;
        payload?: unknown;
        reason?: unknown;
    };

    return [nestedError.error, nestedError.message, nestedError.payload, nestedError.reason].some(isStaleAssetLoadError);
}

export function shouldReloadForStaleAsset(options: Pick<StaleAssetReloadOptions, 'now' | 'storage'> = {}): boolean {
    const now = options.now?.() ?? Date.now();

    try {
        const storage = options.storage ?? window.sessionStorage;
        const lastReloadAtValue = storage.getItem(staleAssetReloadStorageKey);
        const lastReloadAt = Number(lastReloadAtValue);

        if (lastReloadAtValue !== null && Number.isFinite(lastReloadAt) && now - lastReloadAt < staleAssetReloadCooldownMs) {
            return false;
        }

        storage.setItem(staleAssetReloadStorageKey, String(now));
        inMemoryLastReloadAt = now;

        return true;
    } catch {
        if (now - inMemoryLastReloadAt < staleAssetReloadCooldownMs) {
            return false;
        }

        inMemoryLastReloadAt = now;

        return true;
    }
}

export function reloadForStaleAsset(options: StaleAssetReloadOptions = {}): void {
    if (!shouldReloadForStaleAsset(options)) {
        return;
    }

    (options.reload ?? (() => window.location.reload()))();
}

export function registerStaleAssetReload(
    router?: Pick<Router, 'onError'>,
    options: StaleAssetReloadOptions = {},
): () => void {
    const reload = (): void => reloadForStaleAsset(options);

    const handleVitePreloadError = (event: Event): void => {
        event.preventDefault();
        reload();
    };

    const handleUnhandledRejection = (event: PromiseRejectionEvent): void => {
        if (!isStaleAssetLoadError(event.reason)) {
            return;
        }

        event.preventDefault();
        reload();
    };

    const handleWindowError = (event: ErrorEvent): void => {
        if (!isStaleAssetLoadError(event.error ?? event.message)) {
            return;
        }

        event.preventDefault();
        reload();
    };

    window.addEventListener('vite:preloadError', handleVitePreloadError);
    window.addEventListener('unhandledrejection', handleUnhandledRejection);
    window.addEventListener('error', handleWindowError);

    const removeRouterHandler = router?.onError?.((error) => {
        if (isStaleAssetLoadError(error)) {
            reload();
        }
    });

    return () => {
        window.removeEventListener('vite:preloadError', handleVitePreloadError);
        window.removeEventListener('unhandledrejection', handleUnhandledRejection);
        window.removeEventListener('error', handleWindowError);
        removeRouterHandler?.();
    };
}
