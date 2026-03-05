import { beforeEach, describe, expect, it } from 'vitest';
import { collectDeviantArtBatchReactionItems } from './deviantart-batch-reaction';

describe('collectDeviantArtBatchReactionItems', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');
    });

    it('collects all DeviantArt gallery items and restores the original selection', async () => {
        const origin = window.location.origin;
        const basePageUrl = `${origin}/artseize/art/Untitled-1305712740`;
        const media = document.createElement('img');
        media.src = 'https://images.example.com/direct-image-1.jpg';
        document.body.appendChild(media);

        Object.defineProperty(media, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({
                x: 100,
                y: 80,
                left: 100,
                top: 80,
                right: 540,
                bottom: 440,
                width: 440,
                height: 360,
                toJSON: () => ({}),
            }) as DOMRect,
        });

        const section = document.createElement('section');
        const headingWrap = document.createElement('span');
        const heading = document.createElement('h2');
        heading.textContent = 'All Images';
        headingWrap.appendChild(heading);
        section.appendChild(headingWrap);

        const strip = document.createElement('div');
        const firstButton = document.createElement('button');
        const firstThumb = document.createElement('img');
        firstThumb.src = 'https://images.example.com/thumb-image-1.jpg';
        firstThumb.alt = 'Image 1';
        firstButton.appendChild(firstThumb);
        firstButton.addEventListener('click', () => {
            media.src = 'https://images.example.com/direct-image-1.jpg';
            history.replaceState({}, '', `${basePageUrl}#image-1`);
        });

        const secondButton = document.createElement('button');
        const secondThumb = document.createElement('img');
        secondThumb.src = 'https://images.example.com/thumb-image-2.jpg';
        secondThumb.alt = 'Image 2';
        secondButton.appendChild(secondThumb);
        secondButton.addEventListener('click', () => {
            media.src = 'https://images.example.com/direct-image-2.jpg';
            history.replaceState({}, '', `${basePageUrl}#image-2`);
        });

        strip.appendChild(firstButton);
        strip.appendChild(secondButton);
        section.appendChild(strip);
        document.body.appendChild(section);

        Object.defineProperty(section, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({
                x: 260,
                y: 456,
                left: 260,
                top: 456,
                right: 620,
                bottom: 516,
                width: 360,
                height: 60,
                toJSON: () => ({}),
            }) as DOMRect,
        });

        const items = await collectDeviantArtBatchReactionItems(media, {
            hostname: 'www.deviantart.com',
        });

        expect(items).toEqual([
            {
                candidateId: 'image-1',
                url: 'https://images.example.com/direct-image-1.jpg',
                referrerUrlHashAware: basePageUrl,
                pageUrl: basePageUrl,
                tagName: 'img',
            },
            {
                candidateId: 'image-2',
                url: 'https://images.example.com/direct-image-2.jpg',
                referrerUrlHashAware: `${basePageUrl}#image-2`,
                pageUrl: basePageUrl,
                tagName: 'img',
            },
        ]);
        expect(media.src).toBe('https://images.example.com/direct-image-1.jpg');
        expect(window.location.href).toBe(basePageUrl);
    });
});
