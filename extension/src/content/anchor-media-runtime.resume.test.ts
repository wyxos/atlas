import { beforeEach, describe, expect, it, vi } from 'vitest';

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

type MissedReferrerResult = {
    exists: false;
    reaction: null;
    reactedAt: null;
    downloadedAt: null;
    blacklistedAt: null;
};

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

function missedReferrerResult(): MissedReferrerResult {
    return {
        exists: false,
        reaction: null,
        reactedAt: null,
        downloadedAt: null,
        blacklistedAt: null,
    };
}

describe('anchor-media-runtime resume behavior', () => {
    beforeEach(() => {
        vi.resetModules();
        vi.clearAllMocks();
        document.body.innerHTML = '';
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: new URL('https://current.example.com/current') as unknown as Location,
        });

        mockUrlMatchesAnyRule.mockReturnValue(true);
        mockGetCachedReferrerCheck.mockReturnValue(null);
    });

    it('rechecks missed referrers that settled while paused so resume can pick up updates', async () => {
        let resolveInitialCheck: ((value: MissedReferrerResult) => void) | null = null;
        mockEnqueueReferrerCheck
            .mockReturnValueOnce(new Promise<MissedReferrerResult>((resolve) => {
                resolveInitialCheck = resolve;
            }))
            .mockResolvedValueOnce({
                exists: true,
                reaction: 'like',
                reactedAt: '2026-03-26T12:00:00Z',
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

        expect(image.getAttribute('data-atlas-anchor-checking')).toBe('1');

        runtime.suspend();
        resolveInitialCheck?.(missedReferrerResult());
        await flushPromises();

        expect(image.getAttribute('data-atlas-anchor-checking')).toBe('1');

        runtime.resume();
        runtime.registerVisibleFromDocument();
        await flushPromises();

        expect(mockEnqueueReferrerCheck).toHaveBeenCalledTimes(2);
        expect(image.getAttribute('data-atlas-anchor-checking')).toBeNull();
        expect(image.getAttribute('data-atlas-anchor-reaction')).toBe('like');
        expect(anchor.querySelector('[data-atlas-anchor-reaction-badge="1"]')?.getAttribute('data-atlas-anchor-badge-kind')).toBe('reaction');
    });
});
