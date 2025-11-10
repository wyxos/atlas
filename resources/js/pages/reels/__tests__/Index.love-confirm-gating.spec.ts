import { describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import Index from '@/pages/reels/Index.vue';

// Mock Inertia
vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', template: '<template><slot /></template>' },
  useForm: (initial: any) => ({ ...initial, data: () => ({ ...initial }), defaults: () => {}, reset: () => {} }) as any,
  usePage: () => ({ url: '/reels', props: { auth: { user: { is_admin: true } } } }),
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

const GridItemStub = {
  name: 'GridItem',
  props: { item: { type: Object, required: true }, fileForReactions: { type: Object, required: false } },
  emits: ['favorite'],
  template: `<button data-test="favorite" @click="$emit('favorite', item, $event)">favorite</button>`,
};

const DialogStub = { name: 'Dialog', props: { open: { type: Boolean, default: false } }, template: `<div v-if="open"><slot /></div>` };
const DialogScrollContentStub = { name: 'DialogScrollContent', template: '<div><slot /></div>' };
const DialogTitleStub = { name: 'DialogTitle', template: '<div><slot /></div>' };
const DialogDescriptionStub = { name: 'DialogDescription', template: '<div><slot /></div>' };
const FullSizeViewerStub = { name: 'FullSizeViewer', props: { open: Boolean, item: Object }, emits: ['update:open','update:item'], template: '<div></div>' };
const AppLayoutStub = { name: 'AppLayout', template: '<div><slot /></div>' };
const ContentLayoutStub = { name: 'ContentLayout', template: '<div><slot /></div>' };
const ScrollableLayoutStub = { name: 'ScrollableLayout', template: '<div><slot /></div>' };

function mountReels(files: any[]) {
  const filter = { limit: 40, page: 1, next: null };
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

describe('Reels/Index.vue love confirm gating', () => {
  it('does NOT show confirm when absolute_path exists but url is empty', async () => {
    const wrapper = mountReels([{ id: 1, type: 'video', title: 'R', absolute_path: '/local/file.mp4', url: '' }]);
    await nextTick(); await nextTick();
    await wrapper.get('[data-test="favorite"]').trigger('click');
    await nextTick();
    expect(wrapper.text()).not.toContain('Re-download and love?');
    wrapper.unmount();
  });

  it('shows confirm when absolute_path exists and url is present', async () => {
    const wrapper = mountReels([{ id: 2, type: 'video', title: 'S', absolute_path: '/local/file.mp4', url: 'https://example.com/x.mp4' }]);
    await nextTick(); await nextTick();
    await wrapper.get('[data-test="favorite"]').trigger('click');
    await nextTick();
    expect(wrapper.text()).toContain('Re-download and love?');
    wrapper.unmount();
  });
});


