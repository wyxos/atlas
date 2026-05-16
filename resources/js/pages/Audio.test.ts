import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Audio from './Audio.vue';
import PageLayout from '../components/PageLayout.vue';
import VirtualList from '../components/VirtualList.vue';
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

function createDeferred<T>() {
    let resolve: ((value: T | PromiseLike<T>) => void) | null = null;
    const promise = new Promise<T>((innerResolve) => {
        resolve = innerResolve;
    });

    return {
        promise,
        resolve: (value: T) => {
            if (resolve) {
                resolve(value);
            }
        },
    };
}

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

describe('Audio', () => {
    it('loads ids with cursor pagination and renders list only after all chunks are loaded', async () => {
        const pageTwo = createDeferred<{ data: AudioIdsResponse }>();
        const pageThree = createDeferred<{ data: AudioIdsResponse }>();

        mockAxios.get.mockImplementation((_url: string, config?: { params?: { after_id?: number; max_id?: number } }) => {
            const afterId = config?.params?.after_id;

            if (afterId === 0) {
                return Promise.resolve({
                    data: {
                        ids: [101],
                        sources: {
                            101: 'Spotify',
                        },
                        cursor: {
                            after_id: 0,
                            next_after_id: 101,
                            has_more: true,
                            max_id: 303,
                        },
                        pagination: {
                            per_page: 100,
                            total: 3,
                            total_pages: 3,
                        },
                    },
                });
            }

            if (afterId === 101) {
                return pageTwo.promise;
            }

            if (afterId === 202) {
                return pageThree.promise;
            }

            return Promise.reject(new Error(`Unexpected cursor: ${String(afterId)}`));
        });

        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    audioDetail({ id: 101, title: 'Track 101', source: 'Spotify', artists: ['Artist A'], albums: ['Album A'] }),
                    audioDetail({ id: 202, title: 'Track 202', source: 'YouTube', artists: ['Artist B'], albums: ['Album B'] }),
                    audioDetail({ id: 303, title: 'Track 303', source: null, artists: ['Artist C'], albums: ['Album C'] }),
                ],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = mount(Audio);
        await flushPromises();

        expect(mockAxios.get).toHaveBeenNthCalledWith(1, '/api/audio/ids', {
            params: {
                after_id: 0,
                per_page: 100,
            },
        });
        expect(mockAxios.get).toHaveBeenNthCalledWith(2, '/api/audio/ids', {
            params: {
                after_id: 101,
                max_id: 303,
                per_page: 100,
            },
        });
        expect(wrapper.text()).toContain('Pages: 1 / 3');
        expect(wrapper.text()).toContain('IDs loaded: 1 / 3');
        expect(wrapper.findAll('li')).toHaveLength(0);
        expect(wrapper.text()).toContain('Preparing full audio index...');

        pageTwo.resolve({
            data: {
                ids: [202],
                sources: {
                    202: 'YouTube',
                },
                cursor: {
                    after_id: 101,
                    next_after_id: 202,
                    has_more: true,
                    max_id: 303,
                },
                pagination: {
                    per_page: 100,
                    total: null,
                    total_pages: null,
                },
            },
        });
        await flushPromises();

        expect(mockAxios.get).toHaveBeenNthCalledWith(3, '/api/audio/ids', {
            params: {
                after_id: 202,
                max_id: 303,
                per_page: 100,
            },
        });
        expect(wrapper.text()).toContain('Pages: 2 / 3');
        expect(wrapper.text()).toContain('IDs loaded: 2 / 3');
        expect(wrapper.findAll('li')).toHaveLength(0);

        pageThree.resolve({
            data: {
                ids: [303],
                sources: {
                    303: null,
                },
                cursor: {
                    after_id: 202,
                    next_after_id: null,
                    has_more: false,
                    max_id: 303,
                },
                pagination: {
                    per_page: 100,
                    total: null,
                    total_pages: null,
                },
            },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Pages: 3 / 3');
        expect(wrapper.text()).toContain('100%');
        expect(wrapper.text()).toContain('IDs loaded: 3 / 3');
        expect(wrapper.findAll('[data-slot="skeleton"]').length).toBeGreaterThan(0);

        vi.advanceTimersByTime(180);
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/audio/details', {
            ids: [101, 202, 303],
        }, expect.objectContaining({
            signal: expect.any(AbortSignal),
        }));
        expect(wrapper.text()).toContain('Track 101');
        expect(wrapper.text()).not.toContain('Not Spotify');
        expect(wrapper.text()).toContain('Artist A');
        expect(wrapper.text()).toContain('Album A');
    });

    it('uses a flush full-height list layout without the redundant page heading', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
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
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    audioDetail({ id: 1, title: 'Track 1', source: 'Spotify', artists: ['Artist 1'], albums: ['Album 1'] }),
                ],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = mount(Audio);
        await flushPromises();

        expect(wrapper.findComponent(PageLayout).props('flush')).toBe(true);
        expect(wrapper.find('h4').exists()).toBe(false);
        expect(wrapper.get('[data-test="audio-page"]').classes()).toEqual(expect.arrayContaining([
            'h-full',
            'min-h-0',
            'overflow-hidden',
        ]));
        expect(wrapper.get('[data-test="audio-list-shell"]').classes()).toEqual(expect.arrayContaining([
            'flex',
            'min-h-0',
            'flex-1',
            'flex-col',
        ]));
        expect(wrapper.get('[data-test="audio-list-header"]').classes()).toEqual(expect.arrayContaining([
            'h-10',
            'justify-between',
            'border-b',
        ]));
        expect(wrapper.get('[data-test="audio-list-header"]').text()).toContain('Playlists');
        expect(wrapper.get('[data-test="audio-list-header"]').text()).toContain('Filter: All');
        expect(wrapper.findComponent(VirtualList).props('containerClass')).toBe('min-h-0 flex-1 overflow-y-auto');
        expect(wrapper.findComponent(VirtualList).props('itemHeight')).toBe(72);
    });

    it('opens a non-overlay playlist panel beside the audio list', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
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
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockResolvedValue({
            data: {
                items: [
                    audioDetail({ id: 1, title: 'Track 1', source: 'Spotify', artists: ['Artist 1'], albums: ['Album 1'] }),
                ],
            } satisfies AudioDetailsResponse,
        });

        const wrapper = mount(Audio);
        await flushPromises();

        expect(wrapper.find('[data-test="audio-playlist-panel"]').exists()).toBe(false);

        await wrapper.get('[data-test="audio-playlists-cta"]').trigger('click');

        expect(wrapper.get('[data-test="audio-library-surface"]').classes()).toContain('flex');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').classes()).toEqual(expect.arrayContaining([
            'w-72',
            'shrink-0',
            'md:flex',
        ]));
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).not.toContain('PLAYLISTS');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Spotify favorites');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Source: Spotify / Reaction: favorite');
        expect(wrapper.get('[data-test="audio-playlist-panel"]').text()).toContain('Banned from feed');
        expect(wrapper.get('[data-test="audio-add-playlist-cta"]').text()).toBe('Add playlist');
    });

    it('renders desktop rows with Spotify-style columns and routes reactions', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                ids: [7],
                sources: {
                    7: 'Spotify',
                },
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
            } satisfies AudioIdsResponse,
        });

        mockAxios.post.mockImplementation(async (url: string) => {
            if (url === '/api/audio/details') {
                return {
                    data: {
                        items: [
                            audioDetail({
                                id: 7,
                                title: 'Seed Track',
                                source: 'Spotify',
                                artists: ['Artist A', 'Artist B'],
                                albums: ['Album A'],
                                cover_url: '/api/files/7/poster',
                                duration_seconds: 185,
                                reaction: { type: 'love' },
                            }),
                        ],
                    } satisfies AudioDetailsResponse,
                };
            }

            if (url === '/api/files/7/reaction') {
                return {
                    data: {
                        reaction: { type: 'like' },
                    },
                };
            }

            if (url === '/api/files/blacklist/batch') {
                return {
                    data: {
                        results: [
                            {
                                id: 7,
                                blacklisted_at: '2026-05-16T10:00:00+04:00',
                                previewed_count: 99999,
                            },
                        ],
                    },
                };
            }

            throw new Error(`Unexpected post URL: ${url}`);
        });

        const wrapper = mount(Audio);
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        const row = wrapper.get('li');
        expect(row.classes()).toContain('grid');
        expect(row.classes()).toContain('grid-cols-[2.5rem_minmax(0,1fr)_3rem]');
        expect(row.classes()).toContain('md:grid-cols-[3rem_minmax(18rem,32rem)_minmax(12rem,1fr)_minmax(10rem,auto)_5rem]');
        expect(row.classes()).toContain('gap-2');
        expect(row.classes()).toContain('md:gap-4');
        expect(row.text()).toContain('1');
        expect(row.text()).toContain('Seed Track');
        expect(row.text()).toContain('Artist A, Artist B');
        expect(row.text()).toContain('Album A');
        expect(row.text()).toContain('3:05');
        expect(row.find('img').attributes('src')).toBe('/api/files/7/poster');
        expect(row.get('[data-test="audio-track-title-cell"]').classes()).toContain('min-w-0');
        expect(row.get('[data-test="audio-track-album"]').classes()).toContain('hidden');
        expect(row.get('[data-test="audio-track-album"]').classes()).toContain('md:block');
        expect(row.get('[data-test="audio-track-duration"]').classes()).toContain('tabular-nums');
        expect(row.get('[aria-label="Favorite"]').exists()).toBe(true);
        expect(row.get('[aria-label="Blacklist"]').exists()).toBe(true);
        expect(row.get('[data-test="file-reactions"]').classes()).toContain('gap-3');
        expect(row.get('[data-test="file-reactions"]').classes()).not.toContain('bg-black/60');
        expect(row.get('[aria-label="Blacklist"] svg').attributes('width')).toBe('23');

        await row.get('[aria-label="Like"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/7/reaction', {
            type: 'like',
        });

        await row.get('[aria-label="Blacklist"]').trigger('click');
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledWith('/api/files/blacklist/batch', {
            file_ids: [7],
        });
        expect(row.get('[aria-label="Blacklist"]').attributes('disabled')).toBeDefined();
    });

    it('debounces scroll and fetches unseen visible item details only', async () => {
        const ids = Array.from({ length: 20 }, (_, index) => index + 1);

        mockAxios.get.mockResolvedValue({
            data: {
                ids,
                sources: Object.fromEntries(ids.map((id) => [id, id % 2 === 0 ? 'Spotify' : 'local'])),
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

        mockAxios.post.mockImplementation(async (_url: string, payload: { ids: number[] }) => ({
            data: {
                items: payload.ids.map((id) => ({
                    ...audioDetail({
                        id,
                        title: `Track ${id}`,
                        source: id % 2 === 0 ? 'Spotify' : 'Local',
                        artists: [`Artist ${id}`],
                        albums: [`Album ${id}`],
                    }),
                })),
            } satisfies AudioDetailsResponse,
        }));

        const wrapper = mount(Audio);
        await flushPromises();

        vi.advanceTimersByTime(180);
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledTimes(1);
        const initialIds = (mockAxios.post.mock.calls[0]?.[1] as { ids: number[] }).ids;
        expect(initialIds).toContain(1);
        expect(initialIds).not.toContain(20);

        const virtualList = wrapper.findComponent(VirtualList);
        virtualList.vm.$emit('visible-items-change', [20]);
        virtualList.vm.$emit('scroll', 500);
        virtualList.vm.$emit('scroll', 700);

        vi.advanceTimersByTime(180);
        await flushPromises();

        expect(mockAxios.post).toHaveBeenCalledTimes(2);
        expect(mockAxios.post.mock.calls[1]?.[1]).toEqual({
            ids: [20],
        });
    });

    it('shows an error when loading ids fails', async () => {
        mockAxios.get.mockRejectedValue(new Error('Network failure'));

        const wrapper = mount(Audio);
        await flushPromises();

        expect(wrapper.text()).toContain('Failed to load audio IDs.');
    });

    it('pads the filter sheet body on mobile', async () => {
        mockAxios.get.mockResolvedValue({
            data: {
                ids: [],
                sources: {},
                cursor: {
                    after_id: 0,
                    next_after_id: null,
                    has_more: false,
                    max_id: 0,
                },
                pagination: {
                    per_page: 100,
                    total: 0,
                    total_pages: 0,
                },
            } satisfies AudioIdsResponse,
        });

        const wrapper = mount(Audio, {
            attachTo: document.body,
        });
        await flushPromises();

        await wrapper.get('[data-test="audio-filter-cta"]').trigger('click');
        await flushPromises();

        const sheetBody = document.body.querySelector('[data-test="audio-filter-sheet-body"]');

        expect(sheetBody).not.toBeNull();
        expect(sheetBody?.classList.contains('px-6')).toBe(true);
    });
});
