import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useGlobalAudioPlayer } from '@/composables/useGlobalAudioPlayer';
import TabContentV2FullscreenReactions from './TabContentV2FullscreenReactions.vue';

vi.mock('./FileReactions.vue', () => ({
    default: {
        template: '<div data-testid="file-reactions" />',
    },
}));

describe('TabContentV2FullscreenReactions', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
    });

    it('queues spotify browse items through the global audio player', async () => {
        const player = useGlobalAudioPlayer();
        const wrapper = mount(TabContentV2FullscreenReactions, {
            props: {
                item: {
                    id: '1066450',
                    type: 'image',
                    url: 'https://i.scdn.co/image/ab67616d0000b273cover',
                    feedItem: {
                        id: 1066450,
                        width: 640,
                        height: 640,
                        page: 1,
                        key: '1-1066450',
                        index: 0,
                        src: 'https://i.scdn.co/image/ab67616d0000b273cover',
                        preview: 'https://i.scdn.co/image/ab67616d0000b273cover',
                        original: null,
                        originalUrl: null,
                        url: 'https://open.spotify.com/track/5P97xlvOl6IadKTLVId5ap',
                        media_kind: 'audio',
                        mime_type: 'audio/spotify',
                        source: 'Spotify',
                        source_id: '5P97xlvOl6IadKTLVId5ap',
                        spotify_uri: 'spotify:track:5P97xlvOl6IadKTLVId5ap',
                        title: 'Spotify fixture track',
                    },
                },
                index: 0,
                total: 1,
                handleBlacklist: vi.fn(),
                handleReaction: vi.fn(),
            },
        });

        await wrapper.get('button[aria-label="Play Spotify track"]').trigger('click');

        expect(player.currentTrackId.value).toBe(1066450);
        expect(player.isPlaying.value).toBe(true);
        expect(player.currentTrack.value).toMatchObject({
            id: 1066450,
            title: 'Spotify fixture track',
            source: 'Spotify',
            sourceId: '5P97xlvOl6IadKTLVId5ap',
            spotifyUri: 'spotify:track:5P97xlvOl6IadKTLVId5ap',
            coverUrl: 'https://i.scdn.co/image/ab67616d0000b273cover',
        });
    });
});
