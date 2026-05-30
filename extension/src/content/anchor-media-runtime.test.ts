import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockUrlMatchesAnyRule = vi.fn();
const mockEnqueueReferrerCheck = vi.fn();
const mockGetCachedReferrerCheck = vi.fn();
const mockUpsertReferrerCheckCache = vi.fn();
const mockInvalidateOpenTabCheckCache = vi.fn();
const mockIsUrlOpenInAnotherTab = vi.fn();
const mockSubmitBadgeReaction = vi.fn();

vi.mock('../match-rules', async () => {
    const actual = await vi.importActual<typeof import('../match-rules')>('../match-rules');

    return {
        ...actual,
        urlMatchesAnyRule: mockUrlMatchesAnyRule,
    };
});

vi.mock('./referrer-check-queue', () => ({
    enqueueReferrerCheck: mockEnqueueReferrerCheck,
    getCachedReferrerCheck: mockGetCachedReferrerCheck,
    upsertReferrerCheckCache: mockUpsertReferrerCheckCache,
}));

vi.mock('./open-anchor-tab-check', () => ({
    invalidateOpenTabCheckCache: mockInvalidateOpenTabCheckCache,
    isUrlOpenInAnotherTab: mockIsUrlOpenInAnotherTab,
    toComparableOpenTabUrl: (url: string | null) => url,
}));

vi.mock('./reaction-submit', () => ({
    submitBadgeReaction: mockSubmitBadgeReaction,
}));

function visibleRect(): DOMRect {
    return {
        x: 12,
        y: 12,
        top: 12,
        left: 12,
        right: 112,
        bottom: 112,
        width: 100,
        height: 100,
        toJSON: () => ({}),
    } as DOMRect;
}

async function flushPromises(): Promise<void> {
    await Promise.resolve();
    await Promise.resolve();
}

