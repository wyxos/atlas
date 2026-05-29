import { describe, expect, it } from 'vitest';
import { mediaMatchesRulesForPage } from './media-rule-match';

describe('mediaMatchesRulesForPage', () => {
    it('keeps reddit blob-backed videos eligible even when active domain regexes do not match', () => {
        const video = document.createElement('video');
        video.innerHTML = '<source src="blob:https://www.reddit.com/abc-123" type="video/mp4">';

        const matches = mediaMatchesRulesForPage(
            video,
            'https://www.reddit.com/r/videos/comments/abc123/example/',
            [
                {
                    domain: 'reddit.com',
                    regexes: ['.*v\\.redd\\.it.*'],
                },
            ],
            'www.reddit.com',
        );

        expect(matches).toBe(true);
    });

    it('applies normal rule checks for non-reddit blob-backed videos', () => {
        const video = document.createElement('video');
        video.innerHTML = '<source src="blob:https://www.youtube.com/abc-123" type="video/mp4">';

        const matches = mediaMatchesRulesForPage(
            video,
            'https://www.youtube.com/watch?v=abc123',
            [
                {
                    domain: 'youtube.com',
                    regexes: ['.*\\.mp4$'],
                },
            ],
            'www.youtube.com',
        );

        expect(matches).toBe(false);
    });

    it('allows non-reddit blob-backed videos when the page url matches active rules', () => {
        const video = document.createElement('video');
        video.innerHTML = '<source src="blob:https://www.youtube.com/abc-123" type="video/mp4">';

        const matches = mediaMatchesRulesForPage(
            video,
            'https://www.youtube.com/watch?v=abc123',
            [
                {
                    domain: 'youtube.com',
                    regexes: ['.*\\/watch\\?.*v='],
                },
            ],
            'www.youtube.com',
        );

        expect(matches).toBe(true);
    });

    it('still applies normal rule checks for non-blob videos', () => {
        const video = document.createElement('video');
        video.src = 'https://cdn.example.com/video.mp4';

        const matches = mediaMatchesRulesForPage(
            video,
            'https://videos.example.com/watch/42',
            [
                {
                    domain: 'videos.example.com',
                    regexes: ['.*\\/watch\\/.*'],
                },
            ],
            'videos.example.com',
        );

        expect(matches).toBe(false);
    });
});
