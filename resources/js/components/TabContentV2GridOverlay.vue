<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, Info, Loader2, RefreshCw, Trash2, UserMinus, UserPlus } from 'lucide-vue-next';
import type { VibeViewerItem } from '@wyxos/vibe';
import type { LocalFileDeletion } from '@/composables/useLocalFileDeletion';
import type { SourceWatchRefreshActions } from '@/composables/useSourceWatchRefresh';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import { Button } from '@/components/ui/button';
import FileReactions from './FileReactions.vue';
import Pill from './ui/Pill.vue';

interface Props {
    active: boolean;
    hovered: boolean;
    index: number;
    item: FeedItem;
    dimmed?: boolean;
    totalItems: number;
    vibeItem: VibeViewerItem;
    containers: TabContentContainerInteractions;
    itemInteractions: TabContentItemInteractions;
    localFileDeletion: LocalFileDeletion;
    isRemovingFromTab?: (item: FeedItem) => boolean;
    openFileSheet: (item: FeedItem, index: number) => void;
    removeItemFromTab?: (item: FeedItem) => void | Promise<void>;
    queuePreviewRegeneration?: (item: FeedItem) => void | Promise<void>;
    isPreviewRegenerationQueued?: (item: FeedItem) => boolean;
    sourceWatchRefresh: SourceWatchRefreshActions;
    onReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
}

const props = defineProps<Props>();

const itemContainers = computed(() => props.containers.badges.getContainersForItem(props.item));
const deviantArtUserContainer = computed(() => itemContainers.value.find((container) => (
    String(container.source ?? '').toLowerCase() === 'deviantart.com'
    && String(container.type ?? '').toLowerCase() === 'user'
    && typeof container.source_id === 'string'
    && container.source_id.trim() !== ''
)) ?? null);
const deviantArtUsername = computed(() => deviantArtUserContainer.value?.source_id?.trim() ?? null);
const isPreloaded = computed(() => props.itemInteractions.preload.isItemPreloaded(props.item.id));
const showContainers = computed(() => props.hovered && isPreloaded.value && itemContainers.value.length > 0);
const showSourceMediaRefreshButton = computed(() => props.hovered
    && props.sourceWatchRefresh.canRefreshSourceMedia(props.item));
const showSourceWatchRefreshButton = computed(() => props.hovered
    && props.sourceWatchRefresh.canWatchAndRefresh(props.item, deviantArtUsername.value));
const showSourceUnwatchButton = computed(() => props.hovered
    && props.sourceWatchRefresh.canUnwatchSourceAccount(props.item, deviantArtUsername.value));
const isSourceWatchRefreshPending = computed(() => props.sourceWatchRefresh.isWatchingAndRefreshing(props.item));
const showPromptButton = computed(() => props.hovered && isPreloaded.value);
const showDeleteButton = computed(() => props.hovered
    && isPreloaded.value
    && props.localFileDeletion.actions.canDelete(props.item));
const showRemoveFromTab = computed(() => Boolean(props.removeItemFromTab));
const isRemovingFromTab = computed(() => props.isRemovingFromTab?.(props.item) ?? false);
const previewGeneration = computed(() => props.item.preview_generation ?? null);
const previewGenerationStatus = computed(() => previewGeneration.value?.status ?? null);
const previewRegenerationQueued = computed(() => props.isPreviewRegenerationQueued?.(props.item) ?? false);
const showPreviewGenerationState = computed(() => props.item.downloaded === true
    && Boolean(previewGeneration.value)
    && !props.item.src
    && !props.item.preview
    && !props.item.thumbnail);
const canRetryPreviewGeneration = computed(() => showPreviewGenerationState.value
    && Boolean(props.queuePreviewRegeneration)
    && !previewRegenerationQueued.value
    && previewGeneration.value?.can_retry === true);
