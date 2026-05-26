import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { AUDIO_PLAYBACK_STATS_EVENT, useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { ReactionType } from '@/types/reaction';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function audioDetail(id: number, playCount: number, skipCount: number) {
    return {
        id,
        title: `Track ${id}`,
        source: 'Spotify',
        source_id: null,
        spotify_uri: null,
        artists: ['Artist A'],
        albums: ['Album A'],
        cover_url: null,
        duration_seconds: 185,
        reaction: null as { type: ReactionType } | null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
        play_count: playCount,
        skip_count: skipCount,
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

afterEach(() => {
    useGlobalAudioPlayer().clear();
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Audio playback stats', () => {
    it('renders play skip counts and applies player stat updates to visible rows', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                ids: [7],
                sources: { 7: 'Spotify' },
                source_ids: { 7: null },
                spotify_uris: { 7: null },
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 7,
                },
                pagination: {
                    per_page: 100,
                    total: 1,
                    total_pages: 1,
                },
            },
        });
        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    audioDetail(7, 4, 2),
                ],
            },
        });

        const wrapper = await mountAudio();
        await flushPromises();
        vi.advanceTimersByTime(180);
        await flushPromises();

        const row = wrapper.get('[data-test="audio-track-row"]');
        expect(row.get('[data-test="audio-track-title-cell"]').text()).not.toContain('4 plays');
        expect(row.get('[data-test="audio-track-playback-counts"]').classes()).toEqual(expect.arrayContaining([
            'hidden',
            'lg:block',
        ]));
        expect(row.get('[data-test="audio-track-play-count"]').text()).toBe('4 plays');
        expect(row.get('[data-test="audio-track-skip-count"]').text()).toBe('2 skips');

        window.dispatchEvent(new CustomEvent(AUDIO_PLAYBACK_STATS_EVENT, {
            detail: {
                file_id: 7,
                last_played_at: '2026-05-26T10:00:00+04:00',
                last_skipped_at: '2026-05-26T10:01:00+04:00',
                play_count: 5,
                skip_count: 3,
            },
        }));
        await flushPromises();

        expect(row.get('[data-test="audio-track-play-count"]').text()).toBe('5 plays');
        expect(row.get('[data-test="audio-track-skip-count"]').text()).toBe('3 skips');
    });
});
