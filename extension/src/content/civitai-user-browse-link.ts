import { isCivitAiHostname as isSupportedCivitAiHostname } from '../civitai-domains';

const USER_OPEN_TITLE = 'Open this CivitAI user in Atlas';
const USER_PENDING_TITLE = 'Opening this CivitAI user in Atlas...';
const USER_SUCCESS_TITLE = 'Opened this CivitAI user in Atlas';
const USER_FAILURE_TITLE = 'Failed to open this CivitAI user in Atlas';

type CivitAiUsernameReference = {
    username: string;
    key: string;
};

type RuntimeOpenResponse = {
    ok?: boolean;
};

const usernameBrowseLinkListeners = new WeakSet<HTMLAnchorElement>();
let isInstalled = false;

function isCivitAiHostname(hostname: string = window.location.hostname): boolean {
    return isSupportedCivitAiHostname(hostname);
}

function parseUsernameReferenceFromUrl(href: string = window.location.href): CivitAiUsernameReference | null {
    let url: URL;

    try {
        url = new URL(href);
    } catch {
        return null;
    }

    if (!isCivitAiHostname(url.hostname)) {
        return null;
    }

    const segments = url.pathname.split('/').filter((segment) => segment !== '');
    if (segments[0] !== 'user') {
        return null;
    }

    const rawUsername = segments[1] ?? '';
    if (rawUsername === '') {
        return null;
    }

    let username: string;
    try {
        username = decodeURIComponent(rawUsername).trim();
    } catch {
        return null;
    }

    if (username === '' || username.length > 255 || /\s/.test(username)) {
        return null;
    }

    return {
        username,
        key: username.toLowerCase(),
    };
}

function requestOpenCivitAiUsernameTab(reference: CivitAiUsernameReference): Promise<RuntimeOpenResponse | null> {
    if (typeof chrome === 'undefined' || !chrome.runtime || typeof chrome.runtime.sendMessage !== 'function') {
        return Promise.resolve(null);
    }

    return new Promise((resolve) => {
        try {
            chrome.runtime.sendMessage({
                type: 'ATLAS_OPEN_CIVITAI_USERNAME_TAB',
                username: reference.username,
            }, (response: unknown) => {
                if (chrome.runtime.lastError) {
                    console.error('[Atlas Extension] CivitAI user browse request failed', chrome.runtime.lastError.message);
                    resolve(null);
                    return;
                }

                resolve(response && typeof response === 'object' ? response as RuntimeOpenResponse : null);
            });
        } catch (error) {
            console.error('[Atlas Extension] CivitAI user browse request failed', error);
            resolve(null);
        }
    });
}

function isJoinedTextElement(element: Element): boolean {
    return /^Joined\s+/i.test(element.textContent?.trim() ?? '');
}

function resolveUsernameTextElement(stack: Element, reference: CivitAiUsernameReference): HTMLElement | null {
    if (!Array.from(stack.querySelectorAll('.mantine-Text-root')).some(isJoinedTextElement)) {
        return null;
    }

    const candidates = Array.from(stack.querySelectorAll('.mantine-Text-root'))
        .filter((element): element is HTMLElement => element instanceof HTMLElement)
        .filter((element) => element.textContent?.trim().toLowerCase() === reference.key);

    return candidates.find((element) => element.getAttribute('data-size') === 'xl') ?? candidates[0] ?? null;
}

function setUserBrowseLinkState(anchor: HTMLAnchorElement, state: 'idle' | 'pending' | 'success' | 'failure'): void {
    const label = anchor.querySelector('.mantine-Text-root');
    anchor.dataset.atlasCivitaiUserBrowseState = state;
    anchor.title = {
        idle: USER_OPEN_TITLE,
        pending: USER_PENDING_TITLE,
        success: USER_SUCCESS_TITLE,
        failure: USER_FAILURE_TITLE,
    }[state];
    anchor.style.cursor = state === 'pending' ? 'default' : 'pointer';
    anchor.style.opacity = state === 'pending' ? '0.72' : '';

    if (label instanceof HTMLElement) {
        label.style.cursor = anchor.style.cursor;
        label.style.textDecoration = 'underline rgba(34, 139, 230, 0.65)';
        label.style.textUnderlineOffset = '3px';
    }
}

