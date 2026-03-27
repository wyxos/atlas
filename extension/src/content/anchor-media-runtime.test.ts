import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mockUrlMatchesAnyRule = vi.fn();
const mockEnqueueReferrerCheck = vi.fn();
const mockGetCachedReferrerCheck = vi.fn();
const mockUpsertReferrerCheckCache = vi.fn();
const mockInvalidateOpenTabCheckCache = vi.fn();
const mockIsUrlOpenInAnotherTab = vi.fn();

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
