import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Index from '@/pages/photos/Index.vue';

const axiosMocks = vi.hoisted(() => ({
  get: vi.fn(() =>
    Promise.resolve({
      data: {
        grouped: { audio: [], video: [], image: [], other: [] },
        all: [],
      },
    }),
  ),
  post: vi.fn(() => Promise.resolve({ data: {} })),
}));

vi.mock('axios', () => ({
  default: axiosMocks,
}));

// Mock Inertia's Head
vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<template><slot /></template>' },
  useForm: (initial: any) => ({ ...initial, data: () => ({ ...initial }), defaults: () => {}, reset: () => {} }) as any,
  usePage: () => ({
    url: '/photos',
    props: {
      auth: {
        user: {
          is_admin: true,
        },
      },
    },
  }),
}));

// Provide a minimal Masonry stub that supports v-model:items and scroller methods
const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: false,
  props: { items: { type: Array, default: () => [] } },
  emits: ['update:items', 'backfill:start', 'backfill:tick', 'backfill:stop', 'retry:start', 'retry:tick', 'retry:stop'],
  methods: {
    init(files: any[]) {
      this.$emit('update:items', Array.isArray(files) ? files : []);
    },
    async loadNext() { return []; },
    reset() {},
    remove() {},
    async removeMany() {},
    refreshLayout() {},
    layout() {},
  },
  template: `
    <div data-test="masonry-stub">
      <slot name="item" v-for="(it, i) in items" :item="it" :index="i" />
    </div>
  `,
};

// SectionHeader stub to avoid passing icon object to DOM
const SectionHeaderStub = { name: 'SectionHeader', inheritAttrs: false, props: { title: String, icon: [Object, Function] }, template: '<div data-test="section-header">{{ title }}</div>' };

// Stub components used on the page to keep the test simple and avoid passing object attrs to DOM
const GridItemStub = { name: 'GridItem', inheritAttrs: false, props: { item: Object, fileForReactions: Object }, template: `<div />` };
const AppLayoutStub = { name: 'AppLayout', inheritAttrs: false, props: { breadcrumbs: { type: Array, default: () => [] } }, template: '<div><slot /></div>' };
const ContentLayoutStub = { name: 'ContentLayout', inheritAttrs: false, template: '<div><slot /></div>' };
const ScrollableLayoutStub = { name: 'ScrollableLayout', inheritAttrs: false, template: '<div><slot /></div>' };
const FullSizeViewerStub = { name: 'FullSizeViewer', inheritAttrs: false, props: { open: Boolean, item: Object, items: Array, scroller: Object }, template: '<div />' };

function mountPhotosWithTotal(total: number) {
  const files = [
    { id: 1, type: 'image', title: 'Sample', preview: 'about:blank', containers: [] },
  ];
  const filter = { limit: 40, page: 1, next: null, total } as any;

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

describe('Photos/Index.vue - total count', () => {
  it('renders the provided total count from filter', async () => {
    const wrapper = mountPhotosWithTotal(123);
    await nextTick();
    await nextTick();

    expect(wrapper.text()).toContain('total');
    expect(wrapper.text()).toContain('123');
  });
});
