import { onUnmounted, ref } from 'vue';
import type { LoadedItemsBulkAction } from '@/lib/tabContentLoadedItemsBulkActions';

export function useLoadedItemsBatchActionConfirmation() {
    const pendingAction = ref<LoadedItemsBulkAction | null>(null);
    let resolver: ((confirmed: boolean) => void) | null = null;

    function resolve(confirmed: boolean): void {
        const currentResolver = resolver;
        resolver = null;
        pendingAction.value = null;
        currentResolver?.(confirmed);
    }

    function request(action: LoadedItemsBulkAction): Promise<boolean> {
        resolve(false);
        pendingAction.value = action;

        return new Promise((promiseResolver) => {
            resolver = promiseResolver;
        });
    }

    onUnmounted(() => {
        resolve(false);
    });

    return {
        cancel: () => resolve(false),
        confirm: () => resolve(true),
        pendingAction,
        request,
    };
}
