export function documentWasDiscarded(): boolean {
    return (document as Document & { wasDiscarded?: boolean }).wasDiscarded === true;
}

function isBackForwardNavigation(): boolean {
    if (typeof performance.getEntriesByType !== 'function') {
        return false;
    }

    const navigation = performance.getEntriesByType('navigation')[0] as PerformanceNavigationTiming | undefined;

    return navigation?.type === 'back_forward';
}

function requestShouldForceBadgeCheckForCurrentPage(): Promise<boolean> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return Promise.resolve(false);
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage({
                type: 'ATLAS_SHOULD_FORCE_BADGE_CHECK_ON_PAGE',
                url: window.location.href,
            }, (response: unknown) => {
                if (chrome.runtime.lastError || !response || typeof response !== 'object') {
                    resolve(false);
                    return;
                }

                resolve((response as { shouldForce?: unknown }).shouldForce === true);
            });
        } catch {
            resolve(false);
        }
    });
}

export async function shouldBypassBadgeCheckCacheForPageStart(forceByPageLifecycle = false): Promise<boolean> {
    return forceByPageLifecycle
        || documentWasDiscarded()
        || isBackForwardNavigation()
        || await requestShouldForceBadgeCheckForCurrentPage();
}
