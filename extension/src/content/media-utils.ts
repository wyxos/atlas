export type MediaElement = HTMLImageElement | HTMLVideoElement;

export type MediaResolution = {
    width: number;
    height: number;
};

const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i;
const PHONE_PATTERN = /\+\d[\d\s().-]{6,}\d/;
const DEVIANT_ART_HOST_PATTERN = /(^|\.)deviantart\.com$/i;

export function isMediaElement(element: Element): element is MediaElement {
    return element instanceof HTMLImageElement || element instanceof HTMLVideoElement;
}

export function resolveMediaUrl(element: MediaElement): string | null {
    if (element instanceof HTMLImageElement) {
        return element.currentSrc || element.src || element.getAttribute('src') || null;
    }

    return element.currentSrc || element.src || element.poster || element.getAttribute('src') || null;
}

function resolveSourceUrl(source: HTMLSourceElement): string | null {
    return source.src || source.getAttribute('src') || null;
}

function isLikelyMp4Source(source: HTMLSourceElement, sourceUrl: string): boolean {
    const type = source.type?.toLowerCase() ?? '';
    if (type.includes('mp4')) {
        return true;
    }

    return /\.mp4(?:$|[?#])/i.test(sourceUrl);
}

export function resolveReactionMediaUrl(element: MediaElement): string | null {
    if (element instanceof HTMLImageElement) {
        return normalizeUrl(resolveMediaUrl(element));
    }

    const sources = Array.from(element.querySelectorAll('source'))
        .map((source) => {
            const sourceUrl = resolveSourceUrl(source);
            return sourceUrl === null ? null : { source, sourceUrl };
        })
        .filter((entry): entry is { source: HTMLSourceElement; sourceUrl: string } => entry !== null);

    const mp4Source = sources.find(({ source, sourceUrl }) => isLikelyMp4Source(source, sourceUrl));
    if (mp4Source) {
        return normalizeUrl(mp4Source.sourceUrl);
    }

    if (sources.length > 0) {
        return normalizeUrl(sources[0].sourceUrl);
    }

    return normalizeUrl(element.currentSrc || element.src || element.getAttribute('src') || null);
}

export function resolveReactionTargetUrl(element: MediaElement, pageUrl: string | null): string | null {
    const mediaUrl = resolveReactionMediaUrl(element);
    if (mediaUrl !== null) {
        return mediaUrl;
    }

    if (element instanceof HTMLVideoElement) {
        return normalizeUrl(pageUrl);
    }

    return null;
}

export function resolveIdentifiedMediaResolution(element: MediaElement): MediaResolution | null {
    if (element instanceof HTMLImageElement) {
        const width = element.naturalWidth;
        const height = element.naturalHeight;

        return width > 0 && height > 0 ? { width, height } : null;
    }

    const width = element.videoWidth;
    const height = element.videoHeight;

    return width > 0 && height > 0 ? { width, height } : null;
}

export function resolveMediaResolution(element: MediaElement): MediaResolution | null {
    const identifiedResolution = resolveIdentifiedMediaResolution(element);
    if (identifiedResolution !== null) {
        return identifiedResolution;
    }

    if (element instanceof HTMLImageElement) {
        const width = element.naturalWidth || element.width;
        const height = element.naturalHeight || element.height;

        return width > 0 && height > 0 ? { width, height } : null;
    }

    const width = element.videoWidth || element.clientWidth;
    const height = element.videoHeight || element.clientHeight;

    return width > 0 && height > 0 ? { width, height } : null;
}

export function normalizeHashAwareUrl(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return null;
    }

    if (!/^https?:\/\//i.test(trimmed)) {
        return null;
    }

    return trimmed;
}

export function normalizeUrl(value: string | null | undefined): string | null {
    const hashAware = normalizeHashAwareUrl(value);
    if (hashAware === null) {
        return null;
    }

    return hashAware.replace(/#.*$/, '');
}

export function isLikelyDomainRootUrl(url: string | null | undefined): boolean {
    if (typeof url !== 'string') {
        return false;
    }

    const trimmed = url.trim();
    if (!/^https?:\/\//i.test(trimmed)) {
        return false;
    }

    try {
        const parsed = new URL(trimmed);
        const segments = parsed.pathname.split('/').filter((segment) => segment !== '');

        return segments.length === 0 && parsed.search === '' && parsed.hash === '';
    } catch {
        return false;
    }
}

export function containsEmailOrPhoneHint(value: string | null | undefined): boolean {
    if (typeof value !== 'string') {
        return false;
    }

    const trimmed = value.trim();
    if (trimmed === '') {
        return false;
    }

    const normalized = trimmed.toLowerCase();
    if (normalized.startsWith('mailto:') || normalized.startsWith('tel:') || normalized.startsWith('sms:') || normalized.startsWith('callto:')) {
        return true;
    }

    if (normalized.includes('%40') || normalized.includes('email=') || normalized.includes('phone=') || normalized.includes('tel=')) {
        return true;
    }

    if (EMAIL_PATTERN.test(trimmed)) {
        return true;
    }

    if (PHONE_PATTERN.test(trimmed)) {
        return true;
    }

    return false;
}

export function shouldExcludeMediaOrAnchorUrl(url: string | null | undefined): boolean {
    if (url === null || url === undefined) {
        return true;
    }

    return containsEmailOrPhoneHint(url);
}

export function shouldExcludeAnchorHref(rawHref: string | null | undefined, absoluteHref: string | null): boolean {
    const trimmedRaw = typeof rawHref === 'string' ? rawHref.trim() : '';
    if (trimmedRaw === '' || trimmedRaw === '#') {
        return true;
    }

    if (containsEmailOrPhoneHint(trimmedRaw)) {
        return true;
    }

    return shouldExcludeMediaOrAnchorUrl(absoluteHref);
}

function isDeviantArtHostname(hostname: string | null | undefined): boolean {
    if (typeof hostname !== 'string') {
        return false;
    }

    return DEVIANT_ART_HOST_PATTERN.test(hostname.trim().toLowerCase());
}

function hasVisibleThumbnailImage(button: HTMLButtonElement): boolean {
    const image = button.querySelector('img');
    if (!(image instanceof HTMLImageElement)) {
        return false;
    }

    const alt = image.getAttribute('alt')?.trim() ?? '';
    const src = image.getAttribute('src')?.trim() ?? '';

    return alt !== '' && src !== '';
}

function isDeviantArtAllImagesSection(section: Element): boolean {
    const heading = Array.from(section.querySelectorAll('h1, h2, h3'))
        .find((element) => element.textContent?.trim() === 'All Images');
    if (heading === undefined) {
        return false;
    }

    const thumbnailButtons = Array.from(section.querySelectorAll('button'))
        .filter((button): button is HTMLButtonElement => button instanceof HTMLButtonElement)
        .filter((button) => hasVisibleThumbnailImage(button));

    return thumbnailButtons.length >= 2;
}

export function hasRelatedPostThumbnailsBelowMedia(
    element: MediaElement,
    hostname: string = window.location.hostname,
): boolean {
    if (!isDeviantArtHostname(hostname)) {
        return false;
    }

    const mediaRect = element.getBoundingClientRect();
    if (mediaRect.width < 1 || mediaRect.height < 1) {
        return false;
    }

    const root = element.closest('main, article, [role="main"]') ?? document.querySelector('main') ?? document.body;

    return Array.from(root.querySelectorAll('section'))
        .filter((section) => isDeviantArtAllImagesSection(section))
        .some((section) => {
            const sectionRect = section.getBoundingClientRect();

            return sectionRect.top >= mediaRect.bottom - 24 && sectionRect.top <= mediaRect.bottom + 220;
        });
}

export function collectMediaFromNode(node: Node): MediaElement[] {
    if (!(node instanceof Element)) {
        return [];
    }

    const media: MediaElement[] = [];
    if (isMediaElement(node)) {
        media.push(node);
    }

    for (const element of node.querySelectorAll('img,video')) {
        if (isMediaElement(element)) {
            media.push(element);
        }
    }

    return media;
}
