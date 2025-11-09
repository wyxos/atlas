import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';

import { bus } from '@/lib/bus';

const lastActionMenuProps = ref<Record<string, any> | null>(null);

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

const stubComponent = (name: string) =>
    defineComponent({
        name,
        inheritAttrs: false,
        setup(_props, { slots }) {
            return () => h('div', { class: name }, slots.default ? slots.default() : []);
        },
    });

vi.mock('lucide-vue-next', () => ({
    Eye: stubComponent('EyeIcon'),
    ZoomIn: stubComponent('ZoomInIcon'),
    MoreHorizontal: stubComponent('MoreHorizontalIcon'),
    User: stubComponent('UserIcon'),
    Newspaper: stubComponent('NewspaperIcon'),
    Book: stubComponent('BookIcon'),
    BookOpen: stubComponent('BookOpenIcon'),
    Palette: stubComponent('PaletteIcon'),
    Tag: stubComponent('TagIcon'),
    Info: stubComponent('InfoIcon'),
    ImageOff: stubComponent('ImageOffIcon'),
    AlertTriangle: stubComponent('AlertTriangleIcon'),
    Loader2: stubComponent('Loader2Icon'),
}));

vi.mock('@/components/audio/FileReactions.vue', () => ({
    default: defineComponent({ name: 'FileReactionsStub', template: '<div />' }),
}));

vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        name: 'UIButtonStub',
        inheritAttrs: false,
        emits: ['click'],
        setup(_props, { slots, emit, attrs }) {
            return () =>
                h(
                    'button',
                    {
                        type: 'button',
                        ...attrs,
                        onClick: (event: MouseEvent) => emit('click', event),
                    },
                    slots.default ? slots.default() : [],
                );
        },
    }),
}));

vi.mock('@/components/ui/LoaderOverlay.vue', () => ({
    default: defineComponent({ name: 'LoaderOverlayStub', template: '<div data-test="loader-overlay" />' }),
}));

vi.mock('@/components/browse/ActionMenu.vue', () => ({
    default: defineComponent({
        name: 'ActionMenuStub',
        emits: ['close', 'path-change'],
        props: {
            open: { type: Boolean, default: false },
            options: { type: Array as () => Array<Record<string, any>>, default: () => [] },
            initialPathLabels: { type: Array as () => string[] | undefined, default: undefined },
        },
        setup(props, { slots }) {
            return () => {
                lastActionMenuProps.value = { ...props };
                return h('div', { 'data-test': 'action-menu' }, slots.default ? slots.default() : []);
            };
        },
    }),
}));

vi.mock('@/components/browse/ContainerBadge.vue', () => ({
    default: defineComponent({ name: 'ContainerBadgeStub', template: '<span />' }),
}));

vi.mock('@/components/ui/tooltip', () => ({
    Tooltip: stubComponent('TooltipStub'),
    TooltipContent: stubComponent('TooltipContentStub'),
    TooltipProvider: stubComponent('TooltipProviderStub'),
    TooltipTrigger: stubComponent('TooltipTriggerStub'),
}));

vi.mock('@/pages/browse/highlight', () => ({
    ringForSlot: () => 'ring-test',
    badgeClassForSlot: () => 'badge-test',
}));

vi.mock('@/pages/browse/useBatchReact', () => ({
    createBatchReact: () => async () => Promise.resolve(),
}));

vi.mock('@/utils/moderationHighlight', () => ({
    highlightPromptHtml: () => 'highlighted',
}));

