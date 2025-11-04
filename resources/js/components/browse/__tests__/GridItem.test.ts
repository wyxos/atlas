import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h, reactive, ref } from 'vue';

import { bus } from '@/lib/bus';

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

vi.mock('axios', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: {} })),
    },
}));

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
}));

vi.mock('@/components/audio/FileReactions.vue', () => ({
    default: defineComponent({ name: 'FileReactionsStub', template: '<div />' }),
}));

vi.mock('@/components/ui/button', () => ({
    Button: defineComponent({
        name: 'UIButtonStub',
        inheritAttrs: false,
        emits: ['click'],
        setup(_props, { slots, emit }) {
            return () =>
                h(
                    'button',
                    {
                        type: 'button',
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
        props: { open: { type: Boolean, default: false } },
        setup(_props, { slots }) {
            return () => h('div', { 'data-test': 'action-menu' }, slots.default ? slots.default() : []);
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
    highlightPromptHtml: (_prompt: string) => 'highlighted',
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
    });

    afterEach(() => {
        document.body.innerHTML = '';
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

