import {
    normalizeHashAwareUrl,
    normalizeUrl,
    resolveReactionMediaUrl,
    type MediaElement,
} from './media-utils';
import type {
    BatchReactionItem,
    ListingMetadataOverrides,
    ListingMetadataResourceContainer,
} from './reaction-batch-types';

const CIVITAI_HOST_PATTERN = /(^|\.)civitai\.com$/i;
const CIVITAI_POST_PATH_PATTERN = /^\/posts\/(\d+)(?:\/|$)/i;
const CIVITAI_IMAGE_PATH_PATTERN = /^\/images\/(\d+)(?:\/|$)/i;
const IMAGE_LINK_SELECTOR = 'a[href^="/images/"], a[href*="://civitai.com/images/"]';
const USER_LINK_SELECTOR = 'a[href^="/user/"], a[href*="://civitai.com/user/"]';
const MODEL_LINK_SELECTOR = 'a[href^="/models/"], a[href*="://civitai.com/models/"]';
const CREATOR_CARD_SELECTOR = '[class*="CreatorCard_profileDetailsContainer"], [class*="CreatorCard_profileDetails"]';
const MENU_BUTTON_SELECTOR = 'button[aria-haspopup="menu"]';
const MODEL_RESOURCE_TYPES = new Set<ListingMetadataResourceContainer['type']>(['Checkpoint', 'LoRA']);

export type CivitAiReactionPageKind = 'post-page' | 'image-page';

type CivitAiCardCandidate = {
    cardRoot: HTMLElement;
    media: MediaElement;
    imagePageUrl: string;
    item: BatchReactionItem;
};

function parseRelativeOrAbsoluteUrl(value: string | null | undefined): URL | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    try {
        return new URL(trimmed, window.location.href);
    } catch {
        return null;
    }
}

function parseIdFromPath(url: URL, pattern: RegExp): number | null {
    const match = url.pathname.match(pattern);
    if (!match) {
        return null;
    }

    const parsed = Number(match[1]);

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
}

function parseImageIdFromHref(href: string | null | undefined): number | null {
    const url = parseRelativeOrAbsoluteUrl(href);

    return url === null ? null : parseIdFromPath(url, CIVITAI_IMAGE_PATH_PATTERN);
}

function parsePostIdFromHref(href: string | null | undefined): number | null {
    const url = parseRelativeOrAbsoluteUrl(href);

    return url === null ? null : parseIdFromPath(url, CIVITAI_POST_PATH_PATTERN);
}

function toAbsoluteUrl(href: string | null | undefined): string | null {
    const normalized = normalizeHashAwareUrl(parseRelativeOrAbsoluteUrl(href)?.href ?? null);

    return normalized;
}

function isImagePageLink(anchor: HTMLAnchorElement): boolean {
    return parseImageIdFromHref(anchor.getAttribute('href') ?? anchor.href) !== null;
}

function resolveImageLinkForMedia(media: MediaElement): HTMLAnchorElement | null {
    const anchor = media.closest('a');

    return anchor instanceof HTMLAnchorElement && isImagePageLink(anchor) ? anchor : null;
}

function resolveCivitAiCardRoot(anchor: HTMLAnchorElement): HTMLElement | null {
    const paperRoot = anchor.closest('.mantine-Paper-root');
    if (paperRoot instanceof HTMLElement) {
        return paperRoot;
    }

    let current: HTMLElement | null = anchor.parentElement;
    while (current !== null && current !== document.body) {
        const imageLinks = Array.from(current.querySelectorAll(IMAGE_LINK_SELECTOR))
            .filter((element): element is HTMLAnchorElement => element instanceof HTMLAnchorElement)
            .filter(isImagePageLink);
        if (imageLinks.length === 1 && imageLinks[0] === anchor) {
            return current;
        }

        current = current.parentElement;
    }

    return anchor.parentElement;
}

