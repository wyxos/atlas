const CTA_TEXT = 'Open in Atlas';
const CTA_PENDING_TEXT = 'Opening...';
const CTA_SUCCESS_TEXT = 'Opened';
const CTA_FAILURE_TEXT = 'Failed';
const CTA_SELECTOR = '[data-atlas-deviantart-artist-browse-cta]';
const noop = (): void => {};

type DeviantArtUsernameReference = {
    username: string;
    key: string;
};

type RuntimeOpenResponse = {
    ok?: boolean;
};

let isInstalled = false;

function isDeviantArtHostname(hostname: string = window.location.hostname): boolean {
    const normalized = hostname.toLowerCase().trim();

    return normalized === 'deviantart.com' || normalized.endsWith('.deviantart.com');
}

function isReservedPathSegment(value: string): boolean {
    return [
        'about',
        'art',
        'browse',
        'daily-deviations',
        'gallery',
        'morelikethis',
        'notifications',
        'prints',
        'search',
        'settings',
        'shop',
        'watch',
    ].includes(value.toLowerCase());
}

function parseUsernameSegment(value: string | null | undefined): string | null {
    if (typeof value !== 'string') {
        return null;
    }

    let username: string;
    try {
        username = decodeURIComponent(value).trim();
    } catch {
        return null;
    }

    if (
        username === ''
        || username.length > 255
        || isReservedPathSegment(username)
        || /^[a-z0-9_-]+$/i.test(username) === false
    ) {
        return null;
    }

    return username;
}

function parseUsernameReferenceFromUrl(href: string = window.location.href): DeviantArtUsernameReference | null {
    let url: URL;

    try {
        url = new URL(href, window.location.href);
    } catch {
        return null;
    }

    if (!isDeviantArtHostname(url.hostname)) {
        return null;
    }

    const [rawUsername] = url.pathname.split('/').filter((segment) => segment !== '');
    const username = parseUsernameSegment(rawUsername);
    if (username === null) {
        return null;
    }

    return {
        username,
        key: username.toLowerCase(),
    };
}

function isDeviationPage(href: string = window.location.href): boolean {
    try {
        const url = new URL(href, window.location.href);
        const segments = url.pathname.split('/').filter((segment) => segment !== '');

        return segments[1] === 'art';
    } catch {
        return false;
    }
}

function isProfileHeaderAnchor(anchor: HTMLAnchorElement): boolean {
    return anchor.closest('h1') !== null;
}

function normalizeText(value: string | null | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

function normalizeCollapsedText(value: string | null | undefined): string {
    return normalizeText(value).replace(/\s+/g, ' ');
}

function resolveAnchorUsername(anchor: HTMLAnchorElement): DeviantArtUsernameReference | null {
    const href = anchor.getAttribute('href');

    return href === null ? null : parseUsernameReferenceFromUrl(href);
}

function isDeviationBylineAnchor(anchor: HTMLAnchorElement, reference: DeviantArtUsernameReference): boolean {
    let current = anchor.parentElement;
    let depth = 0;

    while (current instanceof HTMLElement && depth < 5) {
        const text = normalizeCollapsedText(current.textContent);
        if (text.startsWith(`by ${reference.key}`) || text.startsWith(`by${reference.key}`)) {
            return true;
        }

        current = current.parentElement;
        depth += 1;
    }

    return false;
}

function isCandidateArtistAnchor(anchor: HTMLAnchorElement, reference: DeviantArtUsernameReference): boolean {
    const anchorReference = resolveAnchorUsername(anchor);
    if (anchorReference === null || anchorReference.key !== reference.key) {
        return false;
    }

    if (normalizeText(anchor.textContent) !== reference.key) {
        return false;
    }

    if (isProfileHeaderAnchor(anchor)) {
        return true;
    }

    return isDeviationPage() && isDeviationBylineAnchor(anchor, reference);
}

function setButtonState(
    button: HTMLButtonElement,
    label: string,
    disabled: boolean,
): void {
    button.textContent = label;
    button.disabled = disabled;
    button.style.opacity = disabled ? '0.72' : '1';
    button.style.cursor = disabled ? 'default' : 'pointer';
}

function requestOpenDeviantArtUsernameTab(reference: DeviantArtUsernameReference): Promise<RuntimeOpenResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage({
                type: 'ATLAS_OPEN_DEVIANTART_USERNAME_TAB',
                username: reference.username,
                sourceHostname: window.location.hostname,
                sourceUrl: window.location.href,
            }, (response: unknown) => {
                if (chrome.runtime.lastError) {
                    resolve(null);
                    return;
                }

                resolve(response && typeof response === 'object' ? response as RuntimeOpenResponse : null);
            });
        } catch {
            resolve(null);
        }
    });
}

