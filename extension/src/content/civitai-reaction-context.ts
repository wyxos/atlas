import type { MediaElement } from './media-utils';
import type { ListingMetadataOverrides, ListingMetadataResourceContainer } from './reaction-batch-types';
import {
    createCivitAiLinkSelector,
    isCivitAiHostname,
    isCivitAiMediaHostname,
} from '../civitai-domains';

const CIVITAI_POST_PATH_PATTERN = /^\/posts\/(\d+)(?:\/|$)/i;
const CIVITAI_IMAGE_PATH_PATTERN = /^\/images\/(\d+)(?:\/|$)/i;
const USER_LINK_SELECTOR = createCivitAiLinkSelector('/user/');
const MODEL_LINK_SELECTOR = createCivitAiLinkSelector('/models/');
const CREATOR_CARD_SELECTOR = '[class*="CreatorCard_profileDetailsContainer"], [class*="CreatorCard_profileDetails"]';
const MENU_BUTTON_SELECTOR = 'button[aria-haspopup="menu"]';
const MODEL_RESOURCE_TYPES = new Set<ListingMetadataResourceContainer['type']>(['Checkpoint', 'LoRA']);
const CIVITAI_VIDEO_EXTENSIONS = new Set(['mp4', 'm4v', 'mov', 'webm']);

export type CivitAiReactionPageKind = 'post-page' | 'image-page';

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

function isImagePageLink(anchor: HTMLAnchorElement): boolean {
    return parseImageIdFromHref(anchor.getAttribute('href') ?? anchor.href) !== null;
}

function resolveImageLinkForMedia(media: MediaElement): HTMLAnchorElement | null {
    const anchor = media.closest('a');

    return anchor instanceof HTMLAnchorElement && isImagePageLink(anchor) ? anchor : null;
}

function resolveImageIdForMedia(media: MediaElement | null, href: string): number | null {
    if (media !== null) {
        const imageLink = resolveImageLinkForMedia(media);
        const linkedImageId = parseImageIdFromHref(imageLink?.getAttribute('href') ?? imageLink?.href);
        if (linkedImageId !== null) {
            return linkedImageId;
        }
    }

    const currentUrl = parseRelativeOrAbsoluteUrl(href);

    return currentUrl === null ? null : parseIdFromPath(currentUrl, CIVITAI_IMAGE_PATH_PATTERN);
}

export function classifyCivitAiReactionPage(href: string = window.location.href): CivitAiReactionPageKind | null {
    const url = parseRelativeOrAbsoluteUrl(href);
    if (url === null || !isCivitAiHostname(url.hostname)) {
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

function resolveImageIdFromCandidateUrls(candidateUrls: Array<string | null | undefined>): number | null {
    for (const candidateUrl of candidateUrls) {
        const parsed = parseRelativeOrAbsoluteUrl(candidateUrl);
        if (parsed === null) {
            continue;
        }

        const imageId = parseIdFromPath(parsed, CIVITAI_IMAGE_PATH_PATTERN);
        if (imageId !== null) {
            return imageId;
        }
    }

    return null;
}

export function canonicalizeCivitAiMediaUrl(
    mediaUrl: string | null | undefined,
    options: {
        media?: MediaElement | null;
        candidatePageUrls?: Array<string | null | undefined>;
    } | MediaElement | null = {},
): string | null {
    if (typeof mediaUrl !== 'string') {
        return null;
    }

    const trimmed = mediaUrl.trim();
    if (trimmed === '') {
        return null;
    }

    const parsed = parseRelativeOrAbsoluteUrl(trimmed);
    if (parsed === null || !isCivitAiMediaHostname(parsed.hostname)) {
        return trimmed;
    }

    const segments = parsed.pathname.split('/').filter((segment) => segment !== '');
    if (segments.length < 4) {
        return trimmed;
    }

    const token = segments[0]?.trim() ?? '';
    const guid = segments[1]?.trim() ?? '';
    const filename = segments[segments.length - 1]?.trim() ?? '';
    if (token === '' || guid === '' || filename === '' || !filename.includes('.')) {
        return trimmed;
    }

    const extension = filename.split('.').pop()?.trim().toLowerCase() ?? '';
    if (extension === '') {
        return trimmed;
    }

    const normalizedOptions = options instanceof HTMLImageElement || options instanceof HTMLVideoElement
        ? {
            media: options,
            candidatePageUrls: [window.location.href],
        }
        : options ?? {};
    const media = normalizedOptions.media ?? null;
    const candidatePageUrls = normalizedOptions.candidatePageUrls ?? [window.location.href];
    const isVideo = media instanceof HTMLVideoElement || CIVITAI_VIDEO_EXTENSIONS.has(extension);
    const imageId = isVideo
        ? resolveImageIdForMedia(media, candidatePageUrls[0] ?? window.location.href)
            ?? resolveImageIdFromCandidateUrls(candidatePageUrls)
        : null;
    if (isVideo && imageId === null) {
        return trimmed;
    }

    const transform = isVideo ? 'transcode=true,original=true,quality=90' : 'original=true';
    const canonicalFilename = isVideo ? `${imageId}.${extension}` : `${guid}.${extension}`;

    return `${parsed.protocol}//${parsed.host}/${token}/${guid}/${transform}/${canonicalFilename}`;
}

export const canonicalizeCivitAiBadgeCheckUrl = canonicalizeCivitAiMediaUrl;
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
