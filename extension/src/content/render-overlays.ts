import type { ExtensionMatchResult, MediaCandidate } from './types';

const WRAPPER_ATTR = 'data-atlas-overlay-wrapper';
const BADGE_ATTR = 'data-atlas-overlay-badge';
const APPLIED_ATTR = 'data-atlas-overlay-applied';

function reactionColor(match: ExtensionMatchResult): string {
    if (match.reaction === 'love') {
        return '#ef4444';
    }
    if (match.reaction === 'like') {
        return '#3b82f6';
    }
    if (match.reaction === 'dislike') {
        return '#f97316';
    }
    if (match.reaction === 'funny') {
        return '#eab308';
    }
    if (match.blacklisted_at) {
        return '#dc2626';
    }
    if (match.downloaded_at) {
        return '#10b981';
    }

    return '#64748b';
}

function reactionIcon(match: ExtensionMatchResult): string {
    if (match.reaction === 'love') {
        return '♥';
    }
    if (match.reaction === 'like') {
        return '👍';
    }
    if (match.reaction === 'dislike') {
        return '👎';
    }
    if (match.reaction === 'funny') {
        return '😄';
    }
    if (match.blacklisted_at) {
        return '⛔';
    }
    if (match.downloaded_at) {
        return '⬇';
    }

    return '●';
}

function buildTitle(match: ExtensionMatchResult): string {
    const flags: string[] = ['Exists'];

    if (match.reaction) {
        flags.push(`Reaction: ${match.reaction}`);
    }
    if (match.downloaded_at) {
        flags.push(`Downloaded: ${match.downloaded_at}`);
    }
    if (match.blacklisted_at) {
        flags.push(`Blacklisted: ${match.blacklisted_at}`);
    }

    return flags.join(' | ');
}

function ensureWrapper(media: HTMLImageElement | HTMLVideoElement): HTMLDivElement {
    const parent = media.parentElement;
    if (parent instanceof HTMLDivElement && parent.getAttribute(WRAPPER_ATTR) === '1') {
        return parent;
    }

    const wrapper = document.createElement('div');
    wrapper.setAttribute(WRAPPER_ATTR, '1');
    wrapper.style.position = 'relative';
    wrapper.style.display = 'inline-block';
    wrapper.style.maxWidth = '100%';
    wrapper.style.verticalAlign = 'top';

    if (parent) {
        parent.insertBefore(wrapper, media);
        wrapper.appendChild(media);
    }

    return wrapper;
}

function ensureBadge(wrapper: HTMLDivElement): HTMLDivElement {
    const existing = wrapper.querySelector<HTMLDivElement>(`[${BADGE_ATTR}="1"]`);
    if (existing) {
        return existing;
    }

    const badge = document.createElement('div');
    badge.setAttribute(BADGE_ATTR, '1');
    badge.style.position = 'absolute';
    badge.style.left = '6px';
    badge.style.bottom = '6px';
    badge.style.minWidth = '18px';
    badge.style.height = '18px';
    badge.style.padding = '0 4px';
    badge.style.borderRadius = '9999px';
    badge.style.display = 'flex';
    badge.style.alignItems = 'center';
    badge.style.justifyContent = 'center';
    badge.style.fontSize = '12px';
    badge.style.lineHeight = '1';
    badge.style.fontWeight = '700';
    badge.style.background = 'rgba(15, 23, 42, 0.85)';
    badge.style.color = '#fff';
    badge.style.pointerEvents = 'none';
    badge.style.zIndex = '2147483647';
    wrapper.appendChild(badge);

    return badge;
}

function clearOverlay(candidate: MediaCandidate): void {
    if (candidate.element.getAttribute(APPLIED_ATTR) !== '1') {
        return;
    }

    candidate.element.style.opacity = '';
    candidate.element.removeAttribute(APPLIED_ATTR);

    const wrapper = candidate.element.parentElement;
    if (wrapper instanceof HTMLDivElement && wrapper.getAttribute(WRAPPER_ATTR) === '1') {
        wrapper.style.boxShadow = '';
        const badge = wrapper.querySelector<HTMLElement>(`[${BADGE_ATTR}="1"]`);
        if (badge) {
            badge.remove();
        }
    }
}

function applyOverlay(candidate: MediaCandidate, match: ExtensionMatchResult): void {
    const wrapper = ensureWrapper(candidate.element);
    const color = reactionColor(match);

    wrapper.style.boxShadow = `inset 0 0 0 4px ${color}`;

    const badge = ensureBadge(wrapper);
    badge.textContent = reactionIcon(match);
    badge.title = buildTitle(match);

    candidate.element.style.opacity = '0.3';
    candidate.element.setAttribute(APPLIED_ATTR, '1');
}

export function renderMatches(candidates: MediaCandidate[], matchesById: Map<string, ExtensionMatchResult>): void {
    for (const candidate of candidates) {
        const match = matchesById.get(candidate.payload.id);
        if (!match || !match.exists) {
            clearOverlay(candidate);
            continue;
        }

        applyOverlay(candidate, match);
    }
}