function createCtaButton(reference: DeviantArtUsernameReference): HTMLButtonElement {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.atlasDeviantartArtistBrowseCta = reference.key;
    button.title = 'Open this DeviantArt artist in Atlas';
    button.style.cssText = [
        'appearance:none',
        'display:inline-flex',
        'align-items:center',
        'justify-content:center',
        'min-height:24px',
        'margin-left:8px',
        'padding:4px 10px',
        'border-radius:999px',
        'border:1px solid rgba(34, 139, 230, 0.3)',
        'background:rgba(34, 139, 230, 0.08)',
        'color:#228be6',
        'font-size:12px',
        'font-weight:600',
        'line-height:1.2',
        'white-space:nowrap',
        'vertical-align:middle',
    ].join(';');
    setButtonState(button, CTA_TEXT, false);

    button.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();

        const currentReference = parseUsernameReferenceFromUrl() ?? reference;

        setButtonState(button, CTA_PENDING_TEXT, true);

        void requestOpenDeviantArtUsernameTab(currentReference)
            .then((response) => {
                setButtonState(button, response?.ok === true ? CTA_SUCCESS_TEXT : CTA_FAILURE_TEXT, true);
            })
            .catch(() => {
                setButtonState(button, CTA_FAILURE_TEXT, true);
            })
            .finally(() => {
                window.setTimeout(() => {
                    if (button.isConnected) {
                        setButtonState(button, CTA_TEXT, false);
                    }
                }, 1500);
            });
    });

    return button;
}

function applyArtistBrowseCta(anchor: HTMLAnchorElement, reference: DeviantArtUsernameReference): void {
    const existingButton = anchor.parentElement?.querySelector(CTA_SELECTOR);
    if (existingButton instanceof HTMLButtonElement) {
        if (existingButton.dataset.atlasDeviantartArtistBrowseCta === reference.key) {
            return;
        }

        existingButton.remove();
    }

    anchor.insertAdjacentElement('afterend', createCtaButton(reference));
}

function syncArtistBrowseCtas(root: ParentNode): void {
    const reference = parseUsernameReferenceFromUrl();
    if (reference === null) {
        return;
    }

    const directAnchors = root instanceof HTMLAnchorElement ? [root] : [];
    for (const anchor of [...directAnchors, ...Array.from(root.querySelectorAll('a[href]'))]) {
        if (!(anchor instanceof HTMLAnchorElement) || !isCandidateArtistAnchor(anchor, reference)) {
            continue;
        }

        applyArtistBrowseCta(anchor, reference);
    }
}

function syncMutationTarget(mutation: MutationRecord): void {
    if (mutation.target instanceof Element) {
        syncArtistBrowseCtas(mutation.target);
        return;
    }

    if (mutation.target.parentElement instanceof HTMLElement) {
        syncArtistBrowseCtas(mutation.target.parentElement);
    }
}

export function installDeviantArtArtistBrowseCtas(): () => void {
    if (isInstalled || !isDeviantArtHostname()) {
        return noop;
    }

    isInstalled = true;
    syncArtistBrowseCtas(document);

    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData') {
                syncMutationTarget(mutation);
                continue;
            }

            if (mutation.type !== 'childList') {
                continue;
            }

            syncMutationTarget(mutation);

            for (const addedNode of mutation.addedNodes) {
                if (addedNode instanceof Element) {
                    syncArtistBrowseCtas(addedNode);
                }
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        characterData: true,
        subtree: true,
    });

    return () => {
        observer.disconnect();
        isInstalled = false;
    };
}
