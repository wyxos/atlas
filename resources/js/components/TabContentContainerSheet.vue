<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { VibeLayout, type VibeAssetLoadEvent, type VibeHandle, type VibeInitialState, type VibeViewerItem } from '@wyxos/vibe';
import { X } from 'lucide-vue-next';
import type { ContainerPillTarget } from '@/composables/useContainerPillInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { FeedItem } from '@/composables/useTabs';
import { mapFeedItemToVibeItem } from '@/lib/tabContentV2';
import { createBrowseV2MouseShortcutHandlers } from '@/lib/tabContentV2MouseShortcuts';
import { getFeedItemFromVibeOccurrenceTarget, type AtlasVibeHandle } from '@/lib/tabContentV2VibeItems';
import type { ReactionType } from '@/types/reaction';
import FileReactions from './FileReactions.vue';

const props = defineProps<{
    open: boolean;
    container: ContainerPillTarget | null;
    items: FeedItem[];
    itemInteractions: TabContentItemInteractions;
}>();

const emit = defineEmits<{
    close: [];
}>();

const vibeRef = ref<AtlasVibeHandle | null>(null);
const sheetItems = ref<FeedItem[]>([]);
const sheetSessionKey = ref(0);
const hasInitializedSheet = ref(false);

const containerLabel = computed(() => (
    props.container?.browse_tab?.label?.trim()
    || props.container?.type
    || 'Container'
));

const itemCountLabel = computed(() => (
    sheetItems.value.length === 1 ? '1 item' : `${sheetItems.value.length} items`
));

const viewerKey = computed(() => (
    `${props.container?.id ?? 'container'}-${sheetSessionKey.value}`
));

const initialState = computed<VibeInitialState>(() => ({
    activeIndex: 0,
    cursor: null,
    items: sheetItems.value.map(mapFeedItemToVibeItem),
    nextCursor: null,
    previousCursor: null,
}));

watch(
    () => ({
        containerId: props.container?.id ?? null,
        open: props.open,
    }),
    (current, previous) => {
        if (!current.open) {
            hasInitializedSheet.value = false;
            sheetItems.value = [];
            vibeRef.value = null;
            return;
        }

        if (!hasInitializedSheet.value || current.containerId !== previous?.containerId) {
            hasInitializedSheet.value = true;
            sheetItems.value = [...props.items];
            sheetSessionKey.value += 1;
        }
    },
    { immediate: true },
);

function getFeedItemFromVibeItem(item: VibeViewerItem): FeedItem | null {
    return (item.feedItem as FeedItem | undefined) ?? null;
}

function handleVibeRef(instance: VibeHandle | null): void {
    vibeRef.value = (instance as AtlasVibeHandle | null) ?? null;
}

function handleAssetLoads(loads: VibeAssetLoadEvent[]): void {
    const batch = loads
        .map((load) => getFeedItemFromVibeItem(load.item))
        .filter((item): item is FeedItem => item !== null);

    if (batch.length > 0) {
        props.itemInteractions.preload.onBatchPreloaded(batch);
    }
}

function getShortcutItemFromTarget(target: EventTarget | null): FeedItem | null {
    return getFeedItemFromVibeOccurrenceTarget(vibeRef.value, target);
}

function removeSheetItem(item: FeedItem): void {
    vibeRef.value?.remove(String(item.id));
    sheetItems.value = sheetItems.value.filter((candidate) => candidate.id !== item.id);

    if (sheetItems.value.length === 0) {
        emit('close');
    }
}

function shouldShowReactions(item: VibeViewerItem, hovered: boolean, active: boolean): boolean {
    const feedItem = getFeedItemFromVibeItem(item);

    return Boolean(
        feedItem
        && (
            hovered
            || active
            || props.itemInteractions.reactions.hasActiveReaction(feedItem)
            || props.itemInteractions.reactions.hasBlacklistState(feedItem)
        ),
    );
}

function handleReaction(item: VibeViewerItem, type: ReactionType): void {
    const feedItem = getFeedItemFromVibeItem(item);

    if (feedItem) {
        removeSheetItem(feedItem);
        props.itemInteractions.reactions.onFileReaction(feedItem, type);
    }
}

async function handleBlacklist(item: VibeViewerItem): Promise<void> {
    const feedItem = getFeedItemFromVibeItem(item);

    if (feedItem) {
        removeSheetItem(feedItem);
        await props.itemInteractions.reactions.onFileBlacklist(feedItem);
    }
}

