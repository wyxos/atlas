import { beforeEach, describe, expect, it, vi } from 'vitest';
import { computed, reactive, ref } from 'vue';
import { useTabContentV2FileSheet } from './useTabContentV2FileSheet';
import type { FeedItem } from './useTabs';

const storageKey = 'atlas:fileViewerSheetOpen';

function makeItem(id: number): FeedItem {
    return {
        id,
        height: 100,
        index: id - 1,
        key: `file-${id}`,
        original: `/files/${id}.jpg`,
        page: 1,
        preview: `/files/${id}-preview.jpg`,
        src: `/files/${id}-preview.jpg`,
        type: 'image',
        width: 100,
    };
}

function mountSheet() {
    const activeIndex = ref(0);
    const visibleItem = ref<FeedItem | null>(makeItem(1));
    const promptDialog = {
        clear: vi.fn(),
        select: vi.fn(),
    };
    const surfaceMode = ref<'fullscreen' | 'list'>('list');
    const overlay = reactive({
        fillComplete: true,
        isClosing: false,
        mediaType: 'image' as const,
    });
    const sheet = useTabContentV2FileSheet({
        activeIndex,
        currentVisibleItem: computed(() => visibleItem.value),
        overlay,
        promptDialog,
        surfaceMode,
    });

    return {
        activeIndex,
        overlay,
        promptDialog,
        sheet,
        surfaceMode,
        visibleItem,
    };
}

describe('useTabContentV2FileSheet', () => {
    beforeEach(() => {
        window.localStorage.clear();
        vi.clearAllMocks();
    });

    it('keeps file-viewer-triggered sheets bound to the current visible item', () => {
        const { promptDialog, sheet, visibleItem } = mountSheet();

        sheet.viewer.open();

        expect(sheet.viewer.state.isOpen).toBe(true);
        expect(sheet.grid.state.isOpen).toBe(false);
        expect(sheet.viewer.item.value?.id).toBe(1);
        expect(sheet.viewer.targetFileId.value).toBe(1);
        expect(promptDialog.clear).toHaveBeenCalledTimes(1);
        expect(promptDialog.select).not.toHaveBeenCalled();

        visibleItem.value = makeItem(4);

        expect(sheet.viewer.item.value?.id).toBe(4);
        expect(sheet.viewer.targetFileId.value).toBe(4);
    });

    it('pins grid info sheets to the clicked item while the visible item changes', () => {
        const { activeIndex, promptDialog, sheet, visibleItem } = mountSheet();
        const pinnedItem = makeItem(2);

        sheet.grid.openForItem(pinnedItem, 1);

        expect(activeIndex.value).toBe(1);
        expect(sheet.grid.state.isOpen).toBe(true);
        expect(sheet.viewer.state.isOpen).toBe(false);
        expect(sheet.grid.item.value?.id).toBe(2);
        expect(sheet.grid.targetFileId.value).toBe(2);
        expect(promptDialog.select).toHaveBeenCalledWith(pinnedItem);

        visibleItem.value = makeItem(3);

        expect(sheet.grid.item.value?.id).toBe(2);
        expect(sheet.grid.targetFileId.value).toBe(2);
    });

    it('keeps grid and viewer visibility independent when either owner closes', () => {
        const { sheet } = mountSheet();

        sheet.viewer.open();
        sheet.grid.openForItem(makeItem(2), 1);

        sheet.grid.close();

        expect(sheet.grid.state.isOpen).toBe(false);
        expect(sheet.viewer.state.isOpen).toBe(true);

        sheet.grid.openForItem(makeItem(3), 2);
        sheet.viewer.close();

        expect(sheet.viewer.state.isOpen).toBe(false);
        expect(sheet.grid.state.isOpen).toBe(true);
        expect(sheet.grid.item.value?.id).toBe(3);
    });

    it('exposes only the active surface state to file-data loading', () => {
        const { sheet, surfaceMode } = mountSheet();

        sheet.grid.openForItem(makeItem(2), 1);
        expect(sheet.active.state.isOpen).toBe(true);
        expect(sheet.active.targetFileId.value).toBe(2);

        surfaceMode.value = 'fullscreen';

        expect(sheet.active.state.isOpen).toBe(false);
        expect(sheet.active.targetFileId.value).toBe(1);

        sheet.viewer.open();

        expect(sheet.active.state.isOpen).toBe(true);
        expect(sheet.active.targetFileId.value).toBe(1);
    });

    it('persists only the viewer preference', () => {
        const { sheet } = mountSheet();

        sheet.grid.openForItem(makeItem(2), 1);

        expect(window.localStorage.getItem(storageKey)).toBeNull();

        sheet.viewer.open();

        expect(window.localStorage.getItem(storageKey)).toBe('1');
    });

    it('preserves a viewer-owned sheet across fullscreen exit and re-entry', () => {
        const { promptDialog, sheet, surfaceMode } = mountSheet();

        surfaceMode.value = 'fullscreen';
        sheet.viewer.open();
        expect(window.localStorage.getItem(storageKey)).toBe('1');

        surfaceMode.value = 'list';
        sheet.viewer.exit();

        expect(sheet.viewer.state.isOpen).toBe(true);
        expect(sheet.grid.state.isOpen).toBe(false);
        expect(sheet.active.state.isOpen).toBe(false);
        expect(window.localStorage.getItem(storageKey)).toBe('1');

        surfaceMode.value = 'fullscreen';
        sheet.viewer.enter();

        expect(sheet.viewer.state.isOpen).toBe(true);
        expect(sheet.active.state.isOpen).toBe(true);
        expect(promptDialog.clear).toHaveBeenCalledTimes(3);
    });

    it('preserves a grid-owned sheet without opening the viewer-owned sheet', () => {
        const { promptDialog, sheet, surfaceMode } = mountSheet();
        const gridItem = makeItem(2);

        sheet.grid.openForItem(gridItem, 1);
        expect(sheet.viewer.state.isOpen).toBe(false);

        surfaceMode.value = 'fullscreen';
        sheet.viewer.enter();

        expect(sheet.grid.state.isOpen).toBe(true);
        expect(sheet.viewer.state.isOpen).toBe(false);
        expect(sheet.active.state.isOpen).toBe(false);

        surfaceMode.value = 'list';
        sheet.viewer.exit();

        expect(sheet.grid.state.isOpen).toBe(true);
        expect(sheet.grid.item.value?.id).toBe(gridItem.id);
        expect(sheet.active.state.isOpen).toBe(true);
        expect(promptDialog.select).toHaveBeenLastCalledWith(gridItem);
    });
});
