import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function audioDetail(id: number): AudioDetailsResponse['items'][number] {
    return {
        id,
        title: `Track ${id}`,
        source: 'Spotify',
        source_id: null,
        spotify_uri: null,
        artists: [`Artist ${id}`],
        albums: [`Album ${id}`],
        cover_url: null,
        duration_seconds: null,
        reaction: null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
    };
}

function queuedTrack(id: number, onIdRead: () => void): AudioPlayerTrack {
    const track = {
        title: `Queued Track ${id}`,
        artists: `Queued Artist ${id}`,
        album: `Queued Album ${id}`,
        coverUrl: null,
        duration: '1:00',
        durationSeconds: 60,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
    } as AudioPlayerTrack;

    Object.defineProperty(track, 'id', {
        enumerable: true,
        get() {
            onIdRead();

            return id;
        },
    });

    return track;
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

describe('Audio queue performance', () => {
    it('selects another row without rebuilding an existing matching player queue', async () => {
        const ids = Array.from({ length: 100 }, (_, index) => index + 1);

        mockAxios.get.mockResolvedValue({
            data: {
                ids,
                sources: Object.fromEntries(ids.map((id) => [id, 'Spotify'])),
                source_ids: Object.fromEntries(ids.map((id) => [id, null])),
                spotify_uris: Object.fromEntries(ids.map((id) => [id, null])),
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 100,
                },
                pagination: {
                    per_page: 100,
                    total: 100,
                    total_pages: 1,
                },
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockImplementation(async (_url: string, payload: { ids: number[] }) => ({
            data: {
                items: payload.ids.map(audioDetail),
            } satisfies AudioDetailsResponse,
        }));

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        let idReadCount = 0;
        const player = useGlobalAudioPlayer();
        player.queue.value = ids.map((id) => queuedTrack(id, () => {
            idReadCount += 1;
        }));
        player.currentTrackId.value = 1;
        idReadCount = 0;

        await wrapper.findAll('[data-test="audio-track-row"]')[1]?.trigger('click');
        await flushPromises();

        expect(wrapper.findAll('[data-test="audio-track-row"]')[1]?.attributes('aria-selected')).toBe('true');
        expect(idReadCount).toBe(0);
    });
});
