import { beforeEach, describe, expect, it } from 'vitest';
import {
    resolveSamePageLinkedMediaTargetUrl,
    samePageLinkedMediaTargetMatchesRules,
    shouldSkipLinkedMedia,
} from './linked-media-target';

function linkedImage(href: string): HTMLImageElement {
    const anchor = document.createElement('a');
    anchor.href = href;
    const image = document.createElement('img');
    image.src = 'https://pbs.twimg.com/media/example.jpg';
    anchor.appendChild(image);
    document.body.appendChild(anchor);

    return image;
}

describe('linked media targets', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('allows linked media that points at the current page or a child URL', () => {
        const image = linkedImage('https://x.com/SD_Tutorial/status/2056050638291947756/photo/1');
        const pageUrl = 'https://x.com/SD_Tutorial/status/2056050638291947756';

        expect(shouldSkipLinkedMedia(image, pageUrl)).toBe(false);
        expect(resolveSamePageLinkedMediaTargetUrl(image, pageUrl)).toBe('https://x.com/SD_Tutorial/status/2056050638291947756/photo/1');
    });

    it('keeps skipping linked media that points somewhere else', () => {
        const image = linkedImage('https://example.com/gallery/one');

        expect(shouldSkipLinkedMedia(image, 'https://x.com/SD_Tutorial/status/2056050638291947756')).toBe(true);
    });

    it('matches same-page linked media against the page rules', () => {
        const image = linkedImage('https://x.com/SD_Tutorial/status/2056050638291947756/video/1');

        expect(samePageLinkedMediaTargetMatchesRules(
            image,
            'https://x.com/SD_Tutorial/status/2056050638291947756',
            [{
                domain: 'x.com',
                regexes: ['.*/status/2056050638291947756.*'],
            }],
            'x.com',
        )).toBe(true);
    });
});
