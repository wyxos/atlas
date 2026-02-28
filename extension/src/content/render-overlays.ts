import type { ExtensionMatchResult, MediaCandidate } from './types';

const WRAPPER_ATTR = 'data-atlas-overlay-wrapper';
const BADGE_ATTR = 'data-atlas-overlay-badge';
const APPLIED_ATTR = 'data-atlas-overlay-applied';
const REACTION_BAR_ATTR = 'data-atlas-overlay-reaction-bar';
const HOVER_BOUND_ATTR = 'data-atlas-overlay-hover-bound';

type ReactionWidgetItem = {
    ariaLabel: string;
    color: string;
    svg: string;
};

const REACTION_WIDGET_ITEMS: ReactionWidgetItem[] = [
    {
        ariaLabel: 'Favorite',
        color: '#f87171',
        svg: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.54 0-3.04.99-3.57 2.36h-.86A4.26 4.26 0 0 0 8.5 3A5.5 5.5 0 0 0 3 8.5c0 2.29 1.51 4.04 3 5.5l6 6z"></path>',
    },
    {
        ariaLabel: 'Like',
        color: '#60a5fa',
        svg: '<path d="M7 10v12"></path><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.97 2.34l-1.33 8A2 2 0 0 1 18.5 22H4a2 2 0 0 1-2-2V10a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"></path>',
    },
    {
        ariaLabel: 'Dislike',
        color: '#9ca3af',
        svg: '<path d="M17 14V2"></path><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.97-2.34l1.33-8A2 2 0 0 1 5.5 2H20a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"></path>',
    },
    {
        ariaLabel: 'Funny',
        color: '#facc15',
        svg: '<circle cx="12" cy="12" r="10"></circle><path d="M8 15s1.5 2 4 2 4-2 4-2"></path><line x1="9" x2="9.01" y1="9" y2="9"></line><line x1="15" x2="15.01" y1="9" y2="9"></line>',
    },
];

function reactionColor(match: ExtensionMatchResult): string {
    if (match.reaction === 'love') {
        return '#ef4444';
    }
    if (match.reaction === 'like') {
        return '#0466c8';
    }
    if (match.reaction === 'dislike') {
        return '#6b7280';
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
    badge.style.right = '8px';
    badge.style.bottom = '8px';
    badge.style.width = '50px';
    badge.style.height = '50px';
    badge.style.padding = '0';
    badge.style.borderRadius = '9999px';
    badge.style.display = 'flex';
    badge.style.alignItems = 'center';
    badge.style.justifyContent = 'center';
    badge.style.fontSize = '24px';
    badge.style.lineHeight = '1';
    badge.style.fontWeight = '700';
    badge.style.background = 'rgba(15, 23, 42, 0.85)';
    badge.style.color = '#fff';
    badge.style.pointerEvents = 'none';
    badge.style.zIndex = '2147483647';
    wrapper.appendChild(badge);

    return badge;
}

function createReactionButton(item: ReactionWidgetItem): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('aria-label', item.ariaLabel);
    button.style.width = '34px';
    button.style.height = '34px';
    button.style.padding = '8px';
    button.style.border = 'none';
    button.style.borderRadius = '6px';
    button.style.background = 'transparent';
    button.style.color = '#ffffff';
    button.style.cursor = 'pointer';
    button.style.display = 'inline-flex';
    button.style.alignItems = 'center';
    button.style.justifyContent = 'center';
    button.style.transition = 'color 120ms ease, background 120ms ease';
    button.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${item.svg}</svg>`;

    button.addEventListener('mouseenter', () => {
        button.style.color = item.color;
    });
    button.addEventListener('mouseleave', () => {
        button.style.color = '#ffffff';
    });

    return button;
}

function ensureStandaloneReactionBar(wrapper: HTMLDivElement): HTMLDivElement {
    const existing = wrapper.querySelector<HTMLDivElement>(`[${REACTION_BAR_ATTR}="1"]`);
    if (existing) {
        return existing;
    }

    const bar = document.createElement('div');
    bar.setAttribute(REACTION_BAR_ATTR, '1');
    bar.style.position = 'absolute';
    bar.style.left = '50%';
    bar.style.bottom = '10px';
    bar.style.transform = 'translateX(-50%)';
    bar.style.display = 'flex';
    bar.style.alignItems = 'center';
    bar.style.justifyContent = 'center';
    bar.style.gap = '8px';
    bar.style.padding = '8px 12px';
    bar.style.borderRadius = '10px';
    bar.style.background = 'rgba(0, 0, 0, 0.6)';
    bar.style.backdropFilter = 'blur(8px)';
    bar.style.zIndex = '2147483647';
    bar.style.opacity = '0';
    bar.style.pointerEvents = 'none';
    bar.style.transition = 'opacity 120ms ease';

    for (const item of REACTION_WIDGET_ITEMS) {
        bar.appendChild(createReactionButton(item));
    }

    if (wrapper.getAttribute(HOVER_BOUND_ATTR) !== '1') {
        wrapper.setAttribute(HOVER_BOUND_ATTR, '1');
        wrapper.addEventListener('mouseenter', () => {
            bar.style.opacity = '1';
            bar.style.pointerEvents = 'auto';
        });
        wrapper.addEventListener('mouseleave', () => {
            bar.style.opacity = '0';
            bar.style.pointerEvents = 'none';
        });
    }

    wrapper.appendChild(bar);
    return bar;
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

        const reactionBar = wrapper.querySelector<HTMLElement>(`[${REACTION_BAR_ATTR}="1"]`);
        if (reactionBar) {
            reactionBar.remove();
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

    const isStandalone = candidate.anchorUrl === null;
    if (isStandalone) {
        ensureStandaloneReactionBar(wrapper);
    }

    candidate.element.style.opacity = '0.3';
    candidate.element.setAttribute(APPLIED_ATTR, '1');
}

export function renderMatches(candidates: MediaCandidate[], matchesById: Map<string, ExtensionMatchResult>): void {
    for (const candidate of candidates) {
        const match = matchesById.get(candidate.id);
        if (!match || !match.exists) {
            clearOverlay(candidate);
            continue;
        }

        applyOverlay(candidate, match);
    }
}
