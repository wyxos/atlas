import { beforeEach, describe, expect, it } from 'vitest';
import {
    resolveSameStatusLinkedMediaTargetUrl,
    sameStatusLinkedMediaTargetMatchesRules,
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

    it('allows X status media links that point at the current status', () => {
        const image = linkedImage('https://x.com/SD_Tutorial/status/2056050638291947756/photo/1');
        const pageUrl = 'https://x.com/SD_Tutorial/status/2056050638291947756';

        expect(shouldSkipLinkedMedia(image, pageUrl)).toBe(false);
        expect(resolveSameStatusLinkedMediaTargetUrl(image, pageUrl)).toBe('https://x.com/SD_Tutorial/status/2056050638291947756/photo/1');
    });

    it('keeps skipping linked media that points somewhere else', () => {
        const image = linkedImage('https://example.com/gallery/one');

        expect(shouldSkipLinkedMedia(image, 'https://x.com/SD_Tutorial/status/2056050638291947756')).toBe(true);
    });

    it('matches same-status X links against the page rules', () => {
        const image = linkedImage('https://twitter.com/SD_Tutorial/status/2056050638291947756/video/1');

        expect(sameStatusLinkedMediaTargetMatchesRules(
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