function collectUniqueCardRoots(root: ParentNode): HTMLElement[] {
    const roots: HTMLElement[] = [];
    const seen = new Set<HTMLElement>();

    for (const anchor of root.querySelectorAll(IMAGE_LINK_SELECTOR)) {
        if (!(anchor instanceof HTMLAnchorElement) || !isImagePageLink(anchor)) {
            continue;
        }

        const cardRoot = resolveCivitAiCardRoot(anchor);
        if (!(cardRoot instanceof HTMLElement) || seen.has(cardRoot)) {
            continue;
        }

        const media = cardRoot.querySelector('img,video');
        if (!(media instanceof HTMLImageElement) && !(media instanceof HTMLVideoElement)) {
            continue;
        }

        seen.add(cardRoot);
        roots.push(cardRoot);
    }

    return roots;
}

function resolveCivitAiPostBatchRoot(cardRoot: HTMLElement): HTMLElement | null {
    let current: HTMLElement | null = cardRoot.parentElement;
    while (current !== null && current !== document.body) {
        const roots = collectUniqueCardRoots(current);
        if (roots.length >= 2 && roots.includes(cardRoot)) {
            return current;
        }

        current = current.parentElement;
    }

    return null;
}

function resolvePageUrl(): string | null {
    return normalizeUrl(window.location.href);
}

export function classifyCivitAiReactionPage(href: string = window.location.href): CivitAiReactionPageKind | null {
    const url = parseRelativeOrAbsoluteUrl(href);
    if (url === null || !CIVITAI_HOST_PATTERN.test(url.hostname)) {
        return null;
    }

    if (CIVITAI_POST_PATH_PATTERN.test(url.pathname)) {
        return 'post-page';
    }

    if (CIVITAI_IMAGE_PATH_PATTERN.test(url.pathname)) {
        return 'image-page';
    }

    return null;
}

export function rewriteCivitAiImageAssetUrl(url: string | null | undefined): string | null {
    const normalizedUrl = normalizeUrl(url);
    if (normalizedUrl === null) {
        return null;
    }

    const parsed = parseRelativeOrAbsoluteUrl(normalizedUrl);
    if (parsed === null || !/(^|\.)image\.civitai\.com$/i.test(parsed.hostname)) {
        return normalizedUrl;
    }

    const segments = parsed.pathname.split('/').filter((segment) => segment !== '');
    if (segments.length < 4) {
        return normalizedUrl;
    }

    const [token, guid, , ...rest] = segments;
    if (!token || !guid || rest.length === 0) {
        return normalizedUrl;
    }

    parsed.pathname = `/${token}/${guid}/original=true,quality=90/${rest.join('/')}`;
    parsed.hash = '';

    return parsed.toString();
}

function buildBatchReactionItemForCard(cardRoot: HTMLElement, pageUrl: string): CivitAiCardCandidate | null {
    const anchor = cardRoot.querySelector(IMAGE_LINK_SELECTOR);
    if (!(anchor instanceof HTMLAnchorElement) || !isImagePageLink(anchor)) {
        return null;
    }

    const imagePageUrl = toAbsoluteUrl(anchor.getAttribute('href') ?? anchor.href);
    const imageId = parseImageIdFromHref(imagePageUrl);
    if (imagePageUrl === null || imageId === null) {
        return null;
    }

    const media = anchor.querySelector('video, img');
    if (!(media instanceof HTMLImageElement) && !(media instanceof HTMLVideoElement)) {
        return null;
    }

    const url = media instanceof HTMLVideoElement
        ? resolveReactionMediaUrl(media)
        : rewriteCivitAiImageAssetUrl(media.getAttribute('src') || media.src || null);
    if (url === null) {
        return null;
    }

    return {
        cardRoot,
        media,
        imagePageUrl,
        item: {
            candidateId: `image-${imageId}`,
            url,
            referrerUrlHashAware: imagePageUrl,
            pageUrl,
            tagName: media instanceof HTMLVideoElement ? 'video' : 'img',
        },
    };
}

