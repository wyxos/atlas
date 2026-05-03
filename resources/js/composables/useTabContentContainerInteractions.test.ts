import { describe, expect, it, vi } from 'vitest';
import { computed, nextTick, ref, shallowRef } from 'vue';
import type { BrowseFormInstance } from './useBrowseForm';
import { useTabContentContainerInteractions } from './useTabContentContainerInteractions';
import type { FeedItem, TabData } from './useTabs';

function createItem(id: number, containers: Array<{ id: number; type: string }>): FeedItem {
    return {
        id,
        width: 500,
        height: 500,
        page: 1,
        key: `1-${id}`,
        index: id - 1,
        src: `https://example.com/preview${id}.jpg`,
        preview: `https://example.com/preview${id}.jpg`,
        type: 'image',
        containers,
    } as FeedItem;
}

function createEvent(overrides: Partial<MouseEvent> = {}): MouseEvent {
    return {
        button: 0,
        altKey: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        type: 'click',
        preventDefault: vi.fn(),
        stopPropagation: vi.fn(),
        ...overrides,
    } as MouseEvent;
}

function createSubject(items: FeedItem[]) {
    const subjectItems = shallowRef(items);
    const tab = ref<TabData | null>({
        id: 1,
        label: 'Test tab',
        params: {},
        position: 0,
        isActive: true,
        updatedAt: null,
    });

    return useTabContentContainerInteractions({
        items: subjectItems,
        tab,
        form: { isLocal: ref(false) } as unknown as BrowseFormInstance,
        masonry: ref(null),
        onReaction: vi.fn(),
        onOpenContainerTab: vi.fn(),
    });
}

describe('useTabContentContainerInteractions', () => {
    it('opens the drawer on hover after the configured delay', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onMouseEnter(10);
        vi.advanceTimersByTime(699);

        expect(interactions.drawer.state.isOpen.value).toBe(false);

        vi.advanceTimersByTime(1);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(true);
        expect(interactions.drawer.derived.container.value?.id).toBe(10);
        expect(interactions.drawer.derived.items.value.map((item) => item.id)).toEqual([1, 2]);
        expect([...interactions.drawer.derived.highlightedItemIds.value]).toEqual([1, 2]);

        vi.useRealTimers();
    });

    it('clears drawer highlighted item ids when the drawer closes', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
            createItem(3, [{ id: 20, type: 'album' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect([...interactions.drawer.derived.highlightedItemIds.value]).toEqual([1, 2]);

        interactions.drawer.actions.close();

        expect(interactions.drawer.derived.highlightedItemIds.value.size).toBe(0);

        vi.useRealTimers();
    });

    it('closes the drawer immediately on hover out when hover opened it', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onMouseEnter(10);
        vi.advanceTimersByTime(700);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        interactions.pillHandlers.onMouseLeave(10);

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });

    it('cancels a pending hover-open when pointer tracking leaves the pill before mouseleave fires', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onMouseEnter(10);
        vi.advanceTimersByTime(350);
        interactions.drawer.actions.syncHoverTarget(document.createElement('div'));
        vi.advanceTimersByTime(350);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });

    it('closes a hover-open drawer when pointer tracking leaves the pill and drawer during the transition window', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onMouseEnter(10);
        vi.advanceTimersByTime(700);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        interactions.drawer.actions.syncHoverTarget(document.createElement('div'));

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });

    it('opens the drawer on single left click', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(true);
        expect(interactions.drawer.derived.container.value?.id).toBe(10);
        expect(interactions.drawer.derived.items.value.map((item) => item.id)).toEqual([1, 2]);

        vi.useRealTimers();
    });

    it('keeps the drawer open on hover out when it was opened by click', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        interactions.pillHandlers.onMouseLeave(10);

        expect(interactions.drawer.state.isOpen.value).toBe(true);
        expect(interactions.drawer.derived.container.value?.id).toBe(10);

        vi.useRealTimers();
    });

    it('keeps a click-open drawer open when pointer tracking leaves the hover region', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        interactions.drawer.actions.syncHoverTarget(document.createElement('div'));

        expect(interactions.drawer.state.isOpen.value).toBe(true);
        expect(interactions.drawer.derived.container.value?.id).toBe(10);

        vi.useRealTimers();
    });

    it('does not open the drawer when the container has only one loaded item', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 20, type: 'album' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();
        expect(interactions.drawer.derived.items.value).toEqual([]);

        vi.useRealTimers();
    });

    it('closes the drawer when clicking the same pill again', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });

    it('swaps drawer content when clicking a different pill', () => {
        vi.useFakeTimers();
        const interactions = createSubject([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 20, type: 'album' }]),
            createItem(3, [{ id: 20, type: 'album' }]),
        ]);

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        interactions.pillHandlers.onClick(20, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();

        expect(interactions.drawer.state.isOpen.value).toBe(true);
        expect(interactions.drawer.derived.container.value?.id).toBe(20);
        expect(interactions.drawer.derived.items.value.map((item) => item.id)).toEqual([2, 3]);

        vi.useRealTimers();
    });

    it('closes the drawer when the selected container no longer has loaded items', async () => {
        vi.useFakeTimers();
        const subjectItems = shallowRef([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
            createItem(3, [{ id: 20, type: 'album' }]),
        ]);
        const interactions = useTabContentContainerInteractions({
            items: subjectItems,
            tab: ref<TabData | null>({
                id: 1,
                label: 'Test tab',
                params: {},
                position: 0,
                isActive: true,
                updatedAt: null,
            }),
            form: { isLocal: ref(false) } as unknown as BrowseFormInstance,
            masonry: ref(null),
            onReaction: vi.fn(),
            onOpenContainerTab: vi.fn(),
        });

        interactions.pillHandlers.onClick(10, createEvent());
        vi.advanceTimersByTime(300);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        subjectItems.value = [createItem(3, [{ id: 20, type: 'album' }])];
        await nextTick();

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });

    it('closes a hover-open drawer when the selected container is removed from the visible item set', async () => {
        vi.useFakeTimers();
        const subjectItems = shallowRef([
            createItem(1, [{ id: 10, type: 'gallery' }]),
            createItem(2, [{ id: 10, type: 'gallery' }]),
            createItem(3, [{ id: 20, type: 'album' }]),
        ]);
        const removedIds = ref(new Set<number>());
        const visibleItems = computed(() => (
            subjectItems.value.filter((item) => !removedIds.value.has(item.id))
        ));
        const interactions = useTabContentContainerInteractions({
            items: subjectItems,
            visibleItems,
            tab: ref<TabData | null>({
                id: 1,
                label: 'Test tab',
                params: {},
                position: 0,
                isActive: true,
                updatedAt: null,
            }),
            form: { isLocal: ref(false) } as unknown as BrowseFormInstance,
            masonry: ref(null),
            onReaction: vi.fn(),
            onOpenContainerTab: vi.fn(),
        });

        interactions.pillHandlers.onMouseEnter(10);
        vi.advanceTimersByTime(700);
        vi.runOnlyPendingTimers();
        expect(interactions.drawer.state.isOpen.value).toBe(true);

        removedIds.value = new Set([1, 2]);
        await nextTick();

        expect(interactions.drawer.state.isOpen.value).toBe(false);
        expect(interactions.drawer.derived.container.value).toBeNull();

        vi.useRealTimers();
    });
});