const previewGenerationTitle = computed(() => {
    if (previewGenerationStatus.value === 'unavailable' || (previewGeneration.value?.can_retry === false && previewGenerationStatus.value === 'failed')) {
        return 'Preview unavailable';
    }

    if (previewGenerationStatus.value === 'failed') {
        return 'Preview failed';
    }

    if (previewGenerationStatus.value === 'missing') {
        return 'Preview missing';
    }

    return 'Generating preview';
});
const previewGenerationMessage = computed(() => {
    if (previewGenerationStatus.value === 'unavailable' || previewGenerationStatus.value === 'failed') {
        return previewGeneration.value?.message ?? 'Preview generation failed.';
    }

    if (previewGenerationStatus.value === 'missing') {
        return 'A new preview task has been queued.';
    }

    return '';
});
const showReactions = computed(() => (
    (
        props.hovered
        || props.active
        || props.itemInteractions.reactions.hasActiveReaction(props.item)
        || props.itemInteractions.reactions.hasBlacklistState(props.item)
    )
    && isPreloaded.value
));
</script>

<template>
    <div
        class="pointer-events-none absolute inset-0 z-[5]"
        :data-container-drawer-dimmed="dimmed ? 'true' : undefined"
    >
        <div
            v-if="showContainers"
            class="pointer-events-auto absolute left-2 top-2 flex flex-col gap-1"
        >
            <div
                v-for="container in itemContainers"
                :key="container.id"
                class="cursor-pointer"
                data-container-pill-trigger
                @mouseenter="() => containers.pillHandlers.onMouseEnter(container.id)"
                @mouseleave="() => containers.pillHandlers.onMouseLeave(container.id)"
                @click.stop="(event) => containers.pillHandlers.onClick(container.id, event)"
                @dblclick.prevent.stop="(event) => containers.pillHandlers.onDoubleClick(container.id, event)"
                @contextmenu.prevent.stop="(event) => containers.pillHandlers.onContextMenu(container.id, event)"
                @mousedown.stop="containers.pillHandlers.onMouseDown"
                @mouseup.stop="(event) => { if (event.button === 1) containers.pillHandlers.onAuxClick(container.id, event) }"
            >
                <Pill
                    :label="container.type"
                    :value="containers.badges.getItemCountForContainerId(container.id)"
                    :variant="containers.badges.getVariantForContainerType(container.type)"
                    :dismissible="containers.isBlacklistable(container) ? 'danger' : false"
                    @dismiss="() => containers.pillHandlers.onDismiss(container)"
                />
            </div>
        </div>

        <div
            v-if="showSourceMediaRefreshButton || showSourceWatchRefreshButton || showSourceUnwatchButton || showPromptButton || showDeleteButton"
            class="pointer-events-auto absolute right-2 top-2 flex items-center gap-2"
        >
            <Button
                v-if="showSourceMediaRefreshButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-black/55 p-0 text-white hover:bg-black/75 disabled:cursor-wait disabled:opacity-80"
                aria-label="Refresh source media"
                data-test="source-media-refresh-trigger"
                :disabled="isSourceWatchRefreshPending"
                @click.stop="sourceWatchRefresh.refreshSourceMedia(item)"
            >
                <Loader2
                    v-if="isSourceWatchRefreshPending"
                    :size="14"
                    class="animate-spin"
                />
                <RefreshCw v-else :size="14" />
            </Button>
            <Button
                v-if="showSourceWatchRefreshButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-smart-blue-700/80 p-0 text-white hover:bg-smart-blue-600 disabled:cursor-wait disabled:opacity-80"
                aria-label="Watch source account and refresh media"
                data-test="source-watch-refresh-trigger"
                :disabled="isSourceWatchRefreshPending"
                @click.stop="() => {
                    if (deviantArtUsername) {
                        sourceWatchRefresh.watchAndRefresh(item, deviantArtUsername);
                    }
                }"
            >
                <Loader2
                    v-if="isSourceWatchRefreshPending"
                    :size="14"
                    class="animate-spin"
                />
                <UserPlus v-else :size="14" />
            </Button>
            <Button
                v-if="showSourceUnwatchButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-zinc-700/80 p-0 text-white hover:bg-zinc-600 disabled:cursor-wait disabled:opacity-80"
                aria-label="Unwatch source account"
                data-test="source-unwatch-trigger"
                :disabled="isSourceWatchRefreshPending"
                @click.stop="() => {
                    if (deviantArtUsername) {
                        sourceWatchRefresh.unwatchSourceAccount(item, deviantArtUsername);
                    }
                }"
            >
                <Loader2
                    v-if="isSourceWatchRefreshPending"
                    :size="14"
                    class="animate-spin"
                />
                <UserMinus v-else :size="14" />
            </Button>
            <Button
                v-if="showPromptButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-black/50 p-0 text-white hover:bg-black/70"
                aria-label="Show prompt"
                @click.stop="openFileSheet(item, index)"
            >
                <Info :size="14" />
            </Button>
            <Button
                v-if="showDeleteButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-danger-700/80 p-0 text-white hover:bg-danger-600"
                aria-label="Delete library file"
                data-test="local-file-delete-trigger"
                @click.stop="localFileDeletion.actions.open(item)"
            >
                <Trash2 :size="14" />
            </Button>
        </div>

        <div
            v-if="showPreviewGenerationState"
            class="pointer-events-none absolute inset-0 flex items-center justify-center px-3"
            data-test="preview-generation-state"
        >
            <div class="pointer-events-auto max-w-[13rem] border border-white/15 bg-black/75 p-3 text-center shadow-2xl backdrop-blur">
                <div class="mb-2 flex justify-center text-danger-200">
                    <Loader2
                        v-if="previewRegenerationQueued || (previewGenerationStatus !== 'failed' && previewGenerationStatus !== 'missing' && previewGenerationStatus !== 'unavailable')"
                        :size="18"
                        class="animate-spin"
                    />
                    <AlertTriangle v-else :size="18" />
                </div>
                <div class="text-xs font-semibold text-white">
                    {{ previewGenerationTitle }}
                </div>
                <div
                    v-if="previewGenerationMessage"
                    class="mt-1 text-[11px] leading-snug text-white/65"
                >
                    {{ previewGenerationMessage }}
                </div>
                <Button
                    v-if="canRetryPreviewGeneration"
                    variant="ghost"
                    size="sm"
                    class="mt-3 h-7 border border-white/10 bg-white/10 px-2 text-[11px] text-white hover:bg-white/15"
                    data-test="preview-regeneration-trigger"
                    @click.stop="queuePreviewRegeneration?.(item)"
                >
                    <RefreshCw :size="12" />
                    Retry
                </Button>
            </div>
        </div>

        <div
            v-if="showReactions"
            class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center px-3 pb-3"
        >
            <div class="pointer-events-auto">
                <FileReactions
                    :file-id="item.id"
                    :reaction="item.reaction ?? null"
                    :blacklisted-at="item.blacklisted_at ?? null"
                    :allow-blacklist-toggle="itemInteractions.reactions.canToggleBlacklist?.(item) ?? false"
                    :previewed-count="item.previewed_count ?? 0"
                    :viewed-count="item.seen_count ?? 0"
                    :current-index="index"
                    :total-items="totalItems"
                    variant="small"
                    :show-remove="showRemoveFromTab"
                    :removing="isRemovingFromTab"
                    @reaction="(type) => onReaction(vibeItem, type)"
                    @blacklist="() => itemInteractions.reactions.onFileBlacklist(item)"
                    @remove="() => removeItemFromTab?.(item)"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
:global(article[data-testid="vibe-list-card"]) {
    transition: opacity 0.16s ease;
}

:global(article[data-testid="vibe-list-card"]:has([data-container-drawer-dimmed="true"])) {
    opacity: 0.3;
}
</style>
