import { documentWasDiscarded } from './restored-page-badge-check';

type StartPageWork = (options: { fullScan?: boolean; bypassBadgeCheckCache?: boolean }) => void;
type DestroyPageWork = () => void;

export function isPageVisible(): boolean {
    return document.visibilityState !== 'hidden';
}

export function installPageVisibilityLifecycle(startPageWork: StartPageWork, destroyPageWork: DestroyPageWork): void {
    document.addEventListener('visibilitychange', () => {
        if (isPageVisible()) {
            startPageWork({ fullScan: false });
        }
    });

    window.addEventListener('pagehide', (event: PageTransitionEvent) => {
        if (!event.persisted) {
            destroyPageWork();
        }
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        if (isPageVisible() && (event.persisted || documentWasDiscarded())) {
            startPageWork({ fullScan: false, bypassBadgeCheckCache: true });
        }
    });
}
