import { beforeEach, describe, expect, it } from 'vitest';
import { collectDeviantArtBatchReactionItems } from './deviantart-batch-reaction';

describe('collectDeviantArtBatchReactionItems', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        history.replaceState({}, '', '/artseize/art/Untitled-1305712740');
    });

    function withRect(width: number, height: number, top: number, left = 100): DOMRect {
        return {
            x: left,
            y: top,
            left,
            top,
            right: left + width,
            bottom: top + height,
            width,
            height,
            toJSON: () => ({}),
        } as DOMRect;
    }

    it('collects all DeviantArt gallery items and restores the original selection', async () => {
        const origin = window.location.origin;
        const basePageUrl = `${origin}/artseize/art/Untitled-1305712740`;
        const media = document.createElement('img');
        media.src = 'https://images.example.com/direct-image-1.jpg';
        document.body.appendChild(media);

        Object.defineProperty(media, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(440, 360, 80),
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
            value: () => withRect(360, 60, 456, 260),
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

    it('collects all DeviantArt gallery items when the main image node is replaced', async () => {
        const origin = window.location.origin;
        const basePageUrl = `${origin}/artseize/art/Untitled-1305712740`;
        const section = document.createElement('section');
        const headingWrap = document.createElement('span');
        const heading = document.createElement('h2');
        heading.textContent = 'All Images';
        headingWrap.appendChild(heading);
        section.appendChild(headingWrap);

        const strip = document.createElement('div');
        section.appendChild(strip);
        document.body.appendChild(section);

        Object.defineProperty(section, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(360, 60, 456, 260),
        });

        const createMainImage = (src: string): HTMLImageElement => {
            const image = document.createElement('img');
            image.src = src;
            Object.defineProperty(image, 'getBoundingClientRect', {
                configurable: true,
                value: () => withRect(440, 360, 80),
            });

            return image;
        };

        let currentMedia = createMainImage('https://images.example.com/direct-image-1.jpg');
        document.body.insertBefore(currentMedia, section);
        const initialMedia = currentMedia;

        const replaceMainImage = (src: string, href: string): void => {
            currentMedia.remove();
            currentMedia = createMainImage(src);
            document.body.insertBefore(currentMedia, section);
            history.replaceState({}, '', href);
        };

        const firstButton = document.createElement('button');
        const firstThumb = document.createElement('img');
        firstThumb.src = 'https://images.example.com/thumb-image-1.jpg';
        firstThumb.alt = 'Image 1';
        firstButton.appendChild(firstThumb);
        firstButton.addEventListener('click', () => {
            replaceMainImage('https://images.example.com/direct-image-1.jpg', basePageUrl);
        });

        const secondButton = document.createElement('button');
        const secondThumb = document.createElement('img');
        secondThumb.src = 'https://images.example.com/thumb-image-2.jpg';
        secondThumb.alt = 'Image 2';
        secondButton.appendChild(secondThumb);
        secondButton.addEventListener('click', () => {
            replaceMainImage('https://images.example.com/direct-image-2.jpg', `${basePageUrl}#image-2`);
        });

        strip.appendChild(firstButton);
        strip.appendChild(secondButton);

        const items = await collectDeviantArtBatchReactionItems(initialMedia, {
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
        expect(initialMedia.isConnected).toBe(false);
        expect(currentMedia.src).toBe('https://images.example.com/direct-image-1.jpg');
        expect(window.location.href).toBe(basePageUrl);
    });

    it('keeps resolving the overlay image when DeviantArt mounts the viewer outside the page main element', async () => {
        const origin = window.location.origin;
        const basePageUrl = `${origin}/artseize/art/Untitled-1305712740`;

        const main = document.createElement('main');
        const unrelatedImage = document.createElement('img');
        unrelatedImage.src = 'https://images.example.com/unrelated-page-image.jpg';
        Object.defineProperty(unrelatedImage, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(1600, 1200, 20, 20),
        });
        main.appendChild(unrelatedImage);
        document.body.appendChild(main);

        const overlay = document.createElement('div');
        const post = document.createElement('div');
        const viewer = document.createElement('div');
        const figure = document.createElement('figure');
        const mediaShell = document.createElement('div');
        figure.appendChild(mediaShell);
        viewer.appendChild(figure);
        post.appendChild(viewer);

        const section = document.createElement('section');
        const headingWrap = document.createElement('span');
        const heading = document.createElement('h2');
        heading.textContent = 'All Images';
        headingWrap.appendChild(heading);
        section.appendChild(headingWrap);

        const strip = document.createElement('div');
        section.appendChild(strip);
        post.appendChild(section);
        overlay.appendChild(post);
        document.body.appendChild(overlay);

        Object.defineProperty(viewer, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(900, 960, 40, 80),
        });
        Object.defineProperty(section, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(360, 60, 1012, 260),
        });

        const createOverlayImage = (src: string): HTMLImageElement => {
            const image = document.createElement('img');
            image.src = src;
            Object.defineProperty(image, 'getBoundingClientRect', {
                configurable: true,
                value: () => withRect(560, 560, 80),
            });

            return image;
        };

        let currentMedia = createOverlayImage('https://images.example.com/direct-image-1.jpg');
        mediaShell.appendChild(currentMedia);
        const initialMedia = currentMedia;

        const replaceOverlayImage = (src: string, href: string): void => {
            currentMedia.remove();
            currentMedia = createOverlayImage(src);
            mediaShell.appendChild(currentMedia);
            history.replaceState({}, '', href);
        };

        const firstButton = document.createElement('button');
        const firstThumb = document.createElement('img');
        firstThumb.src = 'https://images.example.com/thumb-image-1.jpg';
        firstThumb.alt = 'Image 1';
        firstButton.appendChild(firstThumb);
        firstButton.addEventListener('click', () => {
            replaceOverlayImage('https://images.example.com/direct-image-1.jpg', basePageUrl);
        });

        const secondButton = document.createElement('button');
        const secondThumb = document.createElement('img');
        secondThumb.src = 'https://images.example.com/thumb-image-2.jpg';
        secondThumb.alt = 'Image 2';
        secondButton.appendChild(secondThumb);
        secondButton.addEventListener('click', () => {
            replaceOverlayImage('https://images.example.com/direct-image-2.jpg', `${basePageUrl}#image-2`);
        });

        strip.appendChild(firstButton);
        strip.appendChild(secondButton);

        const items = await collectDeviantArtBatchReactionItems(initialMedia, {
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
        expect(initialMedia.isConnected).toBe(false);
        expect(currentMedia.src).toBe('https://images.example.com/direct-image-1.jpg');
        expect(window.location.href).toBe(basePageUrl);
    });

    it('falls back to thumbnail order for candidate ids when DeviantArt does not update the image hash', async () => {
        const origin = window.location.origin;
        const basePageUrl = `${origin}/artseize/art/Untitled-1305712740`;
        const viewer = document.createElement('div');
        const media = document.createElement('img');
        media.src = 'https://images.example.com/direct-image-1.jpg';
        viewer.appendChild(media);
        document.body.appendChild(viewer);

        Object.defineProperty(media, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(560, 560, 80),
        });
        Object.defineProperty(viewer, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(900, 960, 40, 80),
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
            history.replaceState({}, '', basePageUrl);
        });

        const secondButton = document.createElement('button');
        const secondThumb = document.createElement('img');
        secondThumb.src = 'https://images.example.com/thumb-image-2.jpg';
        secondThumb.alt = 'Image 2';
        secondButton.appendChild(secondThumb);
        secondButton.addEventListener('click', () => {
            media.src = 'https://images.example.com/direct-image-2.jpg';
            history.replaceState({}, '', basePageUrl);
        });

        strip.appendChild(firstButton);
        strip.appendChild(secondButton);
        section.appendChild(strip);
        document.body.appendChild(section);

        Object.defineProperty(section, 'getBoundingClientRect', {
            configurable: true,
            value: () => withRect(360, 60, 1012, 260),
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
                referrerUrlHashAware: basePageUrl,
                pageUrl: basePageUrl,
                tagName: 'img',
            },
        ]);
    });
});
