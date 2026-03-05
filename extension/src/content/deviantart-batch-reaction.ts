import { normalizeHashAwareUrl, normalizeUrl, type MediaElement } from './media-utils';

const DEVIANT_ART_HOST_PATTERN = /(^|\.)deviantart\.com$/i;
const IMAGE_HASH_PATTERN = /#image-(\d+)$/i;

export type BatchReactionItem = {
    candidateId: string;
    url: string;
    referrerUrlHashAware: string;
    pageUrl: string;
    tagName: 'img';
};

type CollectBatchReactionItemsOptions = {
    hostname?: string;
};

function isDeviantArtHostname(hostname: string | null | undefined): boolean {
    if (typeof hostname !== 'string') {
        return false;
    }

    return DEVIANT_ART_HOST_PATTERN.test(hostname.trim().toLowerCase());
}

function sleep(delayMs: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, delayMs);
    });
}

function parseImageNumber(url: string): number {
    const match = url.match(IMAGE_HASH_PATTERN);
    if (!match) {
        return 1;
    }

    const parsed = Number(match[1]);
    return Number.isInteger(parsed) && parsed > 0 ? parsed : 1;
}

function resolveCurrentImageUrl(media: HTMLImageElement): string | null {
    return normalizeUrl(media.getAttribute('src') || media.src || null);
}

function resolveCurrentLocationState(): { href: string; pageUrl: string; imageNumber: number } | null {
    const href = normalizeHashAwareUrl(window.location.href);
    const pageUrl = normalizeUrl(href);
    if (href === null || pageUrl === null) {
        return null;
    }

    return {
        href,
        pageUrl,
        imageNumber: parseImageNumber(href),
    };
}

function resolveAllImagesSection(media: HTMLImageElement, hostname: string): HTMLElement | null {
    if (!isDeviantArtHostname(hostname)) {
        return null;
    }

    const root = media.closest('main, article, [role="main"]') ?? document.querySelector('main') ?? document.body;
    const mediaRect = media.getBoundingClientRect();
    if (mediaRect.width < 1 || mediaRect.height < 1) {
        return null;
    }

    const sections = Array.from(root.querySelectorAll('section'));

    return sections.find((section) => {
        const heading = Array.from(section.querySelectorAll('h1, h2, h3'))
            .find((element) => element.textContent?.trim() === 'All Images');
        if (heading === undefined) {
            return false;
        }

        const thumbnailButtons = Array.from(section.querySelectorAll('button'))
            .filter((button): button is HTMLButtonElement => button instanceof HTMLButtonElement)
            .filter((button) => button.querySelector('img') instanceof HTMLImageElement);
        if (thumbnailButtons.length < 2) {
            return false;
        }

        const sectionRect = section.getBoundingClientRect();

        return sectionRect.top >= mediaRect.bottom - 24 && sectionRect.top <= mediaRect.bottom + 220;
    }) ?? null;
}

function resolveThumbnailButtons(section: HTMLElement): HTMLButtonElement[] {
    return Array.from(section.querySelectorAll('button'))
        .filter((button): button is HTMLButtonElement => button instanceof HTMLButtonElement)
        .filter((button) => button.querySelector('img') instanceof HTMLImageElement);
}

async function waitForImageSelectionChange(
    media: HTMLImageElement,
    previousImageUrl: string | null,
    previousHref: string,
): Promise<void> {
    const startedAt = Date.now();

    while (Date.now() - startedAt < 2500) {
        const nextImageUrl = resolveCurrentImageUrl(media);
        const nextHref = normalizeHashAwareUrl(window.location.href);
        if ((nextImageUrl !== null && nextImageUrl !== previousImageUrl) || (nextHref !== null && nextHref !== previousHref)) {
            await sleep(50);
            return;
        }

        await sleep(50);
    }
}

function buildBatchReactionItem(
    media: HTMLImageElement,
    candidateIndex: number,
): BatchReactionItem | null {
    const url = resolveCurrentImageUrl(media);
    const locationState = resolveCurrentLocationState();
    if (url === null || locationState === null) {
        return null;
    }

    return {
        candidateId: `image-${locationState.imageNumber || candidateIndex + 1}`,
        url,
        referrerUrlHashAware: locationState.imageNumber === 1 ? locationState.pageUrl : locationState.href,
        pageUrl: locationState.pageUrl,
        tagName: 'img',
    };
}

async function restoreInitialSelection(
    media: HTMLImageElement,
    buttons: HTMLButtonElement[],
    initialImageUrl: string,
    initialHref: string,
    initialImageNumber: number,
): Promise<void> {
    const currentImageUrl = resolveCurrentImageUrl(media);
    const currentHref = normalizeHashAwareUrl(window.location.href);
    if (currentImageUrl === initialImageUrl && currentHref === initialHref) {
        return;
    }

    const restoreButton = buttons[initialImageNumber - 1] ?? null;
    if (restoreButton !== null) {
        const beforeImageUrl = resolveCurrentImageUrl(media);
        const beforeHref = normalizeHashAwareUrl(window.location.href) ?? initialHref;
        restoreButton.click();
        await waitForImageSelectionChange(media, beforeImageUrl, beforeHref);
    }

    if (normalizeHashAwareUrl(window.location.href) !== initialHref) {
        history.replaceState(history.state, document.title, initialHref);
    }
}

export async function collectDeviantArtBatchReactionItems(
    media: MediaElement,
    options: CollectBatchReactionItemsOptions = {},
): Promise<BatchReactionItem[] | null> {
    if (!(media instanceof HTMLImageElement)) {
        return null;
    }

    const hostname = options.hostname ?? window.location.hostname;
    const section = resolveAllImagesSection(media, hostname);
    if (section === null) {
        return null;
    }

    const buttons = resolveThumbnailButtons(section);
    if (buttons.length < 2) {
        return null;
    }

    const initialImageUrl = resolveCurrentImageUrl(media);
    const initialLocationState = resolveCurrentLocationState();
    if (initialImageUrl === null || initialLocationState === null) {
        return null;
    }

    const items: BatchReactionItem[] = [];
    const seenUrls = new Set<string>();

    const collectCurrentItem = (candidateIndex: number): void => {
        const item = buildBatchReactionItem(media, candidateIndex);
        if (item === null || seenUrls.has(item.url)) {
            return;
        }

        seenUrls.add(item.url);
        items.push(item);
    };

    collectCurrentItem(initialLocationState.imageNumber - 1);

    try {
        for (const [index, button] of buttons.entries()) {
            const beforeImageUrl = resolveCurrentImageUrl(media);
            const beforeHref = normalizeHashAwareUrl(window.location.href) ?? initialLocationState.href;
            button.click();
            await waitForImageSelectionChange(media, beforeImageUrl, beforeHref);
            collectCurrentItem(index);
        }
    } finally {
        await restoreInitialSelection(
            media,
            buttons,
            initialImageUrl,
            initialLocationState.href,
            initialLocationState.imageNumber,
        );
    }

    return items.length >= 2 ? items : null;
}
