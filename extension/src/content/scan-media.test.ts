import { beforeEach, describe, expect, it } from 'vitest';
import { scanMediaCandidates } from './scan-media';

function visibleRect(): DOMRect {
    return {
        x: 0,
        y: 0,
        width: 200,
        height: 200,
        top: 0,
        right: 200,
        bottom: 200,
        left: 0,
        toJSON: () => ({}),
    } as DOMRect;
}

function markVisible(element: Element): void {
    Object.defineProperty(element, 'getBoundingClientRect', {
        configurable: true,
        value: () => visibleRect(),
    });
}

describe('scan-media', () => {
    const rules = [];

    beforeEach(() => {
        document.body.innerHTML = '';
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280 });
        Object.defineProperty(window, 'innerHeight', { configurable: true, value: 720 });
    });

    it('scans anchor media and standalone media in viewport', () => {
        document.body.innerHTML = `
            <a href="https://www.deviantart.com/u/art/work-1"><img id="anchored" src="https://images-wixmp.com/f/image-1.jpg" /></a>
            <img id="standalone" src="https://images-wixmp.com/f/image-2.jpg" />
        `;

        const anchored = document.getElementById('anchored') as HTMLImageElement;
        const standalone = document.getElementById('standalone') as HTMLImageElement;
        const anchor = anchored.closest('a') as HTMLAnchorElement;
        markVisible(anchor);
        markVisible(anchored);
        markVisible(standalone);

        const candidates = scanMediaCandidates(300, rules);

        expect(candidates).toHaveLength(2);
        expect(candidates[0].mediaUrl).toContain('images-wixmp.com');
        expect(candidates[0].anchorUrl).toContain('/art/');
        expect(candidates[1].mediaUrl).toContain('images-wixmp.com');
        expect(candidates[1].anchorUrl).toBeNull();
    });

    it('ignores non-visible media', () => {
        document.body.innerHTML = `<img id="img1" src="https://images-wixmp.com/f/image-3.jpg" />`;
        const image = document.getElementById('img1') as HTMLImageElement;
        Object.defineProperty(image, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ ...visibleRect(), width: 0 }),
        });

        const candidates = scanMediaCandidates(300, rules);
        expect(candidates).toHaveLength(0);
    });

    it('requires anchor visibility for anchored media candidates', () => {
        document.body.innerHTML = `
            <a id="hidden-anchor" href="https://www.deviantart.com/u/art/work-hidden">
                <img id="anchored-hidden" src="https://images-wixmp.com/f/image-hidden.jpg" />
            </a>
        `;

        const anchor = document.getElementById('hidden-anchor') as HTMLAnchorElement;
        const anchored = document.getElementById('anchored-hidden') as HTMLImageElement;
        Object.defineProperty(anchor, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ ...visibleRect(), width: 0 }),
        });
        markVisible(anchored);

        const candidates = scanMediaCandidates(300, rules);
        expect(candidates).toHaveLength(0);
    });
});
