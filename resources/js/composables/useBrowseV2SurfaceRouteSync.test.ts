import { describe, expect, it } from 'vitest';
import { mapBrowseV2FileToFeedItem } from '@/composables/useBrowseV2SurfaceRouteSync';
import type { File } from '@/types/file';

function createFile(overrides: Partial<File> = {}): File {
    return {
        id: 111,
        source: 'CivitAI',
        source_id: '127736990',
        filename: 'video-file',
        ext: 'mp4',
        size: null,
        width: 960,
        height: 960,
        mime_type: 'application/mp4',
        hash: null,
        title: 'Video file',
        description: null,
        url: 'https://example.test/original.mp4',
        file_url: 'https://example.test/original.mp4',
        referrer_url: 'https://example.test/referrer',
        path: null,
        absolute_path: null,
        absolute_preview_path: null,
        preview_url: 'https://example.test/preview.mp4',
        cover_url: null,
        disk_url: null,
        preview_file_url: 'https://example.test/preview.mp4',
        poster_url: null,
        preview_path: null,
        poster_path: null,
        tags: null,
        parent_id: null,
        chapter: null,
        previewed_at: null,
        previewed_count: 0,
        seen_at: null,
        seen_count: 0,
        auto_blacklisted: false,
        blacklisted_at: null,
        downloaded: false,
        downloaded_at: null,
        imported_at: null,
        download_progress: 0,
        not_found: false,
        listing_metadata: null,
        detail_metadata: null,
        containers: [],
        created_at: '2026-04-17T00:00:00Z',
        updated_at: '2026-04-17T00:00:00Z',
        ...overrides,
    };
}

describe('mapBrowseV2FileToFeedItem', () => {
    it('treats application/mp4 files as fullscreen videos', () => {
        const item = mapBrowseV2FileToFeedItem(createFile());

        expect(item.media_kind).toBe('video');
        expect(item.type).toBe('video');
        expect(item.preview).toBe('https://example.test/preview.mp4');
        expect(item.original).toBe('https://example.test/original.mp4');
    });

    it('uses audio cover URLs as standalone browse previews', () => {
        const item = mapBrowseV2FileToFeedItem(createFile({
            filename: 'audio-file',
            ext: 'mp3',
            mime_type: 'audio/mpeg',
            file_url: '/api/files/111/downloaded',
            preview_url: null,
            preview_file_url: null,
            cover_url: '/api/audio/album-covers/501',
        }));

        expect(item.media_kind).toBe('audio');
        expect(item.type).toBe('image');
        expect(item.preview).toBe('/api/audio/album-covers/501');
        expect(item.original).toBe('/api/files/111/downloaded');
    });

    it('keeps spotify standalone browse items on cover art instead of external page playback urls', () => {
        const item = mapBrowseV2FileToFeedItem(createFile({
            filename: 'spotify-track',
            ext: 'spotify',
            source: 'Spotify',
            source_id: '5P97xlvOl6IadKTLVId5ap',
            spotify_uri: 'spotify:track:5P97xlvOl6IadKTLVId5ap',
            mime_type: 'audio/spotify',
            url: 'https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap',
            file_url: null,
            disk_url: null,
            preview_url: 'https://i.scdn.co/image/ab67616d0000b273cover',
            preview_file_url: null,
            cover_url: 'https://i.scdn.co/image/ab67616d0000b273cover',
        }));

        expect(item.media_kind).toBe('audio');
        expect(item.type).toBe('image');
        expect(item.preview).toBe('https://i.scdn.co/image/ab67616d0000b273cover');
        expect(item.original).toBe('https://i.scdn.co/image/ab67616d0000b273cover');
        expect(item.url).toBe('https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap');
        expect(item.spotify_uri).toBe('spotify:track:5P97xlvOl6IadKTLVId5ap');
    });
});
