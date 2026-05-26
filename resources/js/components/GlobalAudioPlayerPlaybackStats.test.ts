import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import GlobalAudioPlayer from './GlobalAudioPlayer.vue';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '@/composables/useGlobalAudioPlayer';

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: '',
        album: '',
        coverUrl: null,
        duration: `0:${id.toString().padStart(2, '0')}`,
        durationSeconds: id,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
        ...overrides,
    };
}

describe('GlobalAudioPlayer playback stats', () => {
    afterEach(() => {
        useGlobalAudioPlayer().clear();
        delete (window as unknown as { axios?: unknown }).axios;
        vi.restoreAllMocks();
    });

    it('records play starts and manual skips without counting pause resume as another play', async () => {
        const post = vi.fn().mockImplementation(async (_url: string, payload: { event: 'play' | 'skip'; file_id: number }) => ({
            data: {
                file_id: payload.file_id,
                last_played_at: payload.event === 'play' ? '2026-05-26T10:00:00+04:00' : null,
                last_skipped_at: payload.event === 'skip' ? '2026-05-26T10:01:00+04:00' : null,
                play_count: payload.event === 'play' ? 1 : 1,
                skip_count: payload.event === 'skip' ? 1 : 0,
            },
        }));
        Object.assign(window, {
            axios: { post },
        });
        const player = useGlobalAudioPlayer();
        const wrapper = mount(GlobalAudioPlayer);
        player.queueAndPlay([testTrack(11), testTrack(12)], 11);
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/audio/playback-events', {
            event: 'play',
            file_id: 11,
        });
        expect(player.currentTrack.value?.playCount).toBe(1);

        await wrapper.get('[aria-label="Pause"]').trigger('click');
        await wrapper.get('[aria-label="Play"]').trigger('click');
        await flushPromises();

        const firstTrackPlayEvents = post.mock.calls.filter((call) => {
            const payload = call[1] as { event: string; file_id: number };

            return payload.event === 'play' && payload.file_id === 11;
        });
        expect(firstTrackPlayEvents).toHaveLength(1);

        await wrapper.get('[aria-label="Next"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith('/api/audio/playback-events', {
            event: 'skip',
            file_id: 11,
        });
        expect(post).toHaveBeenCalledWith('/api/audio/playback-events', {
            event: 'play',
            file_id: 12,
        });
    });
});
