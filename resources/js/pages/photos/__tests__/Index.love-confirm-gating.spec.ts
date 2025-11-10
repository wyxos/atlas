import { describe, it, expect, vi, beforeEach } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Index from '@/pages/photos/Index.vue';

// Mock Inertia's Head and useForm
vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<template><slot /></template>' },
  useForm: (initial: any) => ({ ...initial, data: () => ({ ...initial }), defaults: () => {}, reset: () => {} }) as any,
  usePage: () => ({ url: '/photos', props: { auth: { user: { is_admin: true } } } }),
}));

// Mock axios
vi.mock('axios', () => ({ default: { post: vi.fn(() => Promise.resolve({ data: {} })) } }));

// Mock controller URLs
vi.mock('@/actions/App/Http/Controllers/BrowseController', () => ({
  reactDownload: ({ file }: any) => ({ url: `/browse/react-download/${file}` }),
  react: ({ file }: any) => ({ url: `/browse/react/${file}` }),
}), { virtual: true });

const MasonryStub = {
  name: 'Masonry',
  inheritAttrs: true,
  props: { items: { type: Array, default: () => [] } },
  emits: ['update:items'],
  methods: { init(files: any[]) { this.$emit('update:items', Array.isArray(files) ? files : []); } },
  template: `<div><slot name="item" v-for="(it,i) in items" :item="it" :index="i" /></div>`,
};

// GridItem with favorite trigger
const GridItemStub = {
  name: 'GridItem',
  props: { item: { type: Object, required: true }, fileForReactions: { type: Object, required: false } },
  emits: ['open', 'favorite', 'like', 'dislike', 'laughed-at'],
  template: `
    <div data-test="grid-item">
      <button data-test="favorite" @click="$emit('favorite', item, $event)">favorite</button>
    </div>
  `,
};

// Dialog stub that only renders slot when open is true
const DialogStub = {
  name: 'Dialog',
  props: { open: { type: Boolean, default: false } },
  template: `<div v-if="open"><slot /></div>`,
};
const DialogScrollContentStub = { name: 'DialogScrollContent', template: '<div><slot /></div>' };
const DialogTitleStub = { name: 'DialogTitle', template: '<div><slot /></div>' };
const DialogDescriptionStub = { name: 'DialogDescription', template: '<div><slot /></div>' };

// FullSizeViewer pass-through
const FullSizeViewerStub = {
  name: 'FullSizeViewer',
  props: { open: { type: Boolean, default: false }, item: { type: Object, default: null } },
  emits: ['update:open', 'update:item', 'favorite', 'like', 'dislike', 'laughed-at'],
  template: `<div></div>`,
};

function mountPhotos(files: any[]) {
  const filter = { limit: 40, page: 1, next: null };
  const AppLayoutStub = { name: 'AppLayout', template: '<div><slot /></div>' };
  const ContentLayoutStub = { name: 'ContentLayout', template: '<div><slot /></div>' };
  const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div><slot /></div>' };
  return mount(Index, {
    props: { files, filter },
    global: {
      stubs: {
        Masonry: MasonryStub,
        GridItem: GridItemStub,
        FullSizeViewer: FullSizeViewerStub,
        Dialog: DialogStub,
        DialogScrollContent: DialogScrollContentStub,
        DialogTitle: DialogTitleStub,
        DialogDescription: DialogDescriptionStub,
        AppLayout: AppLayoutStub,
        ContentLayout: ContentLayoutStub,
        ScrollableLayout: ScrollableLayoutStub,
        SectionHeader: true,
        Button: true,
        Label: true,
      },
    },
    attachTo: document.body,
  });
}

describe('Photos/Index.vue love confirm gating', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  it('does NOT show confirm when absolute_path exists but url is empty', async () => {
    const files = [{ id: 1, type: 'image', title: 'A', absolute_path: '/local/file.jpg', url: '', containers: [] }];
    const wrapper = mountPhotos(files);
    await nextTick(); await nextTick();
    // trigger favorite
    await wrapper.get('[data-test="favorite"]').trigger('click');
    await nextTick();
    expect(wrapper.text()).not.toContain('Re-download and love?');
    wrapper.unmount();
  });

  it('shows confirm when absolute_path exists and url is present', async () => {
    const files = [{ id: 2, type: 'image', title: 'B', absolute_path: '/local/file.jpg', url: 'https://example.com/test.jpg', containers: [] }];
    const wrapper = mountPhotos(files);
    await nextTick(); await nextTick();
    // trigger favorite
    await wrapper.get('[data-test="favorite"]').trigger('click');
    await nextTick();
    expect(wrapper.text()).toContain('Re-download and love?');
    wrapper.unmount();
  });
});


