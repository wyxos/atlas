import { describe, it, expect, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { defineComponent, h, nextTick, reactive } from 'vue';

const stubComponent = (name: string) =>
    defineComponent({
        name,
        setup(_, { slots }) {
            return () => h('div', { class: name }, slots.default ? slots.default() : []);
        },
    });

const scrollerSpies = {
    init: vi.fn(),
    reset: vi.fn(),
    loadNext: vi.fn().mockResolvedValue(undefined),
    loadPage: vi.fn().mockResolvedValue(undefined),
    cancelLoad: vi.fn(),
    removeAll: vi.fn().mockResolvedValue(undefined),
    refreshLayout: vi.fn(),
} as Record<string, ReturnType<typeof vi.fn>>;

vi.stubGlobal(
    'IntersectionObserver',
    class {
        observe() {}
        unobserve() {}
        disconnect() {}
    },
);

if (typeof globalThis.requestAnimationFrame !== 'function') {
    vi.stubGlobal('requestAnimationFrame', (cb: (...args: any[]) => void) => setTimeout(cb, 0));
}

const axiosGet = vi.fn().mockResolvedValue({ data: { files: [], filter: { next: null } } });
const axiosPost = vi.fn().mockResolvedValue({});
vi.mock('axios', () => ({
    default: {
        get: axiosGet,
        post: axiosPost,
    },
}));

vi.mock('@inertiajs/vue3', () => {
    const Head = stubComponent('HeadStub');
    const router = { replace: vi.fn() };

    function useForm(initial: Record<string, any> = {}) {
        const defaults = reactive({ ...initial });
        const state = reactive({ ...initial });

        const api = {
            data() {
                return { ...state };
            },
            defaults(values: Record<string, any>) {
                Object.assign(defaults, values);
            },
            reset() {
                for (const key of Object.keys(state)) {
                    delete (state as any)[key];
                }
                Object.assign(state, JSON.parse(JSON.stringify(defaults)));
            },
        } satisfies Record<string, (...args: any[]) => any>;

        return new Proxy(state, {
            get(target, prop, receiver) {
                if (prop in api) {
                    return api[prop as keyof typeof api];
                }
                return Reflect.get(target, prop, receiver);
            },
            set(target, prop, value, receiver) {
                return Reflect.set(target, prop, value, receiver);
            },
            has(target, prop) {
                if (prop in api) return true;
                return Reflect.has(target, prop);
            },
            ownKeys(target) {
                return Reflect.ownKeys(target);
            },
            getOwnPropertyDescriptor(target, prop) {
                if (prop in api) {
                    return {
                        configurable: true,
                        enumerable: false,
                        value: api[prop as keyof typeof api],
                        writable: false,
                    };
                }
                return Reflect.getOwnPropertyDescriptor(target, prop as string);
            },
        }) as any;
    }

    return {
        Head,
        useForm,
        router,
        usePage: () => ({
            props: {
                auth: {
                    user: {
                        is_admin: true,
                    },
                },
            },
        }),
    };
});

vi.mock('@wyxos/vibe', () => ({
    Masonry: defineComponent({
        name: 'MasonryStub',
        props: {
            items: { type: Array, default: () => [] },
        },
        emits: ['update:items'],
        setup(_props: unknown, context: { expose: (value: any) => void }) {
            context.expose({
                ...scrollerSpies,
                get isLoading() {
                    return false;
                },
                get totalItems() {
                    return 0;
                },
            });
            return () => h('div');
        },
    }),
}));

vi.mock('@/components/ui/button', () => ({ Button: stubComponent('UIButton') }));
vi.mock('@/components/ui/dialog', () => ({
    Dialog: stubComponent('DialogStub'),
    DialogDescription: stubComponent('DialogDescriptionStub'),
    DialogScrollContent: stubComponent('DialogScrollContentStub'),
    DialogTitle: stubComponent('DialogTitleStub'),
}));
vi.mock('@/components/ui/label', () => ({ Label: stubComponent('LabelStub') }));
vi.mock('@/components/audio/SectionHeader.vue', () => ({ default: stubComponent('SectionHeaderStub') }));
vi.mock('@/components/browse/GridItem.vue', () => ({ default: stubComponent('GridItemStub') }));
vi.mock('@/layouts/AppLayout.vue', () => ({ default: stubComponent('AppLayoutStub') }));
vi.mock('@/layouts/ContentLayout.vue', () => ({ default: stubComponent('ContentLayoutStub') }));
vi.mock('@/layouts/ScrollableLayout.vue', () => ({ default: stubComponent('ScrollableLayoutStub') }));
vi.mock('@/pages/browse/FullSizeViewer.vue', () => ({ default: stubComponent('FullSizeViewerStub') }));
vi.mock('@/lib/moderation', () => ({ enqueueModeration: vi.fn(), flushModeration: vi.fn() }));
vi.mock('@/lib/undo', () => ({ undoManager: { push: vi.fn() } }));
vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
    reactDownload: vi.fn(() => ({ url: '/react-download' })),
    dislikeBlacklist: vi.fn(() => ({ url: '/dislike' })),
    react: vi.fn(() => ({ url: '/react' })),
    batchReact: vi.fn(() => ({ url: '/batch-react' })),
    batchUnblacklist: vi.fn(() => ({ url: '/batch-unblacklist' })),
}));
vi.mock('@/actions/App/Http/Controllers/PhotosController', () => ({
    default: {
        data: () => ({ url: '/photos/data' }),
    },
}));

vi.mock('lucide-vue-next', () => ({
    Image: stubComponent('ImageIcon'),
    Hash: stubComponent('HashIcon'),
    ChevronsRight: stubComponent('ChevronsRightIcon'),
    List: stubComponent('ListIcon'),
    Loader2: stubComponent('LoaderIcon'),
    Shuffle: stubComponent('ShuffleIcon'),
    ChevronsLeft: stubComponent('ChevronsLeftIcon'),
    RefreshCw: stubComponent('RefreshIcon'),
    X: stubComponent('XIcon'),
}));

const PhotosIndex = (await import('@/pages/photos/Index.vue')).default;

describe('PhotosIndex manual filter workflow', () => {
    beforeEach(() => {
        Object.values(scrollerSpies).forEach((spy) => spy.mockClear());
        axiosGet.mockClear();
        axiosPost.mockClear();
    });

    it('marks filters dirty and clears them after apply', async () => {
        const wrapper = mount(PhotosIndex, {
            props: {
                files: [],
                filter: { sort: 'newest', limit: 40, source: null },
                moderation: null,
            },
            global: {
                stubs: { transition: false, teleport: true },
            },
        });

        await flushPromises();
        scrollerSpies.reset.mockClear();
        scrollerSpies.loadNext.mockClear();

        expect(wrapper.vm.filtersDirty).toBe(false);

        wrapper.vm.form.sort = 'random';
        await nextTick();

        expect(wrapper.vm.filtersDirty).toBe(true);

        await wrapper.vm.applyFilters();
        await flushPromises();
        await nextTick();

        expect(scrollerSpies.reset).toHaveBeenCalledTimes(1);
        expect(scrollerSpies.loadPage).toHaveBeenCalledTimes(1);
        expect(wrapper.vm.filtersDirty).toBe(false);
        expect(wrapper.vm.filtersBusy).toBe(false);
    });
});
