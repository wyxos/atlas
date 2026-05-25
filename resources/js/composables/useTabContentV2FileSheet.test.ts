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
    const sheet = useTabContentV2FileSheet({
        activeIndex,
        currentVisibleItem: computed(() => visibleItem.value),
        overlay: reactive({
            fillComplete: true,
            isClosing: false,
            mediaType: 'image',
        }),
        promptDialog,
    });

    return {
        activeIndex,
        promptDialog,
        sheet,
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

        sheet.open();

        expect(sheet.state.isOpen).toBe(true);
        expect(sheet.presentation.value).toBe('inline');
        expect(sheet.item.value?.id).toBe(1);
        expect(sheet.targetFileId.value).toBe(1);
        expect(promptDialog.clear).toHaveBeenCalledTimes(1);
        expect(promptDialog.select).not.toHaveBeenCalled();

        visibleItem.value = makeItem(4);

        expect(sheet.item.value?.id).toBe(4);
        expect(sheet.targetFileId.value).toBe(4);
    });

    it('pins grid info sheets to the clicked item while the visible item changes', () => {
        const { activeIndex, promptDialog, sheet, visibleItem } = mountSheet();
        const pinnedItem = makeItem(2);

        sheet.openForItem(pinnedItem, 1);

        expect(activeIndex.value).toBe(1);
        expect(sheet.state.isOpen).toBe(true);
        expect(sheet.presentation.value).toBe('overlay');
        expect(sheet.item.value?.id).toBe(2);
        expect(sheet.targetFileId.value).toBe(2);
        expect(promptDialog.select).toHaveBeenCalledWith(pinnedItem);

        visibleItem.value = makeItem(3);

        expect(sheet.item.value?.id).toBe(2);
        expect(sheet.targetFileId.value).toBe(2);
    });

    it('closes on fullscreen exit without changing the saved open preference', () => {
        const { promptDialog, sheet } = mountSheet();

        sheet.open();
        expect(window.localStorage.getItem(storageKey)).toBe('1');

        sheet.closeForFullscreenExit();

        expect(sheet.state.isOpen).toBe(false);
        expect(sheet.presentation.value).toBe('inline');
        expect(window.localStorage.getItem(storageKey)).toBe('1');
        expect(promptDialog.clear).toHaveBeenCalledTimes(2);
    });
});
