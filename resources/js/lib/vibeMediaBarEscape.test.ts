import { describe, expect, it } from 'vitest';
import { shouldCloseContainerSheetForEscape, shouldCloseFileSheetForEscape, shouldExitFullscreenForMediaBarEscape } from './vibeMediaBarEscape';

function createEvent(target: EventTarget, overrides: Partial<KeyboardEvent> = {}): KeyboardEvent {
    return {
        defaultPrevented: false,
        key: 'Escape',
        repeat: false,
        target,
        ...overrides,
    } as KeyboardEvent;
}

describe('shouldExitFullscreenForMediaBarEscape', () => {
    it('matches escape from Vibe media bar controls while fullscreen', () => {
        document.body.innerHTML = '<div data-testid="vibe-media-bar"><input data-testid="seek" /></div>';

        expect(shouldExitFullscreenForMediaBarEscape(
            createEvent(document.querySelector('[data-testid="seek"]') as HTMLInputElement),
            'fullscreen',
        )).toBe(true);
    });

    it('ignores non-fullscreen mode and events outside the Vibe media bar', () => {
        const input = document.createElement('input');

        expect(shouldExitFullscreenForMediaBarEscape(createEvent(input), 'list')).toBe(false);
        expect(shouldExitFullscreenForMediaBarEscape(createEvent(document.body), 'fullscreen')).toBe(false);
    });
});

describe('shouldCloseContainerSheetForEscape', () => {
    it('matches a first Escape keydown only while the container sheet is open', () => {
        expect(shouldCloseContainerSheetForEscape(createEvent(document.body), true)).toBe(true);
        expect(shouldCloseContainerSheetForEscape(createEvent(document.body), false)).toBe(false);
        expect(shouldCloseContainerSheetForEscape(createEvent(document.body, { repeat: true }), true)).toBe(false);
        expect(shouldCloseContainerSheetForEscape(createEvent(document.body, { key: 'Enter' }), true)).toBe(false);
    });
});

describe('shouldCloseFileSheetForEscape', () => {
    it('matches a first Escape keydown only while the file sheet is open', () => {
        expect(shouldCloseFileSheetForEscape(createEvent(document.body), true)).toBe(true);
        expect(shouldCloseFileSheetForEscape(createEvent(document.body), false)).toBe(false);
        expect(shouldCloseFileSheetForEscape(createEvent(document.body, { repeat: true }), true)).toBe(false);
        expect(shouldCloseFileSheetForEscape(createEvent(document.body, { key: 'Enter' }), true)).toBe(false);
    });
});
