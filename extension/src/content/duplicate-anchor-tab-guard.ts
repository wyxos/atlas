import { normalizeComparableOpenTabUrl, normalizeComparableOpenTabUrls } from '../open-tab-url';
import { shouldExcludeAnchorHref } from './media-utils';
import { createDuplicateAnchorTabDialog } from './duplicate-anchor-tab-dialog';

type DuplicateAnchorTabGuard = {
    destroy: () => void;
    handleTabPresenceChanged: (payload: unknown) => void;
    refreshSnapshot: () => Promise<void>;
};

type OpenComparableUrlCountsResponse = {
    counts?: unknown;
};

type TabPresenceChangedPayload = {
    urls?: unknown;
    counts?: unknown;
};

function buildComparableUrlCounts(values: unknown): Map<string, number> {
    if (typeof values !== 'object' || values === null) {
        return new Map<string, number>();
    }

    const counts = new Map<string, number>();
    for (const [key, rawCount] of Object.entries(values)) {
        const comparableUrl = normalizeComparableOpenTabUrl(key);
        if (comparableUrl === null || typeof rawCount !== 'number' || !Number.isFinite(rawCount)) {
            continue;
        }

        const count = Math.max(0, Math.floor(rawCount));
        if (count === 0) {
            continue;
        }

        counts.set(comparableUrl, count);
    }

    return counts;
}

function resolveAnchorFromEventTarget(target: EventTarget | null): HTMLAnchorElement | null {
    if (!(target instanceof Element)) {
        return null;
    }

    return target.closest('a[href]');
}

export function createDuplicateAnchorTabGuard(): DuplicateAnchorTabGuard {
    const dialog = createDuplicateAnchorTabDialog();
    let openComparableUrlCounts = new Map<string, number>();
    let snapshotReady = false;
    let refreshInFlight: Promise<void> | null = null;
    let destroyed = false;

    function getCurrentTabComparableUrl(): string | null {
        return normalizeComparableOpenTabUrl(window.location.href);
    }

    function shouldBlockComparableUrl(comparableUrl: string): boolean {
        const openCount = openComparableUrlCounts.get(comparableUrl) ?? 0;
        if (openCount === 0) {
            return false;
        }

        const currentTabComparableUrl = getCurrentTabComparableUrl();
        if (currentTabComparableUrl === comparableUrl) {
            return openCount > 1;
        }

        return true;
    }

    function refreshSnapshot(): Promise<void> {
        if (destroyed) {
            return Promise.resolve();
        }

        if (refreshInFlight !== null) {
            return refreshInFlight;
        }

        refreshInFlight = new Promise<void>((resolve) => {
            chrome.runtime.sendMessage(
                { type: 'ATLAS_GET_OPEN_COMPARABLE_URL_COUNTS' },
                (response: unknown) => {
                    if (destroyed) {
                        resolve();
                        return;
                    }

                    const counts = typeof response === 'object' && response !== null
                        ? (response as OpenComparableUrlCountsResponse).counts
                        : {};
                    openComparableUrlCounts = buildComparableUrlCounts(counts);
                    snapshotReady = true;
                    resolve();
                },
            );
        }).finally(() => {
            refreshInFlight = null;
        });

        return refreshInFlight;
    }

    function maybeBlockDuplicateMiddleClick(event: MouseEvent): void {
        if (event.button !== 1) {
            return;
        }

        const anchor = resolveAnchorFromEventTarget(event.target);
        if (anchor === null) {
            return;
        }

        const rawHref = anchor.getAttribute('href');
        const absoluteHref = anchor.href;
        if (shouldExcludeAnchorHref(rawHref, absoluteHref)) {
            return;
        }

        const comparableUrl = normalizeComparableOpenTabUrl(absoluteHref);
        if (comparableUrl === null || !snapshotReady || !shouldBlockComparableUrl(comparableUrl)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        dialog.show(absoluteHref);
    }

    const handleMouseDown = (event: MouseEvent): void => {
        maybeBlockDuplicateMiddleClick(event);
    };

    const handleAuxClick = (event: MouseEvent): void => {
        maybeBlockDuplicateMiddleClick(event);
    };

    document.addEventListener('mousedown', handleMouseDown, true);
    document.addEventListener('auxclick', handleAuxClick, true);
    void refreshSnapshot();

    return {
        destroy: () => {
            if (destroyed) {
                return;
            }

            destroyed = true;
            document.removeEventListener('mousedown', handleMouseDown, true);
            document.removeEventListener('auxclick', handleAuxClick, true);
            dialog.destroy();
        },
        handleTabPresenceChanged: (payload: unknown) => {
            if (!snapshotReady || typeof payload !== 'object' || payload === null) {
                return;
            }

            const { urls, counts } = payload as TabPresenceChangedPayload;
            const changedUrls = normalizeComparableOpenTabUrls(urls);
            if (changedUrls.length === 0) {
                return;
            }

            const nextCounts = buildComparableUrlCounts(counts);
            for (const url of changedUrls) {
                const nextCount = nextCounts.get(url) ?? 0;
                if (nextCount === 0) {
                    openComparableUrlCounts.delete(url);
                    continue;
                }

                openComparableUrlCounts.set(url, nextCount);
            }
        },
        refreshSnapshot,
    };
}
