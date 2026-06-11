import { describe, expect, it } from 'vitest';
import { audioMetadataSourceLinks } from './audioMetadataDisplay';

describe('audioMetadataSourceLinks', () => {
    it('includes catalog provider source links', () => {
        expect(audioMetadataSourceLinks({
            spotify_track_id: '1WUjJPUznd8NJVdzqUpT1M',
            apple_track_url: 'https://music.apple.com/album/the-soultaker/123?i=456',
            apple_collection_url: 'https://music.apple.com/album/the-soultaker/123',
            deezer_track_url: 'https://www.deezer.com/track/98765',
        })).toEqual([
            {
                key: 'spotify-track',
                label: 'Spotify track',
                url: 'https://open.spotify.com/track/1WUjJPUznd8NJVdzqUpT1M',
            },
            {
                key: 'apple-track',
                label: 'Apple Music track',
                url: 'https://music.apple.com/album/the-soultaker/123?i=456',
            },
            {
                key: 'apple-album',
                label: 'Apple Music album',
                url: 'https://music.apple.com/album/the-soultaker/123',
            },
            {
                key: 'deezer-track',
                label: 'Deezer track',
                url: 'https://www.deezer.com/track/98765',
            },
        ]);
    });
});
