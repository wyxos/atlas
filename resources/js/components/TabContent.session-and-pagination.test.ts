import { describe, it, expect, vi } from 'vitest';
import { flushPromises } from '@vue/test-utils';
import { nextTick } from 'vue';
import * as setup from './TabContent.test.setup';
import TabContent from './TabContent.vue';
import { index as browseIndex } from '@/actions/App/Http/Controllers/BrowseController';
import { BrowseFormKey } from '@/composables/useBrowseForm';
import TabFilter from './TabFilter.vue';

const {
    mount,
    mockAxios,
} = setup;

function createMockLocalItems(page: number, limit: number) {
    return Array.from({ length: limit }, (_, index) => {
        const id = (page * 1000) + index + 1;

        return {
            id,
            width: 500,
            height: 500,
            page,
            key: `${page}-${id}`,
            index,
            src: `https://example.com/preview-${id}.jpg`,
            preview: `https://example.com/preview-${id}.jpg`,
            original: `https://example.com/original-${id}.jpg`,
            type: 'image',
            notFound: false,
        };
    });
}

describe('TabContent - Resume Session', () => {
    it('uses tab.params.page when it is a numeric string (restoredPages should not default to 1)', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 123,
                    label: 'Browse 1',
                    params: {
                        page: 'CURSOR_NEXT',
                        service: 'test-service',
                    },
                    items: [
                        {
                            id: 1,
                            width: 500,
                            height: 500,
                            page: 5,
                            key: '5-1',
                            index: 0,
                            src: 'https://example.com/preview1.jpg',
                            preview: 'https://example.com/preview1.jpg',
                            original: 'https://example.com/original1.jpg',
                            type: 'image',
                            notFound: false,
                        },
                    ],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 123,
                availableServices: [{ key: 'test-service', label: 'Test Service' }],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.exists()).toBe(true);
        expect(masonry.props('restoredPages')).toBeUndefined();
        expect(masonry.props('page')).toBe('CURSOR_NEXT');
            // Restored sessions should keep backfill enabled for online browsing.
            expect(masonry.props('mode')).toBe('backfill');
    });
});
describe('TabContent - Local Page Jump', () => {
    it('respects the local page value when applying filters (does not force page 1)', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 444,
                    label: 'Browse 1',
                    params: {
                        feed: 'local',
                        source: 'all',
                        page: 1,
                        limit: 20,
                    },
                    items: [],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 444,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        // Simulate user entering a local page number in Advanced Filters.
        form.data.page = 50;

        const filter = wrapper.findComponent(TabFilter);
        expect(filter.exists()).toBe(true);
        filter.vm.$emit('apply');

        await nextTick();

        // Masonry should restart at the requested local page (numeric pagination).
        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.props('page')).toBe(50);
        expect(form.data.page).toBe(50);
    });

    it('renders the first-page control in the header actions and resets local pagination to page 1', async () => {
        mockAxios.get.mockResolvedValueOnce({
            data: {
                tab: {
                    id: 445,
                    label: 'Browse 1',
                    params: {
                        feed: 'local',
                        source: 'all',
                        page: 50,
                        limit: 20,
                    },
                    items: [],
                    position: 0,
                    isActive: true,
                },
            },
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 445,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        const firstPageButton = wrapper.find('[data-test="go-first-page-button"]');
        expect(firstPageButton.exists()).toBe(true);

        await firstPageButton.trigger('click');
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.props('page')).toBe(1);
        expect(form.data.page).toBe(1);
    });

    it('keeps limit 200 when reapplying local filters after loading the next page', async () => {
        const browseCalls: Array<{ page: string | null; limit: string | null; url: string }> = [];

        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/tabs/446')) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: 446,
                            label: 'Browse 1',
                            params: {
                                feed: 'local',
                                source: 'all',
                                page: 1,
                                limit: 20,
                            },
                            items: [],
                            position: 0,
                            isActive: true,
                        },
                    },
                });
            }

            if (url.includes(browseIndex.definition.url)) {
                const parsed = new URL(url, 'http://localhost');
                const page = parsed.searchParams.get('page');
                const limit = parsed.searchParams.get('limit');
                const requestedPage = Number(page ?? '1');
                const requestedLimit = Number(limit ?? '20');

                browseCalls.push({ page, limit, url });

                return Promise.resolve({
                    data: {
                        items: createMockLocalItems(requestedPage, requestedLimit),
                        nextPage: requestedPage + 1,
                    },
                });
            }

            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 446,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        form.data.limit = '200';
        form.data.page = 10;
        form.data.serviceFilters = {
            ...form.data.serviceFilters,
            local_preset: 'inbox_oldest',
            downloaded: 'any',
            reaction_mode: 'unreacted',
            blacklisted: 'no',
            auto_disliked: 'no',
            sort: 'created_at_asc',
        };

        const filter = wrapper.findComponent(TabFilter);
        expect(filter.exists()).toBe(true);
        filter.vm.$emit('apply');

        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        const masonryInstance = (masonry.vm as any).$?.exposed ?? masonry.vm;
        expect(masonry.props('page')).toBe(10);
        expect(masonry.props('pageSize')).toBe(200);

        const tabContentVm = wrapper.vm as any;
        const firstPage = await tabContentVm.getPage(masonry.props('page'));
        expect(browseCalls.at(-1)).toMatchObject({ page: '10', limit: '200' });

        masonryInstance.nextPage = firstPage.nextPage;
        await masonryInstance.loadNextPage();

        expect(browseCalls.at(-1)).toMatchObject({ page: '11', limit: '200' });
        expect(form.data.page).toBe(10);
        expect(form.data.limit).toBe('200');

        filter.vm.$emit('apply');
        await nextTick();

        expect(masonry.props('page')).toBe(10);
        expect(masonry.props('pageSize')).toBe(200);

        await tabContentVm.getPage(masonry.props('page'));
        expect(browseCalls.at(-1)).toMatchObject({ page: '10', limit: '200' });
    });

    it('keeps limit 200 when changing local page from 30 to 29 and reapplying filters', async () => {
        const browseCalls: Array<{ page: string | null; limit: string | null; url: string }> = [];

        mockAxios.get.mockImplementation((url: string) => {
            if (url.includes('/api/tabs/447')) {
                return Promise.resolve({
                    data: {
                        tab: {
                            id: 447,
                            label: 'Browse 1',
                            params: {
                                feed: 'local',
                                source: 'all',
                                page: 1,
                                limit: 20,
                            },
                            items: [],
                            position: 0,
                            isActive: true,
                        },
                    },
                });
            }

            if (url.includes(browseIndex.definition.url)) {
                const parsed = new URL(url, 'http://localhost');
                const page = parsed.searchParams.get('page');
                const limit = parsed.searchParams.get('limit');
                const requestedPage = Number(page ?? '1');
                const requestedLimit = Number(limit ?? '20');

                browseCalls.push({ page, limit, url });

                return Promise.resolve({
                    data: {
                        items: createMockLocalItems(requestedPage, requestedLimit),
                        nextPage: requestedPage + 1,
                    },
                });
            }

            return Promise.resolve({ data: { items: [], nextPage: null } });
        });

        const wrapper = mount(TabContent, {
            props: {
                tabId: 447,
                availableServices: [],
                onReaction: vi.fn(),
                updateActiveTab: vi.fn(),
            },
        });

        await flushPromises();

        const form = (wrapper.vm as any).$?.provides?.[BrowseFormKey];
        expect(form).toBeTruthy();

        form.data.limit = '200';
        form.data.page = 30;
        form.data.serviceFilters = {
            ...form.data.serviceFilters,
            local_preset: 'inbox_oldest',
            downloaded: 'any',
            reaction_mode: 'unreacted',
            blacklisted: 'no',
            auto_disliked: 'no',
            sort: 'created_at_asc',
        };

        const filter = wrapper.findComponent(TabFilter);
        expect(filter.exists()).toBe(true);

        filter.vm.$emit('apply');
        await nextTick();

        const masonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(masonry.props('page')).toBe(30);
        expect(masonry.props('pageSize')).toBe(200);

        const tabContentVm = wrapper.vm as any;
        const page30 = await tabContentVm.getPage(masonry.props('page'));
        expect(page30.items).toHaveLength(200);
        expect(browseCalls.at(-1)).toMatchObject({ page: '30', limit: '200' });

        form.data.page = 29;
        filter.vm.$emit('apply');
        await nextTick();

        const refreshedMasonry = wrapper.findComponent({ name: 'MasonryGrid' });
        expect(refreshedMasonry.props('page')).toBe(29);
        expect(refreshedMasonry.props('pageSize')).toBe(200);
        expect(form.data.limit).toBe('200');

        const page29 = await tabContentVm.getPage(refreshedMasonry.props('page'));
        expect(page29.items).toHaveLength(200);
        expect(browseCalls.at(-1)).toMatchObject({ page: '29', limit: '200' });
    });
});