function collectCivitAiBatchCardCandidates(media: MediaElement): CivitAiCardCandidate[] {
    const pageKind = classifyCivitAiReactionPage();
    if (pageKind !== 'post-page') {
        return [];
    }

    const anchor = resolveImageLinkForMedia(media);
    if (!(anchor instanceof HTMLAnchorElement)) {
        return [];
    }

    const cardRoot = resolveCivitAiCardRoot(anchor);
    if (!(cardRoot instanceof HTMLElement)) {
        return [];
    }

    const batchRoot = resolveCivitAiPostBatchRoot(cardRoot);
    const pageUrl = resolvePageUrl();
    if (!(batchRoot instanceof HTMLElement) || pageUrl === null) {
        return [];
    }

    const candidates = collectUniqueCardRoots(batchRoot)
        .map((candidateRoot) => buildBatchReactionItemForCard(candidateRoot, pageUrl))
        .filter((candidate): candidate is CivitAiCardCandidate => candidate !== null);

    const clickedRootCandidates = candidates.filter((candidate) => candidate.cardRoot === cardRoot);
    const otherCandidates = candidates.filter((candidate) => candidate.cardRoot !== cardRoot);

    return [...clickedRootCandidates, ...otherCandidates];
}

export function hasCivitAiBatchReactionItems(media: MediaElement): boolean {
    return collectCivitAiBatchCardCandidates(media).length >= 2;
}

export async function collectCivitAiBatchReactionItems(media: MediaElement): Promise<BatchReactionItem[] | null> {
    const items = collectCivitAiBatchCardCandidates(media).map((candidate) => candidate.item);

    return items.length >= 2 ? items : null;
}

function resolveUsernameFromAnchor(anchor: HTMLAnchorElement | null): string | null {
    if (!(anchor instanceof HTMLAnchorElement)) {
        return null;
    }

    const href = parseRelativeOrAbsoluteUrl(anchor.getAttribute('href') ?? anchor.href);
    if (href === null) {
        return null;
    }

    const segments = href.pathname.split('/').filter((segment) => segment !== '');
    if (segments[0] !== 'user') {
        return null;
    }

    const username = segments[1]?.trim();

    return username ? username : null;
}

function resolveUsername(): string | null {
    const preferredAnchor = document.querySelector(`${CREATOR_CARD_SELECTOR} ${USER_LINK_SELECTOR}`);
    if (preferredAnchor instanceof HTMLAnchorElement) {
        return resolveUsernameFromAnchor(preferredAnchor);
    }

    for (const anchor of document.querySelectorAll(USER_LINK_SELECTOR)) {
        if (!(anchor instanceof HTMLAnchorElement)) {
            continue;
        }

        const username = resolveUsernameFromAnchor(anchor);
        if (username !== null) {
            return username;
        }
    }

    return null;
}

function resolveResourceType(listItem: Element): ListingMetadataResourceContainer['type'] | null {
    const exactMatch = Array.from(listItem.querySelectorAll('*'))
        .map((element) => element.textContent?.trim() ?? '')
        .find((text): text is ListingMetadataResourceContainer['type'] => MODEL_RESOURCE_TYPES.has(text as ListingMetadataResourceContainer['type']));

    return exactMatch ?? null;
}

function resolveResourceContainers(): ListingMetadataResourceContainer[] {
    const resourceContainers: ListingMetadataResourceContainer[] = [];
    const seen = new Set<string>();

    for (const anchor of document.querySelectorAll(MODEL_LINK_SELECTOR)) {
        if (!(anchor instanceof HTMLAnchorElement)) {
            continue;
        }

        const url = parseRelativeOrAbsoluteUrl(anchor.getAttribute('href') ?? anchor.href);
        if (url === null) {
            continue;
        }

        const segments = url.pathname.split('/').filter((segment) => segment !== '');
        const modelId = Number(segments[1] ?? '');
        const modelVersionId = Number(url.searchParams.get('modelVersionId') ?? '');
        if (!Number.isInteger(modelId) || modelId <= 0 || !Number.isInteger(modelVersionId) || modelVersionId <= 0) {
            continue;
        }

        const listItem = anchor.closest('li');
        if (!(listItem instanceof HTMLElement)) {
            continue;
        }

        const type = resolveResourceType(listItem);
        if (type === null) {
            continue;
        }

        const key = `${type}:${modelVersionId}`;
        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        resourceContainers.push({
            type,
            modelId,
            modelVersionId,
            referrerUrl: url.toString(),
        });
    }

    return resourceContainers;
}

