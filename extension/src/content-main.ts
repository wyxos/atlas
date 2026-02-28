import { fetchExtensionMatches, loadContentConnectionSettings } from './content/atlas-client';
import { renderMatches } from './content/render-overlays';
import { scanMediaCandidates } from './content/scan-media';

const SCAN_LIMIT = 300;

let rescanQueued = false;
let isRunning = false;
let rerunRequested = false;
const processedSignatures = new WeakMap<Element, string>();

function candidateSignature(candidate: { mediaUrl: string | null; anchorUrl: string | null }): string {
    return [candidate.mediaUrl ?? '', candidate.anchorUrl ?? ''].join('|');
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
                return previousSignature !== signature;
            });

            if (unsentCandidates.length === 0) {
                continue;
            }

            // Render standalone hover widgets immediately, independent of match API success.
            renderMatches(unsentCandidates, new Map());

            const payload = unsentCandidates.flatMap((candidate) => {
                const entries = [];

                if (candidate.mediaUrl) {
                    entries.push({
                        candidate_id: candidate.id,
                        type: 'media' as const,
                        url: candidate.mediaUrl,
                    });
                }

                if (candidate.anchorUrl) {
                    entries.push({
                        candidate_id: candidate.id,
                        type: 'referrer' as const,
                        url: candidate.anchorUrl,
                    });
                }

                return entries;
            });
            const matches = await fetchExtensionMatches(settings.atlasDomain, settings.apiToken, payload);
            renderMatches(unsentCandidates, matches);
            for (const candidate of unsentCandidates) {
                processedSignatures.set(candidate.element, candidateSignature(candidate));
            }
        } while (rerunRequested);
    } catch {
        // Ignore content-side network/runtime failures; next mutation tick will retry.
    } finally {
        isRunning = false;
    }
}

function scheduleScan(): void {
    if (rescanQueued) {
        return;
    }

    rescanQueued = true;
    window.requestAnimationFrame(() => {
        rescanQueued = false;
        void runScanAndRender();
    }
    );
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
    document.addEventListener('scroll', scheduleScan, { capture: true, passive: true });
    window.addEventListener('resize', scheduleScan, { passive: true });
}

function installInteractionListeners(): void {
    document.addEventListener('pointerover', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) {
            return;
        }

        if (target.closest('img,video') !== null) {
            scheduleScan();
        }
    }, { capture: true, passive: true });
}

function bootstrap(): void {
    installMutationObserver();
    installViewportListeners();
    installInteractionListeners();
    void runScanAndRender();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
} else {
    bootstrap();
}
