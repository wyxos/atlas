import { fetchExtensionMatches, loadContentConnectionSettings } from './content/atlas-client';
import { renderMatches } from './content/render-overlays';
import { scanMediaCandidates } from './content/scan-media';

const SCAN_LIMIT = 300;
const RESCAN_DELAY_MS = 500;

let rescanTimeoutId: number | null = null;
let isRunning = false;
let rerunRequested = false;
const processedSignatures = new WeakMap<Element, string>();

function candidateSignature(candidate: { payload: { media_url: string | null; anchor_url: string | null; page_url: string | null } }): string {
    return [candidate.payload.media_url ?? '', candidate.payload.anchor_url ?? '', candidate.payload.page_url ?? ''].join('|');
}

async function runScanAndRender(): Promise<void> {
    if (isRunning) {
        rerunRequested = true;
        return;
    }

    isRunning = true;

    try {
        do {
            rerunRequested = false;

            const settings = await loadContentConnectionSettings();
            if (settings.apiToken === '') {
                continue;
            }

            const candidates = scanMediaCandidates(SCAN_LIMIT, settings.matchRules);
            const unsentCandidates = candidates.filter((candidate) => {
                const signature = candidateSignature(candidate);
                const previousSignature = processedSignatures.get(candidate.element);
                if (previousSignature === signature) {
                    return false;
                }

                processedSignatures.set(candidate.element, signature);
                return true;
            });

            if (unsentCandidates.length === 0) {
                continue;
            }

            const payload = unsentCandidates.map((candidate) => candidate.payload);
            const matches = await fetchExtensionMatches(settings.atlasDomain, settings.apiToken, payload);
            renderMatches(unsentCandidates, matches);
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

function installViewportListeners(): void {
    window.addEventListener('scroll', scheduleScan, { passive: true });
    window.addEventListener('resize', scheduleScan, { passive: true });
}

function bootstrap(): void {
    installMutationObserver();
    installViewportListeners();
    void runScanAndRender();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
