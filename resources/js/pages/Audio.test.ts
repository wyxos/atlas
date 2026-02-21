import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import Audio from './Audio.vue';

type AudioIdsResponse = {
    ids: number[];
    pagination: {
        page: number;
        per_page: number;
        total: number;
        total_pages: number;
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
    it('loads page 1 first, then appends ids as later pages resolve', async () => {
        const pageTwo = createDeferred<{ data: AudioIdsResponse }>();
        const pageThree = createDeferred<{ data: AudioIdsResponse }>();

        mockAxios.get.mockImplementation((_url: string, config?: { params?: { page?: number } }) => {
            const page = config?.params?.page;

            if (page === 1) {
                return Promise.resolve({
                    data: {
                        ids: [101],
                        pagination: {
                            page: 1,
                            per_page: 500,
                            total: 3,
                            total_pages: 3,
                        },
                    },
                });
            }

            if (page === 2) {
                return pageTwo.promise;
            }

            if (page === 3) {
                return pageThree.promise;
            }

            return Promise.reject(new Error(`Unexpected page: ${String(page)}`));
        });

        const wrapper = mount(Audio);
        await flushPromises();

        expect(mockAxios.get).toHaveBeenNthCalledWith(1, '/api/audio/ids', {
            params: {
                page: 1,
                per_page: 500,
            },
        });
        expect(mockAxios.get).toHaveBeenNthCalledWith(2, '/api/audio/ids', {
            params: {
                page: 2,
                per_page: 500,
            },
        });
        expect(wrapper.text()).toContain('Pages: 1 / 3');
        expect(wrapper.text()).toContain('IDs loaded: 1 / 3');
        expect(wrapper.findAll('li')).toHaveLength(1);
        expect(wrapper.find('li').text()).toBe('101');

        pageTwo.resolve({
            data: {
                ids: [202],
                pagination: {
                    page: 2,
                    per_page: 500,
                    total: 3,
                    total_pages: 3,
                },
            },
        });
        await flushPromises();

        expect(mockAxios.get).toHaveBeenNthCalledWith(3, '/api/audio/ids', {
            params: {
                page: 3,
                per_page: 500,
            },
        });
        expect(wrapper.text()).toContain('Pages: 2 / 3');
        expect(wrapper.text()).toContain('IDs loaded: 2 / 3');
        expect(wrapper.findAll('li').map((row) => row.text())).toEqual(['101', '202']);

        pageThree.resolve({
            data: {
                ids: [303],
                pagination: {
                    page: 3,
                    per_page: 500,
                    total: 3,
                    total_pages: 3,
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
