import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudioPage } from './audioTestUtils';
import type { AudioIdsResponse, AudioPlaylistSection } from '@/types/audio';

const playlistSections: AudioPlaylistSection[] = [
    {
        key: 'system',
        label: 'System',
        playlists: [
            {
                id: 1,
                slug: 'all',
                name: 'All audio',
                description: 'Every audio file',
                kind: 'system',
                membership_mode: 'rules',
                source_key: null,
                is_editable: false,
                is_deletable: false,
                count: 3,
            },
            {
                id: 2,
                slug: 'source-spotify',
                name: 'Spotify',
                description: 'Source: Spotify',
                kind: 'system',
                membership_mode: 'rules',
                source_key: 'spotify',
                is_editable: false,
                is_deletable: false,
                count: 2,
            },
        ],
    },
    {
        key: 'smart',
        label: 'Smart',
        playlists: [
            {
                id: 3,
                slug: 'smart-review-queue',
                name: 'Review queue',
                description: 'Hand-picked tracks to review',
                kind: 'smart',
                membership_mode: 'rules',
                source_key: null,
                is_editable: true,
                is_deletable: true,
                count: 1,
            },
        ],
    },
    {
        key: 'manual',
        label: 'Playlists',
        playlists: [
            {
                id: 4,
                slug: 'late-night',
                name: 'Late night',
                description: 'Manual playlist',
                kind: 'manual',
                membership_mode: 'manual',
                source_key: null,
                is_editable: true,
                is_deletable: true,
                count: 4,
            },
        ],
    },
];

const audioIdsResponse: AudioIdsResponse = {
    ids: [1],
    sources: {
        1: 'Spotify',
    },
    cursor: {
        after_id: 0,
        next_after_id: null,
        has_more: false,
        max_id: 1,
    },
    pagination: {
        per_page: 100,
        total: 1,
        total_pages: 1,
    },
};

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
};

function mockAudioEndpoints(): void {
    mockAxios.get.mockImplementation((url: string) => {
        if (url === '/api/audio/playlists') {
            return Promise.resolve({
                data: {
                    sections: playlistSections,
                },
            });
        }

        return Promise.resolve({
            data: audioIdsResponse,
        });
    });
    mockAxios.post.mockResolvedValue({
        data: {
            items: [],
        },
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    Object.assign(global.window, {
        axios: mockAxios,
    });
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Audio playlists', () => {
    it('opens a non-overlay playlist panel beside the audio list', async () => {
        mockAudioEndpoints();

        const { wrapper } = await mountAudioPage();
        await flushPromises();

        expect(wrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(false);

        await wrapper.get('[data-test="audio-playlists-cta"]').trigger('click');
        await flushPromises();

        expect(wrapper.get('[data-test="audio-library-surface"]').classes()).toContain('flex');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').classes()).toEqual(expect.arrayContaining([
            'w-72',
            'shrink-0',
            'md:flex',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('PLAYLISTS');
        expect(wrapper.findAll('[data-test="audio-playlist-section-label"]').map((label) => label.text())).toEqual([
            'System',
            'Smart',
            'Playlists',
        ]);
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('All audio');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Spotify');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Review queue');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Late night');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Every audio file');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Source: Spotify');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Manual playlist');
        expect(wrapper.get('[data-test="audio-playlist-scroll"]').classes()).toContain('[scrollbar-gutter:stable]');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').classes()).toContain('shrink-0');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').classes()).toContain('border-t');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').text()).toBe('Add playlist');
        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/playlists');
    });

    it('reloads audio ids when a playlist is selected', async () => {
        mockAudioEndpoints();

        const { router, wrapper } = await mountAudioPage();
        await flushPromises();

        await wrapper.get('[data-test="audio-playlists-cta"]').trigger('click');
        await flushPromises();

        const spotifyOption = wrapper
            .findAll('[data-test="audio-playlist-option"]')
            .find((option) => option.text().includes('Spotify'));

        expect(spotifyOption).toBeTruthy();
        await spotifyOption?.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/playlists/source-spotify');
        expect(mockAxios.get).toHaveBeenLastCalledWith('/api/audio/ids', {
            params: {
                after_id: 0,
                per_page: 100,
                playlist: 'source-spotify',
            },
        });
    });
});
