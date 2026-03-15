import {
    findRelatedDeviantArtAllImagesSection,
    normalizeHashAwareUrl,
    normalizeUrl,
    resolveMediaSectionSearchRoot,
    type MediaElement,
} from './media-utils';
import type { BatchReactionItem } from './reaction-batch-types';

const IMAGE_HASH_PATTERN = /#image-(\d+)$/i;

type CollectBatchReactionItemsOptions = {
    hostname?: string;
};

type CurrentImageResolver = () => HTMLImageElement | null;

function sleep(delayMs: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, delayMs);
    });
}

function parseImageNumber(url: string): number | null {
    const match = url.match(IMAGE_HASH_PATTERN);
    if (!match) {
        return null;
    }

    const parsed = Number(match[1]);
    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function resolveCurrentImageUrl(media: HTMLImageElement | null): string | null {
    if (!(media instanceof HTMLImageElement)) {
        return null;
    }

    return normalizeUrl(media.getAttribute('src') || media.src || null);
}

function resolveCurrentLocationState(): { href: string; pageUrl: string; imageNumber: number | null } | null {
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

function resolveThumbnailButtons(section: HTMLElement): HTMLButtonElement[] {
    return Array.from(section.querySelectorAll('button'))
        .filter((button): button is HTMLButtonElement => button instanceof HTMLButtonElement)
        .filter((button) => button.querySelector('img') instanceof HTMLImageElement);
}

async function waitForImageSelectionChange(
    resolveCurrentMedia: CurrentImageResolver,
    previousImageUrl: string | null,
    previousHref: string,
): Promise<void> {
    const startedAt = Date.now();

    while (Date.now() - startedAt < 2500) {
        const nextImageUrl = resolveCurrentImageUrl(resolveCurrentMedia());
        const nextHref = normalizeHashAwareUrl(window.location.href);
        if ((nextImageUrl !== null && nextImageUrl !== previousImageUrl) || (nextHref !== null && nextHref !== previousHref)) {
            await sleep(50);
            return;
        }

        await sleep(50);
    }
}

function createCurrentImageResolver(
    media: HTMLImageElement,
    section: HTMLElement,
): CurrentImageResolver {
    const root = resolveMediaSectionSearchRoot(media, section);
    const initialRect = media.getBoundingClientRect();
    const minimumWidth = Math.max(160, Math.round(initialRect.width * 0.4));
    const minimumHeight = Math.max(160, Math.round(initialRect.height * 0.4));

    return () => {
        if (media.isConnected) {
            return media;
        }

        const candidates = Array.from(root.querySelectorAll('img'))
            .filter((candidate): candidate is HTMLImageElement => candidate instanceof HTMLImageElement)
            .filter((candidate) => !section.contains(candidate))
            .filter((candidate) => {
                const rect = candidate.getBoundingClientRect();

                return rect.width >= minimumWidth && rect.height >= minimumHeight;
            })
            .sort((left, right) => {
                const leftRect = left.getBoundingClientRect();
                const rightRect = right.getBoundingClientRect();

                return (rightRect.width * rightRect.height) - (leftRect.width * leftRect.height);
            });

        return candidates[0] ?? null;
    };
}

function buildBatchReactionItem(
    media: HTMLImageElement | null,
    candidateIndex: number,
): BatchReactionItem | null {
    const url = resolveCurrentImageUrl(media);
    const locationState = resolveCurrentLocationState();
    if (url === null || locationState === null) {
        return null;
    }

    return {
        candidateId: `image-${locationState.imageNumber ?? candidateIndex + 1}`,
        url,
        referrerUrlHashAware: locationState.imageNumber === null || locationState.imageNumber === 1
            ? locationState.pageUrl
            : locationState.href,
        pageUrl: locationState.pageUrl,
        tagName: 'img',
    };
}

async function restoreInitialSelection(
    resolveCurrentMedia: CurrentImageResolver,
    buttons: HTMLButtonElement[],
    initialImageUrl: string,
    initialHref: string,
    initialImageNumber: number,
): Promise<void> {
    const currentImageUrl = resolveCurrentImageUrl(resolveCurrentMedia());
    const currentHref = normalizeHashAwareUrl(window.location.href);
    if (currentImageUrl === initialImageUrl && currentHref === initialHref) {
        return;
    }

    const restoreButton = buttons[initialImageNumber - 1] ?? null;
    if (restoreButton !== null) {
        const beforeImageUrl = resolveCurrentImageUrl(resolveCurrentMedia());
        const beforeHref = normalizeHashAwareUrl(window.location.href) ?? initialHref;
        restoreButton.click();
        await waitForImageSelectionChange(resolveCurrentMedia, beforeImageUrl, beforeHref);
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
    const section = findRelatedDeviantArtAllImagesSection(media, hostname);
    if (section === null) {
        return null;
    }

    const buttons = resolveThumbnailButtons(section);
    if (buttons.length < 2) {
        return null;
    }

    const resolveCurrentMedia = createCurrentImageResolver(media, section);
    const initialImageUrl = resolveCurrentImageUrl(resolveCurrentMedia());
    const initialLocationState = resolveCurrentLocationState();
    if (initialImageUrl === null || initialLocationState === null) {
        return null;
    }

    const items: BatchReactionItem[] = [];
    const seenUrls = new Set<string>();

    const collectCurrentItem = (candidateIndex: number): void => {
        const item = buildBatchReactionItem(resolveCurrentMedia(), candidateIndex);
        if (item === null || seenUrls.has(item.url)) {
            return;
        }

        seenUrls.add(item.url);
        items.push(item);
    };

    collectCurrentItem((initialLocationState.imageNumber ?? 1) - 1);

    try {
        for (const [index, button] of buttons.entries()) {
            const beforeImageUrl = resolveCurrentImageUrl(resolveCurrentMedia());
            const beforeHref = normalizeHashAwareUrl(window.location.href) ?? initialLocationState.href;
            button.click();
            await waitForImageSelectionChange(resolveCurrentMedia, beforeImageUrl, beforeHref);
            collectCurrentItem(index);
        }
    } finally {
        await restoreInitialSelection(
            resolveCurrentMedia,
            buttons,
            initialImageUrl,
            initialLocationState.href,
            initialLocationState.imageNumber ?? 1,
        );
    }

    return items.length >= 2 ? items : null;
}