function clearLegacyTextLinkAttributes(element: HTMLElement): void {
    element.removeAttribute('data-atlas-civitai-user-browse-link');
    element.removeAttribute('data-atlas-civitai-user-browse-state');
    element.removeAttribute('role');
    element.removeAttribute('tabindex');
    element.removeAttribute('title');
}

function resolveUserBrowseAnchor(
    usernameText: HTMLElement,
    reference: CivitAiUsernameReference,
): HTMLAnchorElement | null {
    const existingAnchor = usernameText.closest('a[data-atlas-civitai-user-browse-link]');
    if (existingAnchor instanceof HTMLAnchorElement) {
        return existingAnchor;
    }

    if (usernameText.closest('a[href],button') !== null) {
        return null;
    }

    const parent = usernameText.parentElement;
    if (parent === null) {
        return null;
    }

    const anchor = document.createElement('a');
    anchor.href = '#';
    anchor.dataset.atlasCivitaiUserBrowseLink = reference.key;
    anchor.style.color = 'inherit';
    anchor.style.display = 'inline-flex';
    anchor.style.textDecoration = 'none';

    clearLegacyTextLinkAttributes(usernameText);
    parent.insertBefore(anchor, usernameText);
    anchor.appendChild(usernameText);

    return anchor;
}

function openUsernameBrowseLink(anchor: HTMLAnchorElement, reference: CivitAiUsernameReference): void {
    if (anchor.dataset.atlasCivitaiUserBrowseState === 'pending') {
        return;
    }

    setUserBrowseLinkState(anchor, 'pending');

    void requestOpenCivitAiUsernameTab(reference)
        .then((response) => {
            setUserBrowseLinkState(anchor, response?.ok === true ? 'success' : 'failure');
        })
        .catch(() => {
            setUserBrowseLinkState(anchor, 'failure');
        })
        .finally(() => {
            window.setTimeout(() => {
                if (anchor.isConnected) {
                    setUserBrowseLinkState(anchor, 'idle');
                }
            }, 1500);
        });
}

function applyUserBrowseLink(usernameText: HTMLElement, reference: CivitAiUsernameReference): void {
    const anchor = resolveUserBrowseAnchor(usernameText, reference);
    if (anchor === null) {
        return;
    }

    anchor.href = '#';
    anchor.dataset.atlasCivitaiUserBrowseLink = reference.key;
    setUserBrowseLinkState(anchor, 'idle');

    if (usernameBrowseLinkListeners.has(anchor)) {
        return;
    }

    usernameBrowseLinkListeners.add(anchor);
    anchor.addEventListener('click', (event) => {
        const currentReference = parseUsernameReferenceFromUrl();
        if (currentReference === null) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
        openUsernameBrowseLink(anchor, currentReference);
    });
}

function syncUserBrowseLinks(root: ParentNode): void {
    const reference = parseUsernameReferenceFromUrl();
    if (reference === null) {
        return;
    }

    const directStacks = root instanceof HTMLElement && root.classList.contains('mantine-Stack-root') ? [root] : [];
    for (const stack of [...directStacks, ...Array.from(root.querySelectorAll('.mantine-Stack-root'))]) {
        const usernameText = resolveUsernameTextElement(stack, reference);
        if (usernameText !== null) {
            applyUserBrowseLink(usernameText, reference);
        }
    }
}

function syncMutationTarget(mutation: MutationRecord): void {
    if (mutation.target instanceof Element) {
        syncUserBrowseLinks(mutation.target);
        return;
    }

    if (mutation.target.parentElement instanceof HTMLElement) {
        syncUserBrowseLinks(mutation.target.parentElement);
    }
}

export function installCivitAiUserBrowseLinks(): void {
    if (isInstalled || !isCivitAiHostname()) {
        return;
    }

    isInstalled = true;
    syncUserBrowseLinks(document);

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
                    syncUserBrowseLinks(addedNode);
                }
            }
        }
    });

    observer.observe(document.documentElement, {
        childList: true,
        characterData: true,
        subtree: true,
    });
}
