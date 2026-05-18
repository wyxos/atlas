import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { ReactionType } from '@/types/reaction';

type AudioIdsResponse = {
    ids: number[];
    sources: Record<number, string | null>;
    cursor: {
        after_id: number;
        next_after_id: number | null;
        has_more: boolean;
        max_id: number;
    };
    pagination: {
        per_page: number;
        total: number | null;
        total_pages: number | null;
    };
};

type AudioDetailsResponse = {
    items: Array<{
        id: number;
        title: string | null;
        source: string | null;
        artists: string[];
        albums: string[];
        cover_url: string | null;
        duration_seconds: number | null;
        reaction: { type: ReactionType } | null;
        blacklisted_at: string | null;
        previewed_count: number;
        seen_count: number;
    }>;
};

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

afterEach(() => {
    useGlobalAudioPlayer().clear();
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('Audio shuffle play', () => {
    it('starts the visible playlist in shuffled playback from the header CTA', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                ids: [5, 6, 7],
                sources: { 5: 'Spotify', 6: 'Spotify', 7: 'Spotify' },
                cursor: { after_id: 0, next_after_id: null, has_more: false, max_id: 7 },
                pagination: { per_page: 100, total: 3, total_pages: 1 },
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    {
                        id: 5,
                        title: 'First Track',
                        source: 'Spotify',
                        artists: ['Archive Drift'],
                        albums: ['Playback Notes'],
                        cover_url: null,
                        duration_seconds: null,
                        reaction: null,
                        blacklisted_at: null,
                        previewed_count: 0,
                        seen_count: 0,
                    },
                    {
                        id: 6,
                        title: 'Second Track',
                        source: 'Spotify',
                        artists: ['Signal Park'],
                        albums: ['Blue Room Sessions'],
                        cover_url: null,
                        duration_seconds: null,
                        reaction: null,
                        blacklisted_at: null,
                        previewed_count: 0,
                        seen_count: 0,
                    },
                    {
                        id: 7,
                        title: 'Third Track',
                        source: 'Spotify',
                        artists: ['Mira Vale'],
                        albums: ['Late Indexes'],
                        cover_url: null,
                        duration_seconds: null,
                        reaction: null,
                        blacklisted_at: null,
                        previewed_count: 0,
                        seen_count: 0,
                    },
                ],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();
        vi.spyOn(Math, 'random').mockReturnValue(0);

        await wrapper.get('[data-test="audio-shuffle-play-cta"]').trigger('click');

        const player = useGlobalAudioPlayer();
        expect(player.queueLabel.value).toBe('All audio');
        expect(player.isShuffleEnabled.value).toBe(true);
        expect(player.isPlaying.value).toBe(true);
        expect(player.queue.value.map((track) => track.id)).toEqual([6, 7, 5]);
        expect(player.currentTrackId.value).toBe(6);
        expect(wrapper.findAll('[data-test="audio-track-row"]')[1]?.attributes('data-current-track')).toBe('true');
    });
});
