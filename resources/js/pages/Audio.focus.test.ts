import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function testTrack(id: number, overrides: Partial<AudioPlayerTrack> = {}): AudioPlayerTrack {
    return {
        id,
        title: `Track ${id}`,
        artists: 'Signal Park',
        album: 'Focus Tests',
        coverUrl: null,
        duration: '1:00',
        durationSeconds: 60,
        reaction: null,
        blacklistedAt: null,
        previewedCount: 0,
        seenCount: 0,
        playbackUrl: `/api/files/${id}/serve`,
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
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('Audio focus requests', () => {
    it('focuses the current player track in the active audio list when requested', async () => {
        const ids = Array.from({ length: 80 }, (_, index) => index + 1);
        mockAxios.get.mockResolvedValue({
            data: {
                ids,
                sources: Object.fromEntries(ids.map((id) => [id, 'Local'])),
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 80,
                },
                pagination: {
                    per_page: 100,
                    total: 80,
                    total_pages: 1,
                },
            } satisfies AudioIdsResponse,
        });
        mockAxios.post.mockResolvedValue({
            data: {
                items: [],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = await mountAudio();
        await flushPromises();

        const player = useGlobalAudioPlayer();
        player.queueAndPlay([testTrack(60)], 60);

        expect(wrapper.find('[data-audio-id="60"]').exists()).toBe(false);

        player.requestCurrentTrackFocus();
        await wrapper.vm.$nextTick();

        const focusedRow = wrapper.get('[data-audio-id="60"]');
        expect(focusedRow.attributes('data-current-track')).toBe('true');
        expect(focusedRow.attributes('aria-selected')).toBe('true');
    });
});
