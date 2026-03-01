import { describe, expect, it } from 'vitest';
import { resolveReactionMediaUrl } from './media-utils';

describe('resolveReactionMediaUrl', () => {
    it('does not use video poster as reaction media url fallback', () => {
        const video = document.createElement('video');
        video.poster = 'https://cdn.example.com/poster.jpg';

        expect(resolveReactionMediaUrl(video)).toBeNull();
    });

    it('uses direct video src when available', () => {
        const video = document.createElement('video');
        video.src = 'https://cdn.example.com/video.mp4#fragment';

        expect(resolveReactionMediaUrl(video)).toBe('https://cdn.example.com/video.mp4');
    });
});