describe('anchor-media-runtime', () => {
    beforeEach(() => {
        vi.useRealTimers();
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://current.example.com/current') as unknown as Location,
        });

        mockUrlMatchesAnyRule.mockReturnValue(true);
        mockIsUrlOpenInAnotherTab.mockResolvedValue(false);
        mockGetCachedReferrerCheck.mockReturnValue(null);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('shows a checking outline and spinner while the referrer check is pending, then clears on a miss', async () => {
        let resolveCheck: ((value: { exists: boolean; reaction: null; reactedAt: null; downloadedAt: null; blacklistedAt: null }) => void) | null = null;
        const checkPromise = new Promise<{ exists: boolean; reaction: null; reactedAt: null; downloadedAt: null; blacklistedAt: null }>((resolve) => {
            resolveCheck = resolve;
        });
        mockEnqueueReferrerCheck.mockReturnValue(checkPromise);

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.registerFromDocument();

        expect(image.style.outline).not.toBe('');
        expect(image.style.opacity).toBe('0.35');
        expect(image.getAttribute('data-atlas-anchor-checking')).toBe('1');

        const checkingBadge = anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]');
        expect(checkingBadge?.getAttribute('data-atlas-anchor-badge-kind')).toBe('checking');

        resolveCheck?.({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });

        await flushPromises();

        expect(mockIsUrlOpenInAnotherTab).toHaveBeenCalledWith(anchor.href);
        expect(image.style.outline).toBe('');
        expect(image.style.opacity).toBe('');
        expect(image.getAttribute('data-atlas-anchor-checking')).toBeNull();
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')).toBeNull();
    });

    it('skips the checking state when the referrer result is already cached', async () => {
        mockGetCachedReferrerCheck.mockReturnValue({
            exists: true,
            reaction: 'like',
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.registerFromDocument();
        await flushPromises();

        expect(mockEnqueueReferrerCheck).not.toHaveBeenCalled();
        expect(image.getAttribute('data-atlas-anchor-checking')).toBeNull();
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('reaction');
    });

    it('strips configured referrer query params before enqueuing checks', async () => {
        mockEnqueueReferrerCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => ['tag', 'tags'],
            getPageHostname: () => 'domain.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://domain.com/?id=123&tag=blue+sky';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.registerFromDocument();
        await flushPromises();

        expect(mockGetCachedReferrerCheck).toHaveBeenCalledWith('https://domain.com/?id=123', ['tag', 'tags']);
        expect(mockEnqueueReferrerCheck).toHaveBeenCalledWith('https://domain.com/?id=123', ['tag', 'tags']);
    });

    it('marks anchor media that links to the exact current page without queuing a referrer check', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://www.deviantart.com/fr34ky5/art/Lois-Lane-1281274875') as unknown as Location,
        });

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'www.deviantart.com',
        });

        const anchor = document.createElement('a');
        anchor.href = window.location.href;
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.registerFromDocument();
        await flushPromises();

        expect(mockGetCachedReferrerCheck).not.toHaveBeenCalled();
        expect(mockEnqueueReferrerCheck).not.toHaveBeenCalled();
        expect(mockIsUrlOpenInAnotherTab).not.toHaveBeenCalled();
        expect(image.getAttribute('data-atlas-anchor-same-page')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-checking')).toBeNull();
        expect(image.getAttribute('data-atlas-anchor-media-red-border')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-media-match')).toBe('0');
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('same-page');
    });

    it('does not mark same-page when only cleaned referrer urls match', async () => {
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://example.com/post?tag=current') as unknown as Location,
        });
        mockEnqueueReferrerCheck.mockResolvedValue({
            exists: false,
            reaction: null,
            reactedAt: null,
            downloadedAt: null,
            blacklistedAt: null,
        });

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => ['tag'],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post?tag=anchor';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.registerFromDocument();
        await flushPromises();

        expect(image.getAttribute('data-atlas-anchor-same-page')).toBeNull();
        expect(mockEnqueueReferrerCheck).toHaveBeenCalledWith('https://example.com/post', ['tag']);
    });

    it('marks changed anchors as already open elsewhere when tab presence changes', async () => {
        mockIsUrlOpenInAnotherTab.mockResolvedValue(true);

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.handleTabPresenceChanged([anchor.href]);
        await flushPromises();

        expect(mockInvalidateOpenTabCheckCache).toHaveBeenCalledWith([anchor.href]);
        expect(mockIsUrlOpenInAnotherTab).toHaveBeenCalledWith(anchor.href);
        expect(image.getAttribute('data-atlas-anchor-opened-elsewhere')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-media-red-border')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-media-match')).toBe('0');
    });

    it('shows pending referrer sync state, then applies the settled reaction immediately', async () => {
        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.handleReferrerReactionSync({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'pending',
            urls: [anchor.href],
        });

        expect(image.getAttribute('data-atlas-anchor-checking')).toBe('1');
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('checking');

        runtime.handleReferrerReactionSync({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'settled',
            urls: [anchor.href],
            reaction: 'love',
            reactedAt: '2026-03-26T12:00:00Z',
            downloadedAt: null,
            blacklistedAt: null,
        });

        expect(mockUpsertReferrerCheckCache).toHaveBeenCalledWith(
            'https://example.com/post#image-2',
            {
                exists: true,
                reaction: 'love',
                reactedAt: '2026-03-26T12:00:00Z',
                downloadedAt: null,
                blacklistedAt: null,
            },
            [],
        );
        expect(image.getAttribute('data-atlas-anchor-checking')).toBeNull();
        expect(image.getAttribute('data-atlas-anchor-reaction')).toBe('love');
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('reaction');
    });

    it('updates referrer sync cache while paused without touching the DOM', async () => {
        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post#image-2';
        const image = document.createElement('img');
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.suspend();
        runtime.handleReferrerReactionSync({
            type: 'ATLAS_REFERRER_REACTION_SYNC',
            phase: 'settled',
            urls: [anchor.href],
            reaction: 'like',
            reactedAt: '2026-03-26T12:00:00Z',
            downloadedAt: null,
            blacklistedAt: null,
        });

        expect(mockUpsertReferrerCheckCache).toHaveBeenCalledWith(
            'https://example.com/post#image-2',
            {
                exists: true,
                reaction: 'like',
                reactedAt: '2026-03-26T12:00:00Z',
                downloadedAt: null,
                blacklistedAt: null,
            },
            [],
        );
        expect(image.getAttribute('data-atlas-anchor-reaction')).toBeNull();
    });

    it('blacklists an outlined anchor referrer on Alt right click', async () => {
        mockSubmitBadgeReaction.mockResolvedValue({
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
        });

        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => ['utm_source'],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post?utm_source=feed#image-2';
        const image = document.createElement('img');
        image.src = 'https://cdn.example.com/image.jpg';
        image.setAttribute('data-atlas-anchor-media-red-border', '1');
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        let handled = false;
        image.addEventListener('contextmenu', (event) => {
            handled = runtime.handleAltRightClick(event);
        });
        image.dispatchEvent(new MouseEvent('contextmenu', {
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
    });

    it('updates matching anchors when download progress events carry reaction metadata', async () => {
        const { createAnchorMediaRuntime } = await import('./anchor-media-runtime');
        const runtime = createAnchorMediaRuntime({
            getIsEnabled: () => true,
            getRules: () => [],
            getReferrerCleanerQueryParams: () => [],
            getPageHostname: () => 'example.com',
        });

        const anchor = document.createElement('a');
        anchor.href = 'https://example.com/post';
        const image = document.createElement('img');
        Object.defineProperty(image, 'getBoundingClientRect', {
            value: () => visibleRect(),
        });
        anchor.appendChild(image);
        document.body.appendChild(anchor);

        runtime.handleDownloadProgressEvent({
            event: 'DownloadTransferQueued',
            transferId: 55,
            fileId: 12,
            referrerUrl: anchor.href,
            status: 'queued',
            percent: 10,
            reaction: 'funny',
            downloadedAt: '2026-03-21T00:00:00Z',
            blacklistedAt: null,
            payload: {
                file_id: 12,
            },
        });

        expect(mockUpsertReferrerCheckCache).toHaveBeenCalledWith(
            'https://example.com/post',
            {
                exists: true,
                reaction: 'funny',
                reactedAt: undefined,
                downloadedAt: '2026-03-21T00:00:00Z',
                blacklistedAt: null,
            },
            [],
        );
        expect(image.getAttribute('data-atlas-anchor-media-red-border')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-media-match')).toBe('1');
        expect(image.getAttribute('data-atlas-anchor-reaction')).toBe('funny');
        expect(image.getAttribute('data-atlas-anchor-downloaded-at')).toBe('2026-03-21T00:00:00Z');
        expect(image.getAttribute('data-atlas-anchor-blacklisted-at')).toBeNull();
    });
});