const GridItem = (await import('@/components/browse/GridItem.vue')).default;
function mountGridItem(overrides: { item?: Record<string, any> } = {}) {
    const item = reactive({
        id: 123,
        type: 'image',
        preview: '',
        not_found: true,
        containers: [],
        ...overrides.item,
    });

    const itemsRef = ref<any[]>([]);
    const scrollerStub = reactive({
        removeAll: async () => undefined,
        remove: async () => undefined,
        loadNext: async () => undefined,
        loadPage: async () => undefined,
        refreshLayout: () => undefined,
        refreshCurrentPage: async () => undefined,
        cancelLoad: () => undefined,
        reset: () => undefined,
        get isLoading() {
            return false;
        },
        get totalItems() {
            return 0;
        },
    });

    return mount(GridItem, {
        props: { item },
        global: {
            provide: {
                'browse-io': {
                    register: (_el: Element, fn: () => void) => fn(),
                    unregister: () => void 0,
                    observer: {
                        observe() {},
                        unobserve() {},
                    },
                },
                'browse-items': itemsRef,
                'browse-scroller': scrollerStub,
                'browse-schedule-refresh': () => undefined,
                'browse-container-counts': new Map(),
            },
            stubs: {
                transition: false,
                teleport: true,
            },
        },
        attachTo: document.body,
    });
}

describe('GridItem error overlay interactions', () => {
    beforeEach(() => {
        bus.all.clear();
        lastActionMenuProps.value = null;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('shows resolving overlay when resolution is required', async () => {
        const wrapper = mountGridItem({
            item: {
                type: 'video',
                original: 'https://media.example.test/video.mp4',
                preview: 'https://media.example.test/thumb.jpg',
                resolutionRequired: true,
                not_found: false,
            },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-testid="grid-item-resolving-overlay"]').exists()).toBe(true);
        expect(wrapper.find('[data-test="loader-overlay"]').exists()).toBe(true);
        expect(wrapper.find('video').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
    });

    it('does not render loader or media when item is pre-flagged as not found', async () => {
        const wrapper = mountGridItem({
            item: {
                preview: 'https://media.example.test/thumb.jpg',
                original: 'https://media.example.test/video.mp4',
                not_found: true,
            },
        });
        await wrapper.vm.$nextTick();

        expect(wrapper.find('[data-test="loader-overlay"]').exists()).toBe(false);
        expect(wrapper.find('video').exists()).toBe(false);
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('[data-testid="grid-item-error-overlay"]').exists()).toBe(true);
    });

    it('emits open when the error overlay is clicked', async () => {
        const wrapper = mountGridItem();
        await wrapper.vm.$nextTick();

        const overlay = wrapper.get('[data-testid="grid-item-error-overlay"]');
        await overlay.trigger('click');

        const events = wrapper.emitted('open');
        expect(events).toBeTruthy();
        expect(events?.[0]?.[0]).toMatchObject({ id: 123 });
    });
});

describe('GridItem copy menu', () => {
    beforeEach(() => {
        lastActionMenuProps.value = null;
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('includes remote referrer and true URLs when item is not local', async () => {
        const remoteItem = {
            preview: 'https://proxy.example.com/preview.jpg',
            thumbnail_url: 'https://proxy.example.com/preview.jpg',
            original: 'https://app.test/files/view/123',
            true_original_url: 'https://remote.example.com/image/full.jpg',
            true_thumbnail_url: 'https://remote.example.com/image/thumb.jpg',
            referrer_url: 'https://remote.example.com/page',
            is_local: false,
            not_found: false,
        };

        const wrapper = mountGridItem({ item: remoteItem });
        await wrapper.vm.$nextTick();

        const openButton = wrapper.get('[aria-label="More options"]');
        await openButton.trigger('click');
        await wrapper.vm.$nextTick();

        const options = (lastActionMenuProps.value?.options ?? []) as Array<{ label: string; children?: Array<{ label: string }> }>;
        const copySection = options.find((option) => option.label === 'copy url');
        expect(copySection).toBeTruthy();
        const labels = (copySection?.children || []).map((child) => child.label);

        expect(labels).toContain('copy referrer url');
        expect(labels).toContain('copy original url');
        expect(labels).toContain('copy original preview url');
        expect(labels).toContain('copy thumbnail url');
        expect(labels).toContain('copy url');
    });
});

