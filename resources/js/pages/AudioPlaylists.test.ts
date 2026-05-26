import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudioPage, mountAudioPlaylistGrid } from './audioTestUtils';
import { AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY } from '@/composables/useAudioPlaylistPanelOpenState';
import type { AudioIdsResponse, AudioPlaylist, AudioPlaylistSection } from '@/types/audio';

function playlistFixture(overrides: Partial<AudioPlaylist> & Pick<AudioPlaylist, 'id' | 'slug' | 'name'>): AudioPlaylist {
    return {
        description: null,
        kind: 'manual',
        membership_mode: 'manual',
        source_key: null,
        is_editable: true,
        is_deletable: true,
        count: 1,
        cover_mode: 'first_track',
        cover_url: null,
        cover_file_id: null,
        cover_file_ids: [],
        ...overrides,
    };
}

const playlistSections: AudioPlaylistSection[] = [
    {
        key: 'system',
        label: 'System',
        playlists: [
            playlistFixture({
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
                cover_url: '/api/files/1/poster',
                cover_file_id: 1,
            }),
            playlistFixture({
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
                cover_url: '/api/files/2/poster',
                cover_file_id: 2,
            }),
        ],
    },
    {
        key: 'smart',
        label: 'Smart',
        playlists: [
            playlistFixture({
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
            }),
        ],
    },
    {
        key: 'manual',
        label: 'Playlists',
        playlists: [
            playlistFixture({
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
                cover_mode: 'custom',
                cover_url: '/api/files/4/poster',
                cover_file_id: 4,
                cover_file_ids: [4],
            }),
        ],
    },
];

const audioIdsResponse: AudioIdsResponse = {
    ids: [1],
    sources: {
        1: 'Spotify',
    },
    source_ids: {
        1: 'spotify:1',
    },
    spotify_uris: {
        1: 'spotify:track:1',
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
    window.sessionStorage.removeItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY);
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('Audio playlists', () => {
    it('renders the audio playlist grid and opens a playlist from a card', async () => {
        mockAudioEndpoints();

        const { router, wrapper } = await mountAudioPlaylistGrid();
        await flushPromises();

        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/playlists');
        expect(wrapper.get('[data-test="audio-playlist-grid-page"]').classes()).toEqual(expect.arrayContaining([
            'h-full',
            'min-h-0',
            'overflow-hidden',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-grid-count"]').text()).toBe('4 playlists');
        expect(wrapper.findAll('[data-test="audio-playlist-card"]')).toHaveLength(4);
        expect(wrapper.findAll('[data-test="audio-playlist-grid-section"]')).toHaveLength(3);
        expect(wrapper.get('[data-test="audio-playlist-grid"]').text()).toContain('All audio');
        expect(wrapper.get('[data-test="audio-playlist-grid"]').text()).not.toContain('Every audio file');
        expect(wrapper.get('[data-test="audio-playlist-grid"]').text()).toContain('Late night');
        expect(wrapper.get('[data-test="audio-playlist-card"]').classes()).toEqual(expect.arrayContaining([
            'group',
            'block',
            'text-left',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-card-cover"]').classes()).toEqual(expect.arrayContaining([
            'aspect-square',
            'w-full',
        ]));
        expect(wrapper.findAll('[data-test="audio-playlist-card-title"]')[0]?.text()).toBe('All audio');
        expect(wrapper.findAll('[data-test="audio-playlist-card-count"]')[0]?.text()).toBe('3 tracks');
        expect(wrapper.findAll('[data-test="audio-playlist-card-cover"] img')[0]?.attributes('src')).toBe('/api/files/1/poster');

        const spotifyCard = wrapper
            .findAll('[data-test="audio-playlist-card"]')
            .find((card) => card.text().includes('Spotify'));

        expect(spotifyCard).toBeTruthy();
        await spotifyCard?.trigger('click');
        await flushPromises();

        expect(router.currentRoute.value.fullPath).toBe('/playlists/source-spotify');
    });

    it('opens an overlapping mobile playlist panel with desktop inline sizing', async () => {
        mockAudioEndpoints();

        const { wrapper } = await mountAudioPage();
        await flushPromises();

        expect(wrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="audio-playlist-panel-frame"]').classes()).toEqual(expect.arrayContaining([
            '-translate-x-full',
            'pointer-events-none',
            'duration-300',
            'md:w-0',
            'transition-[transform,opacity,width]',
        ]));

        await wrapper.get('[data-test="audio-playlists-cta"]').trigger('click');
        await flushPromises();

        expect(window.sessionStorage.getItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY)).toBe('1');
        expect(wrapper.get('[data-test="audio-library-surface"]').classes()).toContain('flex');
        expect(wrapper.get('[data-test="audio-library-surface"]').classes()).toContain('overflow-hidden');
        expect(wrapper.get('[data-test="audio-playlist-backdrop"]').classes()).toEqual(expect.arrayContaining([
            'absolute',
            'inset-0',
            'md:hidden',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-panel-frame"]').classes()).toEqual(expect.arrayContaining([
            'absolute',
            'translate-x-0',
            'md:relative',
            'md:w-72',
            'duration-500',
            'transition-[transform,opacity,width]',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-panel"]').classes()).toEqual(expect.arrayContaining([
            'h-full',
            'min-h-0',
            'w-full',
            'shrink-0',
            'overflow-hidden',
            'flex',
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
        expect(wrapper.findAll('[data-test="audio-playlist-cover"]')).toHaveLength(4);
        expect(wrapper.findAll('[data-test="audio-playlist-cover"] img')[0]?.attributes('src')).toBe('/api/files/1/poster');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Every audio file');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Source: Spotify');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('Manual playlist');
        expect(wrapper.get('[data-test="audio-playlist-scroll"]').classes()).toContain('[scrollbar-gutter:stable]');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').classes()).toContain('shrink-0');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').classes()).toContain('border-t');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').text()).toBe('Add playlist');
        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/playlists');
    });

    it('restores and persists the playlist panel session state', async () => {
        mockAudioEndpoints();
        window.sessionStorage.setItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY, '1');

        const { wrapper } = await mountAudioPage();
        await flushPromises();

        expect(wrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="audio-playlist-panel-frame"]').classes()).toEqual(expect.arrayContaining([
            'translate-x-0',
            'md:w-72',
            'duration-500',
        ]));
        expect(mockAxios.get).toHaveBeenCalledWith('/api/audio/playlists');

        await wrapper.get('[data-test="audio-playlists-cta"]').trigger('click');
        await flushPromises();

        expect(window.sessionStorage.getItem(AUDIO_PLAYLIST_PANEL_OPEN_STORAGE_KEY)).toBe('0');
        expect(wrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(false);
        expect(wrapper.get('[data-test="audio-playlist-panel-frame"]').classes()).toEqual(expect.arrayContaining([
            '-translate-x-full',
            'md:w-0',
            'duration-300',
        ]));

        wrapper.unmount();
        vi.clearAllMocks();

        const { wrapper: closedWrapper } = await mountAudioPage();
        await flushPromises();

        expect(closedWrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(false);
        expect(closedWrapper.get('[data-test="audio-playlist-panel-frame"]').classes()).toEqual(expect.arrayContaining([
            '-translate-x-full',
            'md:w-0',
            'duration-300',
        ]));
        expect(mockAxios.get).not.toHaveBeenCalledWith('/api/audio/playlists');
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
