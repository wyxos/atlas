import { describe, expect, it, vi } from 'vitest';
import { createTabContentV2KeydownHandler } from './tabContentV2Keyboard';

function createEscapeEvent(target: EventTarget = document.body): KeyboardEvent {
    const event = {
        defaultPrevented: false,
        key: 'Escape',
        preventDefault: vi.fn(() => {
            event.defaultPrevented = true;
        }),
        repeat: false,
        stopImmediatePropagation: vi.fn(),
        target,
    };

    return event as unknown as KeyboardEvent;
}

describe('createTabContentV2KeydownHandler', () => {
    it('closes the file sheet on Escape', () => {
        const closeFileSheet = vi.fn();
        const handler = createTabContentV2KeydownHandler({
            closeContainerSheet: vi.fn(),
            closeFileSheet,
            getContainerSheetOpen: () => false,
            getFileSheetOpen: () => true,
            getSurfaceMode: () => 'list',
            updateSurfaceMode: vi.fn(),
        });
        const event = createEscapeEvent();

        handler(event);

        expect(closeFileSheet).toHaveBeenCalledTimes(1);
        expect(event.defaultPrevented).toBe(true);
    });

    it('lets the container sheet consume Escape before the file sheet', () => {
        const closeContainerSheet = vi.fn();
        const closeFileSheet = vi.fn();
        const handler = createTabContentV2KeydownHandler({
            closeContainerSheet,
            closeFileSheet,
            getContainerSheetOpen: () => true,
            getFileSheetOpen: () => true,
            getSurfaceMode: () => 'list',
            updateSurfaceMode: vi.fn(),
        });

        handler(createEscapeEvent());

        expect(closeContainerSheet).toHaveBeenCalledTimes(1);
        expect(closeFileSheet).not.toHaveBeenCalled();
    });

    it('keeps media-bar Escape available when no sheet consumes it', () => {
        document.body.innerHTML = '<div data-testid="vibe-media-bar"><button data-test="target" /></div>';
        const target = document.querySelector('[data-test="target"]') as HTMLButtonElement;
        const updateSurfaceMode = vi.fn();
        const handler = createTabContentV2KeydownHandler({
            closeContainerSheet: vi.fn(),
            closeFileSheet: vi.fn(),
            getContainerSheetOpen: () => false,
            getFileSheetOpen: () => false,
            getSurfaceMode: () => 'fullscreen',
            updateSurfaceMode,
        });

        handler(createEscapeEvent(target));

        expect(updateSurfaceMode).toHaveBeenCalledWith('list');
    });
});
