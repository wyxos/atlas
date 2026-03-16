import { beforeEach, describe, expect, it } from 'vitest';
import { clearDeviantArtBackgroundImageStyle } from './deviantart-background-image-style';

describe('clearDeviantArtBackgroundImageStyle', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('removes the inline background image on DeviantArt hosts', () => {
        const container = document.createElement('div');
        container.id = 'background-container';
        container.style.backgroundImage = 'url("https://images.example.com/background.jpg")';
        container.style.color = 'rgb(255, 0, 0)';
        document.body.appendChild(container);

        expect(clearDeviantArtBackgroundImageStyle(document, 'www.deviantart.com')).toBe(true);
        expect(container.style.getPropertyValue('background-image')).toBe('');
        expect(container.style.color).toBe('rgb(255, 0, 0)');
    });

    it('removes the empty style attribute after clearing the background image', () => {
        const container = document.createElement('div');
        container.id = 'background-container';
        container.style.backgroundImage = 'url("https://images.example.com/background.jpg")';
        document.body.appendChild(container);

        clearDeviantArtBackgroundImageStyle(document, 'www.deviantart.com');

        expect(container.getAttribute('style')).toBeNull();
    });

    it('supports mutation-observer scans when the added node is the background container', () => {
        const container = document.createElement('div');
        container.id = 'background-container';
        container.style.backgroundImage = 'url("https://images.example.com/background.jpg")';

        expect(clearDeviantArtBackgroundImageStyle(container, 'www.deviantart.com')).toBe(true);
        expect(container.style.getPropertyValue('background-image')).toBe('');
    });

    it('does nothing on non-DeviantArt hosts', () => {
        const container = document.createElement('div');
        container.id = 'background-container';
        container.style.backgroundImage = 'url("https://images.example.com/background.jpg")';
        document.body.appendChild(container);

        expect(clearDeviantArtBackgroundImageStyle(document, 'www.example.com')).toBe(false);
        expect(container.style.backgroundImage).toBe('url("https://images.example.com/background.jpg")');
    });
});
