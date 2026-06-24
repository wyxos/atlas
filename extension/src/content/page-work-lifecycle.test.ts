import { describe, expect, it, vi } from 'vitest';

const mockDocumentWasDiscarded = vi.fn();

vi.mock('./restored-page-badge-check', () => ({
    documentWasDiscarded: mockDocumentWasDiscarded,
}));

function setDocumentVisibility(value: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        value,
    });
}

function dispatchPageTransitionEvent(type: 'pagehide' | 'pageshow', persisted: boolean): void {
    const event = new Event(type) as PageTransitionEvent;
    Object.defineProperty(event, 'persisted', {
        configurable: true,
        value: persisted,
    });
    window.dispatchEvent(event);
}

describe('installPageVisibilityLifecycle', () => {
    it('treats tab switches as state-preserving no-ops while preserving real page teardown', async () => {
        const { installPageVisibilityLifecycle } = await import('./page-work-lifecycle');
        const startPageWork = vi.fn();
        const destroyPageWork = vi.fn();

        mockDocumentWasDiscarded.mockReturnValue(false);
        setDocumentVisibility('visible');
        installPageVisibilityLifecycle(startPageWork, destroyPageWork);

        setDocumentVisibility('hidden');
        document.dispatchEvent(new Event('visibilitychange'));

        expect(startPageWork).not.toHaveBeenCalled();
        expect(destroyPageWork).not.toHaveBeenCalled();

        setDocumentVisibility('visible');
        document.dispatchEvent(new Event('visibilitychange'));

        expect(startPageWork).toHaveBeenCalledWith({ fullScan: false });
        expect(destroyPageWork).not.toHaveBeenCalled();

        dispatchPageTransitionEvent('pagehide', true);
        expect(destroyPageWork).not.toHaveBeenCalled();

        dispatchPageTransitionEvent('pagehide', false);
        expect(destroyPageWork).toHaveBeenCalledTimes(1);

        dispatchPageTransitionEvent('pageshow', true);
        expect(startPageWork).toHaveBeenLastCalledWith({
            fullScan: false,
            bypassBadgeCheckCache: true,
        });
    });
});
