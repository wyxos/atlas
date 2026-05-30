type ReferrerShortcutRuntime = {
    handleAltRightClick: (event: MouseEvent) => boolean;
};

export function createAnchorReferrerShortcutListener(
    runtime: ReferrerShortcutRuntime,
    isActive: () => boolean,
): (event: MouseEvent) => void {
    return (event: MouseEvent): void => {
        if (!isActive() || !event.altKey || event.button !== 2 || !runtime.handleAltRightClick(event)) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();
    };
}
