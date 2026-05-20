import { ref } from 'vue';
import { watchSourceAndRefreshMedia } from '@/actions/App/Http/Controllers/FilesController';
import StatusToast from '@/components/toasts/StatusToast.vue';
import { useToast } from '@/components/ui/toast/use-toast';
import type { FeedItem } from '@/composables/useTabs';
import type { File } from '@/types/file';

type SourceWatchRefreshResponse = {
    message?: string;
    supported: boolean;
    watched: boolean;
    changed: boolean;
    file?: File;
};

export type SourceWatchRefreshActions = {
    canWatchAndRefresh: (item: FeedItem, username: string | null) => boolean;
    isWatchingAndRefreshing: (item: FeedItem) => boolean;
    watchAndRefresh: (item: FeedItem, username: string) => Promise<void>;
};

export function useSourceWatchRefresh(options: {
    setFileData: (file: File) => void;
}): SourceWatchRefreshActions {
    const toast = useToast();
    const pendingFileIds = ref<Set<number>>(new Set());

    function setPending(fileId: number, pending: boolean): void {
        const next = new Set(pendingFileIds.value);
        if (pending) {
            next.add(fileId);
        } else {
            next.delete(fileId);
        }

        pendingFileIds.value = next;
    }

    function showStatusToast(item: FeedItem, title: string, description: string, variant: 'success' | 'error'): void {
        const toastId = `source-watch-refresh-${item.id}`;

        toast({
            component: StatusToast,
            props: {
                toastId,
                title,
                description,
                variant,
            },
        }, {
            id: toastId,
            timeout: 5000,
        });
    }

    function canWatchAndRefresh(item: FeedItem, username: string | null): boolean {
        const normalizedUsername = typeof username === 'string' ? username.trim() : '';
        if (normalizedUsername === '') {
            return false;
        }

        if (String(item.source ?? '').toLowerCase() !== 'deviantart.com') {
            return false;
        }

        return item.capabilities?.watch_source_and_refresh !== false;
    }

    function isWatchingAndRefreshing(item: FeedItem): boolean {
        return pendingFileIds.value.has(item.id);
    }

    async function watchAndRefresh(item: FeedItem, username: string): Promise<void> {
        if (!canWatchAndRefresh(item, username) || isWatchingAndRefreshing(item)) {
            return;
        }

        setPending(item.id, true);

        try {
            const { data } = await window.axios.post<SourceWatchRefreshResponse>(
                watchSourceAndRefreshMedia.url(item.id),
            );

            if (data.file) {
                options.setFileData(data.file);
            }

            showStatusToast(
                item,
                data.changed ? 'Source media refreshed' : 'Source account watched',
                data.message ?? `Watched ${username}.`,
                'success',
            );
        } catch (error) {
            const response = (error as { response?: { data?: { message?: string } } }).response;
            const message = response?.data?.message
                ?? 'Unable to watch the source account or refresh this file.';

            showStatusToast(item, 'Source action failed', message, 'error');
        } finally {
            setPending(item.id, false);
        }
    }

    return {
        canWatchAndRefresh,
        isWatchingAndRefreshing,
        watchAndRefresh,
    };
}
