import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockSubmitBadgeReaction = vi.fn();
const mockUpsertReferrerCheckCache = vi.fn();

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: mockSubmitBadgeReaction,
}));

vi.mock('./referrer-check-queue', () => ({
    upsertReferrerCheckCache: mockUpsertReferrerCheckCache,
}));

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

function makeSuccessResponse() {
    return {
        ok: true,
        reaction: null,
        exists: true,
        fileId: 42,
        blacklistedAt: '2026-05-10T12:00:00Z',
        downloadRequested: false,
        shouldCloseTabAfterQueue: false,
        downloadTransferId: null,
        downloadStatus: null,
        downloadProgressPercent: null,
        downloadCloseTargets: [],
        reverbConfig: null,
    };
}

describe('anchor-referrer-blacklist-shortcut', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
    });

    it('blacklists an outlined anchor referrer on Alt right click', async () => {
        mockSubmitBadgeReaction.mockResolvedValue(makeSuccessResponse());
        const { handleAltRightClickReferrerBlacklist } = await import('./anchor-referrer-blacklist-shortcut');

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post?utm_source=feed#image-2';
        const image = document.createElement('img');
        image.src = 'https://cdn.example.com/image.jpg';
        image.setAttribute('data-atlas-anchor-media-red-border', '1');
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        let handled = false;
        image.addEventListener('mousedown', (event) => {
            handled = handleAltRightClickReferrerBlacklist({
                event,
                isPaused: () => false,
                resolveEligibleAnchorReferrerUrl: () => 'https://example.com/post#image-2',
                getReferrerCleanerQueryParams: () => ['utm_source'],
                applyPendingForReferrerUrls: vi.fn(),
                refreshReferrerUrlsFromCache: vi.fn(),
            });
        });
        image.dispatchEvent(new MouseEvent('mousedown', {
            altKey: true,
            bubbles: true,
            button: 2,
            cancelable: true,
            clientX: 20,
            clientY: 20,
        }));

        expect(handled).toBe(true);
        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'blacklist', {
            referrerUrlOverride: 'https://example.com/post#image-2',
        });

        await flushPromises();

        expect(mockUpsertReferrerCheckCache).toHaveBeenCalledWith(
            'https://example.com/post#image-2',
            {
                exists: true,
                reaction: null,
                reactedAt: null,
                downloadedAt: null,
                blacklistedAt: '2026-05-10T12:00:00Z',
            },
            ['utm_source'],
        );
        expect(image.getAttribute('data-atlas-anchor-blacklisted-at')).toBe('2026-05-10T12:00:00Z');
        expect(image.style.outline).toBe('4px solid #ef4444');
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('blacklisted');
    });

    it('blacklists an outlined anchor referrer when Alt right click targets the anchor', async () => {
        mockSubmitBadgeReaction.mockResolvedValue(makeSuccessResponse());
        const { handleAltRightClickReferrerBlacklist } = await import('./anchor-referrer-blacklist-shortcut');

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        image.src = 'https://cdn.example.com/image.jpg';
        image.setAttribute('data-atlas-anchor-media-red-border', '1');
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        let handled = false;
        anchor.addEventListener('mousedown', (event) => {
            handled = handleAltRightClickReferrerBlacklist({
                event,
                isPaused: () => false,
                resolveEligibleAnchorReferrerUrl: () => 'https://example.com/post#image-2',
                getReferrerCleanerQueryParams: () => [],
                applyPendingForReferrerUrls: vi.fn(),
                refreshReferrerUrlsFromCache: vi.fn(),
            });
        });
        anchor.dispatchEvent(new MouseEvent('mousedown', {
            altKey: true,
            bubbles: true,
            button: 2,
            cancelable: true,
            clientX: 20,
            clientY: 20,
        }));

        expect(handled).toBe(true);
        expect(mockSubmitBadgeReaction).toHaveBeenCalledWith(image, 'blacklist', {
            referrerUrlOverride: 'https://example.com/post#image-2',
        });
    });

    it('does not blacklist from an Alt contextmenu event alone', async () => {
        const { handleAltRightClickReferrerBlacklist } = await import('./anchor-referrer-blacklist-shortcut');

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        image.src = 'https://cdn.example.com/image.jpg';
        image.setAttribute('data-atlas-anchor-media-red-border', '1');
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        let handled = true;
        image.addEventListener('contextmenu', (event) => {
            handled = handleAltRightClickReferrerBlacklist({
                event,
                isPaused: () => false,
                resolveEligibleAnchorReferrerUrl: () => 'https://example.com/post#image-2',
                getReferrerCleanerQueryParams: () => [],
                applyPendingForReferrerUrls: vi.fn(),
                refreshReferrerUrlsFromCache: vi.fn(),
            });
        });
        image.dispatchEvent(new MouseEvent('contextmenu', {
            altKey: true,
            bubbles: true,
            button: 0,
            cancelable: true,
            clientX: 20,
            clientY: 20,
        }));

        expect(handled).toBe(false);
        expect(mockSubmitBadgeReaction).not.toHaveBeenCalled();
    });
});
