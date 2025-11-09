import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Index from '@/pages/photos/Index.vue';

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

// Mock axios to avoid network
vi.mock('axios', () => ({
  default: { post: vi.fn(() => Promise.resolve({ data: {} })) },
}));

// Provide a minimal Masonry stub that supports v-model:items and scroller methods
const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: true,
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
    <div data-test="masonry-stub" v-bind="$attrs">
      <slot name="item" v-for="(it, i) in items" :item="it" :index="i" />
    </div>
  `,
};

// Stub GridItem to emit `open` when clicked
const GridItemStub = {
  name: 'GridItem',
  props: { item: { type: Object, required: true }, fileForReactions: { type: Object, required: false } },
  emits: ['open'],
  template: `<button data-test="grid-item" @click="$emit('open', item)">GridItem {{ item?.id }}</button>`,
};

// Stub FullSizeViewer to reflect v-model:open
const FullSizeViewerStub = {
  name: 'FullSizeViewer',
  props: { open: { type: Boolean, default: false }, item: { type: Object, default: null }, items: { type: Array, default: () => [] }, scroller: { type: Object, default: null } },
  emits: ['update:open', 'update:item', 'favorite', 'like', 'dislike', 'laughed-at'],
  template: `
    <div data-test="full-viewer" v-if="open">
      <div data-test="full-viewer-open">open</div>
      <div data-test="full-viewer-item">{{ item?.id ?? '' }}</div>
    </div>
  `,
};

function mountPhotos(overrides: Record<string, any> = {}) {
  const files = overrides.files ?? [
    { id: 1, type: 'image', title: 'Sample', preview: 'about:blank', containers: [{ key: 'tag', value: 'x', label: 'x' }] },
  ];
  const filter = overrides.filter ?? { limit: 40, page: 1, next: null };

  // Simple layout stubs that render slots
  const AppLayoutStub = { name: 'AppLayout', template: '<div data-test="app-layout"><slot /></div>' };
  const ContentLayoutStub = { name: 'ContentLayout', template: '<div data-test="content-layout"><slot /></div>' };
  const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div data-test="scrollable-layout"><slot /></div>' };

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
        SectionHeader: true,
      },
    },
    attachTo: document.body,
  });
}

describe('Photos/Index.vue - full size viewer', () => {
  it('opens full size viewer when a grid item is clicked', async () => {
    const wrapper = mountPhotos();
    await nextTick();
    await nextTick();

    const first = wrapper.get('[data-test="grid-item"]');
    await first.trigger('click');
    await nextTick();

    // Full viewer stub should be visible
    const viewer = wrapper.find('[data-test="full-viewer-open"]');
    expect(viewer.exists()).toBe(true);
  });

  it('shows the total count of files', async () => {
    const wrapper = mountPhotos();
    await nextTick();
    await nextTick();

    // With 1 initial file and no total from scroller, fallback should be items.length
    expect(wrapper.text()).toContain('total');
    expect(wrapper.text()).toContain('1');
  });
});