function resolvePostIdFromDocument(): number | null {
    for (const anchor of document.querySelectorAll('a[href]')) {
        if (!(anchor instanceof HTMLAnchorElement)) {
            continue;
        }

        const postId = parsePostIdFromHref(anchor.getAttribute('href') ?? anchor.href);
        if (postId !== null) {
            return postId;
        }
    }

    return null;
}

function resolveMenuButtons(media: MediaElement | null): HTMLButtonElement[] {
    const preferredCardRoot = media instanceof HTMLImageElement || media instanceof HTMLVideoElement
        ? resolveImageLinkForMedia(media)?.closest('.mantine-Paper-root')
        : null;
    const preferredButtonRoot = preferredCardRoot instanceof HTMLElement ? preferredCardRoot : null;

    const buttons: HTMLButtonElement[] = [];
    const seen = new Set<HTMLButtonElement>();

    const collectButtons = (root: ParentNode | null): void => {
        if (root === null) {
            return;
        }

        for (const button of root.querySelectorAll(MENU_BUTTON_SELECTOR)) {
            if (!(button instanceof HTMLButtonElement) || seen.has(button)) {
                continue;
            }

            seen.add(button);
            buttons.push(button);
        }
    };

    collectButtons(preferredButtonRoot);
    collectButtons(document);

    return buttons;
}

async function waitForPostIdFromMenu(): Promise<number | null> {
    const startedAt = Date.now();

    while (Date.now() - startedAt < 1200) {
        const postId = resolvePostIdFromDocument();
        if (postId !== null) {
            return postId;
        }

        await new Promise((resolve) => {
            window.setTimeout(resolve, 50);
        });
    }

    return null;
}

async function resolveImagePagePostId(media: MediaElement | null): Promise<number | null> {
    const existingPostId = resolvePostIdFromDocument();
    if (existingPostId !== null) {
        return existingPostId;
    }

    for (const button of resolveMenuButtons(media)) {
        const wasExpanded = button.getAttribute('aria-expanded') === 'true' || button.getAttribute('data-expanded') === 'true';
        if (!wasExpanded) {
            button.click();
        }

        const postId = await waitForPostIdFromMenu();

        if (!wasExpanded) {
            button.click();
        }

        if (postId !== null) {
            return postId;
        }
    }

    return null;
}

export async function collectCivitAiListingMetadataOverrides(
    media: MediaElement | null = null,
): Promise<ListingMetadataOverrides | null> {
    const pageKind = classifyCivitAiReactionPage();
    if (pageKind === null) {
        return null;
    }

    const overrides: ListingMetadataOverrides = {};
    const currentUrl = parseRelativeOrAbsoluteUrl(window.location.href);
    if (currentUrl !== null && pageKind === 'post-page') {
        const postId = parseIdFromPath(currentUrl, CIVITAI_POST_PATH_PATTERN);
        if (postId !== null) {
            overrides.postId = postId;
        }
    }

    if (pageKind === 'image-page' && overrides.postId === undefined) {
        const postId = await resolveImagePagePostId(media);
        if (postId !== null) {
            overrides.postId = postId;
        }
    }

    const username = resolveUsername();
    if (username !== null) {
        overrides.username = username;
    }

    const resourceContainers = resolveResourceContainers();
    if (resourceContainers.length > 0) {
        overrides.resource_containers = resourceContainers;
    }

    return Object.keys(overrides).length > 0 ? overrides : null;
}
