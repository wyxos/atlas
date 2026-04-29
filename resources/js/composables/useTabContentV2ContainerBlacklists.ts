import { ref, type Ref } from 'vue';
import { filterItemsByContainerBlacklists, removeContainerBlacklist, upsertContainerBlacklist } from '@/lib/tabContentV2Blacklists';
import type { FeedItem } from '@/composables/useTabs';
import type { ContainerBlacklist } from '@/types/container-blacklist';
import type { BrowseFeedHandle } from '@/types/browse';

export type ContainerBlacklistChange = {
    action: 'created' | 'deleted';
    blacklist: ContainerBlacklist;
};

type UseTabContentV2ContainerBlacklistsOptions = {
    items: Ref<FeedItem[]>;
    masonry: Ref<BrowseFeedHandle | null>;
};

export function useTabContentV2ContainerBlacklists(options: UseTabContentV2ContainerBlacklistsOptions) {
    const activeContainerBlacklists = ref<ContainerBlacklist[]>([]);

    function filterItemsByActiveContainerBlacklists(candidateItems: FeedItem[]): FeedItem[] {
        return filterItemsByContainerBlacklists(candidateItems, activeContainerBlacklists.value);
    }

    function applyActiveContainerBlacklistFilter(): void {
        const filteredItems = filterItemsByActiveContainerBlacklists(options.items.value);
        if (filteredItems.length === options.items.value.length) {
            return;
        }

        const filteredIds = new Set(filteredItems.map((item) => item.id));
        const itemsInBlacklistedContainers = options.items.value.filter((item) => !filteredIds.has(item.id));
        if (itemsInBlacklistedContainers.length === 0) {
            return;
        }

        const handle = options.masonry.value;
        if (!handle) {
            return;
        }

        void Promise.resolve(handle.remove(itemsInBlacklistedContainers)).catch((error: unknown) => {
            console.error('Failed to remove blacklisted container items from browse-v2:', error);
        });
    }

    function handleContainerBlacklistChange(change: ContainerBlacklistChange): void {
        if (change.action === 'created' && change.blacklist.action_type === 'blacklist') {
            activeContainerBlacklists.value = upsertContainerBlacklist(activeContainerBlacklists.value, change.blacklist);
            applyActiveContainerBlacklistFilter();
            return;
        }

        activeContainerBlacklists.value = removeContainerBlacklist(activeContainerBlacklists.value, change.blacklist);
    }

    return {
        applyActiveContainerBlacklistFilter,
        filterItemsByActiveContainerBlacklists,
        handleContainerBlacklistChange,
    };
}
