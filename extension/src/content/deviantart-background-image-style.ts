const DEVIANT_ART_HOST_PATTERN = /(^|\.)deviantart\.com$/i;

function isDeviantArtHostname(hostname: string | null | undefined): boolean {
    if (typeof hostname !== 'string') {
        return false;
    }

    return DEVIANT_ART_HOST_PATTERN.test(hostname.trim().toLowerCase());
}

function resolveBackgroundContainer(root: Node): HTMLDivElement | null {
    if (root instanceof HTMLDivElement && root.id === 'background-container') {
        return root;
    }

    if (!(root instanceof Document) && !(root instanceof DocumentFragment) && !(root instanceof Element)) {
        return null;
    }

    const container = root.querySelector('#background-container');
    return container instanceof HTMLDivElement ? container : null;
}

export function clearDeviantArtBackgroundImageStyle(
    root: Node = document,
    hostname: string = window.location.hostname,
): boolean {
    if (!isDeviantArtHostname(hostname)) {
        return false;
    }

    const container = resolveBackgroundContainer(root);
    if (container === null || container.style.getPropertyValue('background-image') === '') {
        return false;
    }

    container.style.removeProperty('background-image');

    if (container.getAttribute('style')?.trim() === '') {
        container.removeAttribute('style');
    }

    return true;
}
