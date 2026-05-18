import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer, type AudioPlayerTrack } from '../composables/useGlobalAudioPlayer';
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

type AudioDetailsItem = AudioDetailsResponse['items'][number];

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function audioDetail(overrides: Partial<AudioDetailsItem> & Pick<AudioDetailsItem, 'id'>): AudioDetailsItem {
    return {
        title: `Track ${overrides.id}`,
        source: null,
        artists: [],
        albums: [],
        cover_url: null,
        duration_seconds: null,
        reaction: null,
        blacklisted_at: null,
        previewed_count: 0,
        seen_count: 0,
        ...overrides,
    };
}

function queuedTrack(overrides: Partial<AudioPlayerTrack> & Pick<AudioPlayerTrack, 'id'>): AudioPlayerTrack {
    return {
        id: overrides.id,
        title: `Audio #${overrides.id}`,
        artists: 'Loading metadata...',
        album: 'Unknown album',
        coverUrl: null,
        duration: '--:--',
        durationSeconds: null,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${overrides.id}/serve`,
        ...overrides,
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

describe('Audio player queue metadata', () => {
    it('preserves hydrated player metadata for tracks outside the visible row details cache', async () => {
        const ids = Array.from({ length: 20 }, (_, index) => index + 1);

        mockAxios.get.mockResolvedValue({
            data: {
                ids,
                sources: Object.fromEntries(ids.map((id) => [id, 'Spotify'])),
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 20,
                },
                pagination: {
                    per_page: 100,
                    total: 20,
                    total_pages: 1,
                },
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockImplementation(async (url: string, payload?: { ids?: number[] }) => {
            if (url === '/api/audio/details') {
                return {
                    data: {
                        items: (payload?.ids ?? []).map((id) => audioDetail({
                            id,
                            title: `Visible Track ${id}`,
                            source: 'Spotify',
                            artists: [`Visible Artist ${id}`],
                            albums: [`Visible Album ${id}`],
                            duration_seconds: 120 + id,
                        })),
                    } satisfies AudioDetailsResponse,
                };
            }

            if (url === '/api/files/1/reaction') {
                return {
                    data: {
                        reaction: { type: 'like' },
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio();
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        const firstRow = wrapper.get('[data-test="audio-track-row"]');
        await firstRow.trigger('click');

        const player = useGlobalAudioPlayer();
        expect(player.queue.value.find((track) => track.id === 20)?.title).toBe('Audio #20');

        player.updateQueuedTracks([
            queuedTrack({
                id: 20,
                title: 'Queued Track 20',
                artists: 'Queued Artist 20',
                album: 'Queued Album 20',
                coverUrl: '/api/audio/album-covers/20',
                duration: '2:20',
                durationSeconds: 140,
            }),
        ]);
        expect(player.queue.value.find((track) => track.id === 20)).toMatchObject({
            title: 'Queued Track 20',
            artists: 'Queued Artist 20',
            coverUrl: '/api/audio/album-covers/20',
        });

        await firstRow.get('[aria-label="Like"]').trigger('click');
        await flushPromises();

        expect(player.queue.value.find((track) => track.id === 20)).toMatchObject({
            title: 'Queued Track 20',
            artists: 'Queued Artist 20',
            album: 'Queued Album 20',
            coverUrl: '/api/audio/album-covers/20',
            duration: '2:20',
            durationSeconds: 140,
        });
    });
});