const mouseShortcuts = createBrowseV2MouseShortcutHandlers({
    getCurrentItem: () => sheetItems.value[0] ?? null,
    getItemFromTarget: getShortcutItemFromTarget,
    getSurfaceMode: () => 'list',
    onBlacklist: async (item) => {
        removeSheetItem(item);
        await props.itemInteractions.reactions.onFileBlacklist(item);
    },
    onReaction: (item, type) => {
        removeSheetItem(item);
        props.itemInteractions.reactions.onFileReaction(item, type);
    },
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-200 ease-out"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition duration-[160ms] ease-in"
        leave-from-class="translate-y-0 opacity-100"
        leave-to-class="translate-y-4 opacity-0"
    >
        <section
            v-if="open"
            class="absolute inset-0 z-[70] flex min-h-0 flex-col overflow-hidden border border-white/10 bg-[#05060a] text-[#f7f1ea] shadow-[0_50px_140px_-60px_rgba(0,0,0,0.96)]"
            data-test="container-related-items-sheet"
        >
            <header class="flex h-16 shrink-0 items-center justify-between border-b border-white/10 bg-black/40 px-5">
                <div class="min-w-0">
                    <p class="m-0 truncate text-sm font-semibold text-white" data-test="container-related-items-sheet-title">
                        {{ containerLabel }}
                    </p>
                    <p class="m-0 text-xs text-white/50" data-test="container-related-items-sheet-count">
                        {{ itemCountLabel }}
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex h-10 w-10 items-center justify-center border border-white/12 bg-white/5 text-white/72 transition hover:border-white/24 hover:bg-white/10 hover:text-white"
                    aria-label="Close related items sheet"
                    data-test="container-related-items-sheet-close"
                    @click="emit('close')"
                >
                    <X :size="17" />
                </button>
            </header>

            <div
                class="relative min-h-0 flex-1"
                @click.capture="mouseShortcuts.handleClickCapture"
                @contextmenu.capture="mouseShortcuts.handleContextMenuCapture"
                @mousedown.capture="mouseShortcuts.handleMouseDownCapture"
                @auxclick.capture="mouseShortcuts.handleAuxClickCapture"
            >
                <VibeLayout
                    :key="viewerKey"
                    :ref="handleVibeRef"
                    class="h-full min-h-0 w-full"
                    :initial-state="initialState"
                    :page-size="items.length"
                    :show-end-badge="false"
                    :show-status-badges="false"
                    :surface-mode="'list'"
                    empty-state-mode="hidden"
                    @asset-loads="handleAssetLoads"
                >
                    <template #grid-item-overlay="{ item, hovered, active, index }">
                        <div class="pointer-events-none absolute inset-0 z-[5]">
                            <div
                                v-if="shouldShowReactions(item as VibeViewerItem, hovered, active)"
                                class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center px-3 pb-3"
                            >
                                <div class="pointer-events-auto">
                                    <FileReactions
                                        :file-id="((item as VibeViewerItem).feedItem as FeedItem).id"
                                        :reaction="(((item as VibeViewerItem).feedItem as FeedItem).reaction ?? null)"
                                        :blacklisted-at="(((item as VibeViewerItem).feedItem as FeedItem).blacklisted_at ?? null)"
                                        :previewed-count="(((item as VibeViewerItem).feedItem as FeedItem).previewed_count ?? 0)"
                                        :viewed-count="(((item as VibeViewerItem).feedItem as FeedItem).seen_count ?? 0)"
                                        :current-index="index"
                                        :total-items="sheetItems.length"
                                        variant="small"
                                        @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                        @blacklist="() => handleBlacklist(item as VibeViewerItem)"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>
                    <template #fullscreen-footer="{ item, index, total }">
                        <div class="flex justify-center">
                            <FileReactions
                                :file-id="((item as VibeViewerItem).feedItem as FeedItem).id"
                                :reaction="(((item as VibeViewerItem).feedItem as FeedItem).reaction ?? null)"
                                :blacklisted-at="(((item as VibeViewerItem).feedItem as FeedItem).blacklisted_at ?? null)"
                                :previewed-count="(((item as VibeViewerItem).feedItem as FeedItem).previewed_count ?? 0)"
                                :viewed-count="(((item as VibeViewerItem).feedItem as FeedItem).seen_count ?? 0)"
                                :current-index="index"
                                :total-items="total"
                                variant="default"
                                @reaction="(type) => handleReaction(item as VibeViewerItem, type)"
                                @blacklist="() => handleBlacklist(item as VibeViewerItem)"
                            />
                        </div>
                    </template>
                </VibeLayout>
            </div>
        </section>
    </Transition>
</template>
