<script setup lang="ts">
import type { VibeViewerItem } from '@wyxos/vibe';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import FileReactions from './FileReactions.vue';

const props = defineProps<{
    item: VibeViewerItem;
    index: number;
    total: number;
    canToggleBlacklist?: (item: FeedItem) => boolean;
    handleBlacklist: (item: VibeViewerItem) => void | Promise<void>;
    handleReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
    isRemovingItemFromTab?: (item: FeedItem) => boolean;
    removeItemFromTab?: (item: FeedItem) => void | Promise<void>;
}>();

function getFeedItem(): FeedItem | null {
    return (props.item.feedItem as FeedItem | undefined) ?? null;
}

function isRemovingFromTab(): boolean {
    const feedItem = getFeedItem();

    return feedItem ? props.isRemovingItemFromTab?.(feedItem) ?? false : false;
}

function removeFromTab(): void {
    const feedItem = getFeedItem();

    if (feedItem) {
        void props.removeItemFromTab?.(feedItem);
    }
}

function shouldAllowBlacklistToggle(): boolean {
    const feedItem = getFeedItem();

    return feedItem ? props.canToggleBlacklist?.(feedItem) ?? false : false;
}
</script>

<template>
    <div
        data-testid="browse-fullscreen-reactions"
        class="flex justify-center"
    >
        <FileReactions
            :file-id="getFeedItem()?.id"
            :reaction="getFeedItem()?.reaction ?? null"
            :blacklisted-at="getFeedItem()?.blacklisted_at ?? null"
            :allow-blacklist-toggle="shouldAllowBlacklistToggle()"
            :previewed-count="getFeedItem()?.previewed_count ?? 0"
            :viewed-count="getFeedItem()?.seen_count ?? 0"
            :current-index="index"
            :total-items="total"
            :icon-size="16"
            surface="none"
            variant="small"
            :show-remove="Boolean(removeItemFromTab && getFeedItem())"
            :removing="isRemovingFromTab()"
            @reaction="(type) => handleReaction(item, type)"
            @blacklist="() => handleBlacklist(item)"
            @remove="removeFromTab"
        />
    </div>
</template>
