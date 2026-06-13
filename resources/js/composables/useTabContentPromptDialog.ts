import type { ShallowRef } from 'vue';
import { usePromptData } from './usePromptData';
import type { FeedItem } from './useTabs';

export function useTabContentPromptDialog(items: ShallowRef<FeedItem[]>) {
    const data = usePromptData(items);

    function open(item: FeedItem): void {
        void data.openPromptDialog(item);
    }

    function select(item: FeedItem): void {
        void data.selectPromptItem(item);
    }

    function close(): void {
        data.closePromptDialog();
    }

    function clear(): void {
        data.clearPromptSelection();
    }

    function setOpen(value: boolean): void {
        if (!value) {
            close();
        }
    }

    function copy(): void {
        if (data.currentPromptData.value) {
            void data.copyPromptToClipboard(data.currentPromptData.value);
        }
    }

    return {
        data,
        open,
        select,
        close,
        clear,
        setOpen,
        copy,
    };
}

export type TabContentPromptDialog = ReturnType<typeof useTabContentPromptDialog>;
