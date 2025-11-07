import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Index from '@/pages/photos/Index.vue';

const axiosMocks = vi.hoisted(() => ({
  get: vi.fn(() => Promise.resolve({ data: {} })),
  post: vi.fn(() => Promise.resolve({ data: {} })),
}));

vi.mock('axios', () => ({
  default: axiosMocks,
}));

vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<template><slot /></template>' },
  useForm: (initial: any) => ({ ...initial, data: () => ({ ...initial }), defaults: () => {}, reset: () => {} }) as any,
  usePage: () => ({
    props: {
      auth: {
        user: {
          is_admin: true,
        },
      },
    },
  }),
}));

vi.mock('@/composables/useMimeTypes', () => {
  const fetchStub = vi.fn(() => Promise.resolve());
  return {
    useMimeTypes: () => ({
      loading: false,
      fetch: fetchStub,
      getGrouped: () => ({ image: [] }),
    }),
  };
});

const loadNextSpy = vi.fn(async () => []);

const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: false,
  props: { items: { type: Array, default: () => [] } },
  emits: [
    'update:items',
    'backfill:start',
    'backfill:tick',
    'backfill:stop',
    'retry:start',
    'retry:tick',
    'retry:stop',
    'remove-all:complete',
  ],
  methods: {
    init(this: any, files: any[]) {
      this.$emit('update:items', Array.isArray(files) ? files : []);
    },
    async loadNext() {
      return loadNextSpy();
    },
    async loadPage() {
      return [];
    },
    reset() {},
    remove() {},
    async removeMany() {},
    async removeAll() {},
    refreshLayout() {},
    refreshCurrentPage() {},
    cancelLoad() {},
  },
  template: `
    <div data-test="masonry-stub">
      <slot name="item" v-for="(it, i) in items" :item="it" :index="i" />
    </div>
  `,
};

const SectionHeaderStub = { name: 'SectionHeader', inheritAttrs: false, props: { title: String, icon: [Object, Function] }, template: '<div data-test="section-header">{{ title }}</div>' };
const GridItemStub = { name: 'GridItem', inheritAttrs: false, props: { item: Object, fileForReactions: Object }, template: `<div />` };
const AppLayoutStub = { name: 'AppLayout', inheritAttrs: false, props: { breadcrumbs: { type: Array, default: () => [] } }, template: '<div><slot /></div>' };
const ContentLayoutStub = { name: 'ContentLayout', inheritAttrs: false, template: '<div><slot /></div>' };
const ScrollableLayoutStub = { name: 'ScrollableLayout', inheritAttrs: false, template: '<div><slot /></div>' };
const FullSizeViewerStub = { name: 'FullSizeViewer', inheritAttrs: false, props: { open: Boolean, item: Object, items: Array, scroller: Object }, template: '<div />' };

function mountPhotos() {
  const files = [
    { id: 1, type: 'image', title: 'Sample', preview: 'about:blank', containers: [] },
  ];
  const filter = { limit: 40, page: 1, next: null, total: 1 } as any;

  return mount(Index, {
    props: { files, filter },
    global: {
      stubs: {
        Masonry: MasonryStub,
        GridItem: GridItemStub,
        FullSizeViewer: FullSizeViewerStub,
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: SectionHeaderStub,
      },
    },
    attachTo: document.body,
  });
}

describe('Photos/Index.vue - auto load guard', () => {
  beforeEach(() => {
    loadNextSpy.mockClear();
  });

  it('does not call loadNext when no next page is available', async () => {
    const wrapper = mountPhotos();
    await nextTick();
    await nextTick();

    expect(loadNextSpy).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});


