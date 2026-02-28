import { fetchExtensionMatches } from './content/atlas-client';
import { renderMatches } from './content/render-overlays';
import { scanMediaCandidates } from './content/scan-media';

const SCAN_LIMIT = 300;
const RESCAN_DELAY_MS = 500;

let rescanTimeoutId: number | null = null;
let isRunning = false;
let rerunRequested = false;

async function runScanAndRender(): Promise<void> {
    if (isRunning) {
        rerunRequested = true;
        return;
    }

    isRunning = true;

    try {
        do {
            rerunRequested = false;

            const candidates = scanMediaCandidates(SCAN_LIMIT);
            const payload = candidates.map((candidate) => candidate.payload);
            const matches = await fetchExtensionMatches(payload);
            renderMatches(candidates, matches);
        } while (rerunRequested);
    } catch {
        // Ignore content-side network/runtime failures; next mutation tick will retry.
    } finally {
        isRunning = false;
    }
}

function scheduleScan(): void {
    if (rescanTimeoutId !== null) {
        window.clearTimeout(rescanTimeoutId);
    }

    rescanTimeoutId = window.setTimeout(() => {
        rescanTimeoutId = null;
        void runScanAndRender();
    }, RESCAN_DELAY_MS);
}

function installMutationObserver(): void {
    const observer = new MutationObserver(() => {
        scheduleScan();
    });

    observer.observe(document.documentElement, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['src', 'srcset', 'poster', 'href'],
    });
}

function bootstrap(): void {
    installMutationObserver();
    void runScanAndRender();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
