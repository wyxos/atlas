type SurfaceMode = 'fullscreen' | 'list';

export function shouldExitFullscreenForMediaBarEscape(event: KeyboardEvent, surfaceMode: SurfaceMode): boolean {
    if (
        event.defaultPrevented
        || event.repeat
        || event.key !== 'Escape'
        || surfaceMode !== 'fullscreen'
    ) {
        return false;
    }

    return event.target instanceof Element && event.target.closest('[data-testid="vibe-media-bar"]') !== null;
}

export function shouldCloseContainerSheetForEscape(event: KeyboardEvent, sheetOpen: boolean): boolean {
    return sheetOpen
        && !event.defaultPrevented
        && !event.repeat
        && event.key === 'Escape';
}
