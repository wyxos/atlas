import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Audio from './Audio.vue';

type AudioIdsResponse = {
    ids: number[];
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
};

beforeEach(() => {
    vi.clearAllMocks();
    vi.spyOn(console, 'error').mockImplementation(() => {});
    Object.assign(global.window, {
        axios: mockAxios,
    });
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
        expect(wrapper.findAll('li').map((row) => row.text())).toEqual(['101', '202', '303']);
    });

    it('shows an error when loading ids fails', async () => {
        mockAxios.get.mockRejectedValue(new Error('Network failure'));

        const wrapper = mount(Audio);
        await flushPromises();

        expect(wrapper.text()).toContain('Failed to load audio IDs.');
    });
});
