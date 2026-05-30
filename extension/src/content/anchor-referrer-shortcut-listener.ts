type ReferrerShortcutRuntime = {
    handleAltRightClick: (event: MouseEvent) => boolean;
};

function isAltRightClickShortcut(event: MouseEvent): boolean {
    return event.type === 'mousedown' && event.altKey && event.button === 2;
}

export function createAnchorReferrerShortcutListener(
    runtime: ReferrerShortcutRuntime,
    isActive: () => boolean,
): (event: MouseEvent) => void {
    let suppressContextMenuUntil = 0;

    return (event: MouseEvent): void => {
        if (event.type === 'contextmenu' && Date.now() < suppressContextMenuUntil) {
            event.preventDefault();
            event.stopPropagation();

            return;
        }

        if (!isActive() || !isAltRightClickShortcut(event) || !runtime.handleAltRightClick(event)) {
            return;
        }

        suppressContextMenuUntil = Date.now() + 750;
        event.preventDefault();
        event.stopPropagation();
    };
}
