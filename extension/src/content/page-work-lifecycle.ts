import { documentWasDiscarded, shouldBypassBadgeCheckCacheForPageStart } from './restored-page-badge-check';

type StartPageWork = (options: { fullScan?: boolean; bypassBadgeCheckCache?: boolean }) => void;
type StopPageWork = () => void;

export function isPageVisible(): boolean {
    return document.visibilityState !== 'hidden';
}

export function installPageVisibilityLifecycle(startPageWork: StartPageWork, stopPageWork: StopPageWork): void {
    document.addEventListener('visibilitychange', () => {
        if (isPageVisible()) {
            void shouldBypassBadgeCheckCacheForPageStart()
                .then((bypassBadgeCheckCache) => {
                    if (isPageVisible()) {
                        startPageWork({ fullScan: false, bypassBadgeCheckCache });
                    }
                });
            return;
        }

        stopPageWork();
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        if (isPageVisible() && (event.persisted || documentWasDiscarded())) {
            startPageWork({ fullScan: false, bypassBadgeCheckCache: true });
        }
    });
}
