import { ref } from 'vue';
import { unwatchSourceAccount, watchSourceAndRefreshMedia } from '@/actions/App/Http/Controllers/FilesController';
import StatusToast from '@/components/toasts/StatusToast.vue';
import { useToast } from '@/components/ui/toast/use-toast';
import type { FeedItem } from '@/composables/useTabs';
import type { File, FileMetadataRecord, FileMetadataValue } from '@/types/file';

type SourceWatchRefreshResponse = {
    message?: string;
    supported: boolean;
    watched: boolean;
    changed: boolean;
    file?: File;
};

type SourceUnwatchResponse = {
    message?: string;
    supported: boolean;
    unwatched: boolean;
    file?: File;
};

type WatchAccessState = {
    requiresWatch: boolean;
    canUnwatch: boolean;
};

export type SourceWatchRefreshActions = {
    canWatchAndRefresh: (item: FeedItem, username: string | null) => boolean;
    canUnwatchSourceAccount: (item: FeedItem, username: string | null) => boolean;
    isWatchingAndRefreshing: (item: FeedItem) => boolean;
    watchAndRefresh: (item: FeedItem, username: string) => Promise<void>;
    unwatchSourceAccount: (item: FeedItem, username: string) => Promise<void>;
};

export function useSourceWatchRefresh(options: {
    setFileData: (file: File) => void;
}): SourceWatchRefreshActions {
    const toast = useToast();
    const pendingFileIds = ref<Set<number>>(new Set());
    const watchedSourceUsernames = ref<Set<string>>(new Set());

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

    function normalizedUsername(username: string | null): string {
        const value = typeof username === 'string' ? username.trim() : '';
        if (value === '') {
            return '';
        }

        return value;
    }

    function usernameKey(username: string): string {
        return username.toLowerCase();
    }

    function isRecord(value: FileMetadataValue | undefined): value is FileMetadataRecord {
        return typeof value === 'object' && value !== null && !Array.isArray(value);
    }

    function watchAccessFromListingMetadata(listingMetadata: FileMetadataRecord | null | undefined): WatchAccessState | null {
        const premiumFolderData = listingMetadata?.premium_folder_data;
        if (!isRecord(premiumFolderData)) {
            return null;
        }

        const accessType = typeof premiumFolderData.type === 'string'
            ? premiumFolderData.type.trim().toLowerCase()
            : '';
        const hasAccess = typeof premiumFolderData.has_access === 'boolean'
            ? premiumFolderData.has_access
            : null;

        if (accessType !== 'watchers') {
            return {
                requiresWatch: false,
                canUnwatch: false,
            };
        }

        return {
            requiresWatch: hasAccess === false,
            canUnwatch: hasAccess === true,
        };
    }

    function watchAccessForItem(item: FeedItem): WatchAccessState {
        const listingAccess = watchAccessFromListingMetadata(item.listing_metadata);
        if (listingAccess !== null) {
            return listingAccess;
        }

        return {
            requiresWatch: item.source_access?.requires_watch === true,
            canUnwatch: item.source_access?.can_unwatch === true,
        };
    }

    function canWatchAndRefresh(item: FeedItem, username: string | null): boolean {
        const usernameValue = normalizedUsername(username);
        if (usernameValue === '') {
            return false;
        }

        if (item.capabilities?.watch_source_and_refresh === false) {
            return false;
        }

        if (watchedSourceUsernames.value.has(usernameKey(usernameValue))) {
            return false;
        }

        return watchAccessForItem(item).requiresWatch;
    }

    function canUnwatchSourceAccount(item: FeedItem, username: string | null): boolean {
        const usernameValue = normalizedUsername(username);
        if (usernameValue === '') {
            return false;
        }

        if (item.capabilities?.unwatch_source_account === false) {
            return false;
        }

        return watchedSourceUsernames.value.has(usernameKey(usernameValue))
            || watchAccessForItem(item).canUnwatch;
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

            if (data.watched) {
                watchedSourceUsernames.value = new Set([
                    ...watchedSourceUsernames.value,
                    usernameKey(username),
                ]);
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

    async function unwatchSourceAccountAction(item: FeedItem, username: string): Promise<void> {
        if (!canUnwatchSourceAccount(item, username) || isWatchingAndRefreshing(item)) {
            return;
        }

        setPending(item.id, true);

        try {
            const { data } = await window.axios.post<SourceUnwatchResponse>(
                unwatchSourceAccount.url(item.id),
            );

            if (data.file) {
                options.setFileData(data.file);
            }

            const watchedUsernames = new Set(watchedSourceUsernames.value);
            watchedUsernames.delete(usernameKey(username));
            watchedSourceUsernames.value = watchedUsernames;

            showStatusToast(
                item,
                'Source account unwatched',
                data.message ?? `Unwatched ${username}.`,
                'success',
            );
        } catch (error) {
            const response = (error as { response?: { data?: { message?: string } } }).response;
            const message = response?.data?.message
                ?? 'Unable to unwatch the source account.';

            showStatusToast(item, 'Source action failed', message, 'error');
        } finally {
            setPending(item.id, false);
        }
    }

    return {
        canWatchAndRefresh,
        canUnwatchSourceAccount,
        isWatchingAndRefreshing,
        watchAndRefresh,
        unwatchSourceAccount: unwatchSourceAccountAction,
    };
}
