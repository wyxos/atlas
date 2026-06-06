import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { mountAudio } from './audioTestUtils';
import { useGlobalAudioPlayer } from '../composables/useGlobalAudioPlayer';
import type { AudioDetailsResponse, AudioIdsResponse } from '@/types/audio';

const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    patch: vi.fn(),
};

type AudioDetailsItem = AudioDetailsResponse['items'][number];

function audioDetail(overrides: Partial<AudioDetailsItem> & Pick<AudioDetailsItem, 'id'>): AudioDetailsItem {
    return {
        title: `Track ${overrides.id}`,
        source: null,
        source_id: null,
        spotify_uri: null,
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

beforeEach(() => {
    vi.useFakeTimers();
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    document.querySelectorAll('meta[name="user-id"]').forEach((meta) => meta.remove());
    Object.assign(global.window, {
        axios: mockAxios,
        Echo: undefined,
    });
});

afterEach(() => {
    useGlobalAudioPlayer().clear();
    vi.useRealTimers();
    document.body.innerHTML = '';
    document.querySelectorAll('meta[name="user-id"]').forEach((meta) => meta.remove());
});

describe('Audio playlist membership invalidation', () => {
    it('removes rows that no longer belong to the active playlist when audio files change', async () => {
        const userMeta = document.createElement('meta');
        userMeta.setAttribute('name', 'user-id');
        userMeta.setAttribute('content', '42');
        document.head.append(userMeta);

        const listeners = new Map<string, (payload: unknown) => void>();
        const echoChannel = {
            listen: vi.fn((event: string, callback: (payload: unknown) => void) => {
                listeners.set(event, callback);

                return echoChannel;
            }),
        };
        const echo = {
            private: vi.fn(() => echoChannel),
            leave: vi.fn(),
        };
        Object.assign(global.window, {
            Echo: echo,
        });

        mockAxios.get.mockResolvedValue({
            data: {
                ids: [7, 8],
                sources: { 7: 'local', 8: 'local' },
                source_ids: { 7: null, 8: null },
                spotify_uris: { 7: null, 8: null },
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 8,
                },
                pagination: {
                    per_page: 100,
                    total: 2,
                    total_pages: 1,
                },
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockImplementation(async (url: string, payload?: { ids?: number[]; playlist?: string }) => {
            if (url === '/api/audio/details') {
                return {
                    data: {
                        items: (payload?.ids ?? []).map((id) => audioDetail({
                            id,
                            title: `Track ${id}`,
                            source: 'local',
                            artists: [`Artist ${id}`],
                            albums: [`Album ${id}`],
                            cover_url: id === 7 ? null : '/api/audio/album-covers/8',
                        })),
                    } satisfies AudioDetailsResponse,
                };
            }

            if (url === '/api/audio/playlists/membership') {
                return {
                    data: {
                        playlist: payload?.playlist,
                        files: [
                            { id: 7, is_member: false },
                            { id: 8, is_member: true },
                        ],
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio('/playlists/no-album-cover');
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        expect(wrapper.findAll('[data-test="audio-track-row"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Track 7');
        expect(wrapper.text()).toContain('Track 8');
        expect(echo.private).toHaveBeenCalledWith('App.Models.User.42');
        expect(echoChannel.listen).toHaveBeenCalledWith('.AudioFilesChanged', expect.any(Function));

        listeners.get('.AudioFilesChanged')?.({
            file_ids: [7, 8],
            reason: 'metadata_applied',
        });
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/playlists/membership', {
            playlist: 'no-album-cover',
            file_ids: [7, 8],
        });
        expect(wrapper.findAll('[data-test="audio-track-row"]')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('Track 7');
        expect(wrapper.text()).toContain('Track 8');
    });

    it('closes the track details sheet when the open track leaves the active playlist', async () => {
        const userMeta = document.createElement('meta');
        userMeta.setAttribute('name', 'user-id');
        userMeta.setAttribute('content', '42');
        document.head.append(userMeta);

        const listeners = new Map<string, (payload: unknown) => void>();
        const echoChannel = {
            listen: vi.fn((event: string, callback: (payload: unknown) => void) => {
                listeners.set(event, callback);

                return echoChannel;
            }),
        };
        const echo = {
            private: vi.fn(() => echoChannel),
            leave: vi.fn(),
        };
        Object.assign(global.window, {
            Echo: echo,
        });

        mockAxios.get.mockImplementation(async (url: string) => {
            if (url === '/api/audio/ids') {
                return {
                    data: {
                        ids: [7, 8],
                        sources: { 7: 'local', 8: 'local' },
                        source_ids: { 7: null, 8: null },
                        spotify_uris: { 7: null, 8: null },
                        cursor: {
                            after_id: 0,
                            next_after_id: null,
                            has_more: false,
                            max_id: 8,
                        },
                        pagination: {
                            per_page: 100,
                            total: 2,
                            total_pages: 1,
                        },
                    } satisfies AudioIdsResponse,
                };
            }

            if (url === '/api/audio/7/metadata-proposals/latest') {
                return { data: { proposal: null } };
            }

            throw new Error(`Unexpected get URL: ${url}`);
        });

        mockAxios.post.mockImplementation(async (url: string, payload?: { ids?: number[]; playlist?: string }) => {
            if (url === '/api/audio/details') {
                return {
                    data: {
                        items: (payload?.ids ?? []).map((id) => audioDetail({
                            id,
                            title: `Track ${id}`,
                            source: 'local',
                            artists: [`Artist ${id}`],
                            albums: [`Album ${id}`],
                            cover_url: null,
                        })),
                    } satisfies AudioDetailsResponse,
                };
            }

            if (url === '/api/audio/playlists/membership') {
                return {
                    data: {
                        playlist: payload?.playlist,
                        files: [
                            { id: 7, is_member: false },
                            { id: 8, is_member: true },
                        ],
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = await mountAudio('/playlists/no-album-cover');
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        await wrapper.get('[data-test="audio-track-title"]').trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[data-test="audio-track-metadata-run"]')).toBeInstanceOf(HTMLButtonElement);
        expect(document.body.textContent).toContain('Track 7');

        listeners.get('.AudioFilesChanged')?.({
            file_ids: [7, 8],
            reason: 'metadata_applied',
        });
        await flushPromises();

        expect(wrapper.findAll('[data-test="audio-track-row"]')).toHaveLength(1);
        expect(document.body.querySelector('[data-test="audio-track-metadata-run"]')).toBeNull();
        expect(document.body.textContent).not.toContain('Audio #7');
    });
});
