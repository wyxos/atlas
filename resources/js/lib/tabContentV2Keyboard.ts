import { shouldCloseContainerSheetForEscape, shouldCloseFileSheetForEscape, shouldExitFullscreenForMediaBarEscape } from './vibeMediaBarEscape';

type SurfaceMode = 'fullscreen' | 'list';

type TabContentV2KeydownOptions = {
    closeContainerSheet: () => void;
    closeFileSheet: () => void;
    getContainerSheetOpen: () => boolean;
    getFileSheetOpen: () => boolean;
    getSurfaceMode: () => SurfaceMode;
    updateSurfaceMode: (value: SurfaceMode) => void;
};

export function createTabContentV2KeydownHandler(options: TabContentV2KeydownOptions) {
    return (event: KeyboardEvent): void => {
        if (shouldCloseContainerSheetForEscape(event, options.getContainerSheetOpen())) {
            event.preventDefault();
            event.stopImmediatePropagation();
            options.closeContainerSheet();
            return;
        }

        if (shouldCloseFileSheetForEscape(event, options.getFileSheetOpen())) {
            event.preventDefault();
            event.stopImmediatePropagation();
            options.closeFileSheet();
            return;
        }

        if (shouldExitFullscreenForMediaBarEscape(event, options.getSurfaceMode())) {
            event.preventDefault();
            options.updateSurfaceMode('list');
        }
    };
}
