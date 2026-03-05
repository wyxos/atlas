export type MediaElement = HTMLImageElement | HTMLVideoElement;

export type MediaResolution = {
    width: number;
    height: number;
};

const EMAIL_PATTERN = /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i;
const PHONE_PATTERN = /\+\d[\d\s().-]{6,}\d/;
const THUMBNAIL_LABEL_BLOCKLIST = [
    'add to',
    'favourite',
    'favorite',
    'comment',
    'watch',
    'download',
    'award',
    'private collection',
    'more actions',
    'navigation menu',
    'submit',
    'log in',
    'join',
    'folder select',
];

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

function normalizeControlLabel(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim().toLowerCase();
    return trimmed === '' ? null : trimmed;
}

function hasBlockedThumbnailLabel(label: string | null): boolean {
    if (label === null) {
        return false;
    }

    return THUMBNAIL_LABEL_BLOCKLIST.some((blocked) => label.includes(blocked));
}

type ThumbnailControlCandidate = {
    top: number;
};

function thumbnailControlCandidate(
    element: Element,
    mediaRect: DOMRect,
): ThumbnailControlCandidate | null {
    if (element.closest('[data-atlas-media-red-badge="1"]')) {
        return null;
    }

    const rect = element.getBoundingClientRect();
    if (rect.width < 18 || rect.height < 18 || rect.width > 120 || rect.height > 120) {
        return null;
    }

    if (rect.top < mediaRect.bottom - 16 || rect.top > mediaRect.bottom + 220) {
        return null;
    }

    if (rect.left < mediaRect.left - 140 || rect.right > mediaRect.right + 140) {
        return null;
    }

    const ariaLabel = normalizeControlLabel(element.getAttribute('aria-label'));
    const textLabel = normalizeControlLabel(element.textContent);
    const label = ariaLabel ?? textLabel;
    const hasNestedImage = element.querySelector('img') !== null;
    if (label === null && !hasNestedImage) {
        return null;
    }

    if (hasBlockedThumbnailLabel(label)) {
        return null;
    }

    return {
        top: rect.top,
    };
}

export function hasRelatedPostThumbnailsBelowMedia(element: MediaElement): boolean {
    const mediaRect = element.getBoundingClientRect();
    if (mediaRect.width < 1 || mediaRect.height < 1) {
        return false;
    }

    const root = element.closest('article, main, [role="main"], [data-testid*="post"], [data-hook*="post"]') ?? document.body;
    const controls = Array.from(root.querySelectorAll('button, a[href], [role="button"]'))
        .filter((control) => control !== element && !control.contains(element));

    if (controls.length < 2) {
        return false;
    }

    const candidates = controls
        .map((control) => thumbnailControlCandidate(control, mediaRect))
        .filter((candidate): candidate is ThumbnailControlCandidate => candidate !== null)
        .sort((left, right) => left.top - right.top);

    if (candidates.length < 2) {
        return false;
    }

    const rowTolerance = 14;
    const rows: Array<{ top: number; count: number }> = [];
    for (const candidate of candidates) {
        const existingRow = rows.find((row) => Math.abs(row.top - candidate.top) <= rowTolerance);
        if (existingRow) {
            const nextCount = existingRow.count + 1;
            existingRow.top = ((existingRow.top * existingRow.count) + candidate.top) / nextCount;
            existingRow.count = nextCount;
            continue;
        }

        rows.push({
            top: candidate.top,
            count: 1,
        });
    }

    return rows.some((row) => row.count >= 2);
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
