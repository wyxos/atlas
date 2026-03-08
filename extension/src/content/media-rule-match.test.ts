import { describe, expect, it } from 'vitest';
import { mediaMatchesRulesForPage } from './media-rule-match';

describe('mediaMatchesRulesForPage', () => {
    it('keeps blob-backed videos eligible even when active domain regexes do not match', () => {
        const video = document.createElement('video');
        video.innerHTML = '<source src="blob:https://videos.example.com/abc-123" type="video/mp4">';

        const matches = mediaMatchesRulesForPage(
            video,
            'https://videos.example.com/watch/42',
            [
                {
                    domain: 'videos.example.com',
                    regexes: ['.*\\.mp4$'],
                },
            ],
            'videos.example.com',
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
