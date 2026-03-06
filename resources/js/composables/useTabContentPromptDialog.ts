import type { ShallowRef } from 'vue';
import { usePromptData } from './usePromptData';
import type { FeedItem } from './useTabs';

export function useTabContentPromptDialog(items: ShallowRef<FeedItem[]>) {
    const data = usePromptData(items);

    function open(item: FeedItem): void {
        void data.openPromptDialog(item);
    }

    function close(): void {
        data.closePromptDialog();
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

    function openTestPage(): void {
        if (!data.currentPromptData.value) {
            return;
        }

        const params = new URLSearchParams();
        params.set('text', data.currentPromptData.value);

        window.open(`/moderation/test?${params.toString()}`, '_blank', 'noopener,noreferrer');
    }

    return {
        data,
        open,
        close,
        setOpen,
        copy,
        openTestPage,
    };
}

export type TabContentPromptDialog = ReturnType<typeof useTabContentPromptDialog>;
