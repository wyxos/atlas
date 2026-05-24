import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function audioDetail(id: number, source: string | null): AudioDetailsResponse['items'][number] {
    return {
        id,
        title: `Track ${id}`,
        source,
        source_id: null,
        spotify_uri: null,
        artists: [`Artist ${id}`],
        albums: [`Album ${id}`],
        cover_url: null,
        duration_seconds: 120,
        reaction: null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
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

describe('Audio source badges', () => {
    it('shows source badges for non-local audio rows only', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                ids: [1, 2, 3],
                sources: {
                    1: 'local',
                    2: 'Spotify',
                    3: 'YouTube',
                },
                source_ids: {},
                spotify_uris: {},
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 3,
                },
                pagination: {
                    per_page: 100,
                    total: 3,
                    total_pages: 1,
                },
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    audioDetail(1, 'Local'),
                    audioDetail(2, 'Spotify'),
                    audioDetail(3, 'YouTube'),
                ],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        expect(wrapper.findAll('[data-test="audio-track-source-badge"]').map((badge) => badge.text())).toEqual([
            'Spotify',
            'YouTube',
        ]);
    });
});
