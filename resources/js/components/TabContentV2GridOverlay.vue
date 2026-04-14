<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { Info, Trash2 } from 'lucide-vue-next';
import type { VibeViewerItem } from '@wyxos/vibe';
import type { LocalFileDeletion } from '@/composables/useLocalFileDeletion';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { TabContentPromptDialog } from '@/composables/useTabContentPromptDialog';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import { Button } from '@/components/ui/button';
import DislikeProgressBar from './DislikeProgressBar.vue';
import FileReactions from './FileReactions.vue';
import Pill from './ui/Pill.vue';

interface Props {
    active: boolean;
    hovered: boolean;
    index: number;
    item: FeedItem;
    totalItems: number;
    vibeItem: VibeViewerItem;
    containers: TabContentContainerInteractions;
    itemInteractions: TabContentItemInteractions;
    promptDialog: TabContentPromptDialog;
    localFileDeletion: LocalFileDeletion;
    onReaction: (item: VibeViewerItem, type: ReactionType) => void | Promise<void>;
}

const props = defineProps<Props>();

const itemContainers = computed(() => props.containers.badges.getContainersForItem(props.item));
const isPreloaded = computed(() => props.itemInteractions.preload.isItemPreloaded(props.item.id));
const showContainers = computed(() => props.hovered && isPreloaded.value && itemContainers.value.length > 0);
const showPromptButton = computed(() => props.hovered && isPreloaded.value);
const showDeleteButton = computed(() => props.hovered
    && isPreloaded.value
    && props.localFileDeletion.actions.canDelete(props.item));
const showReactions = computed(() => (
    (props.hovered || props.active || props.itemInteractions.reactions.hasActiveReaction(props.item))
    && isPreloaded.value
));
const showDislikeProgress = computed(() => (
    props.item.will_auto_dislike === true
    && props.itemInteractions.autoDislikeQueue.hasActiveCountdown(props.item.id)
));
const countdownLabel = computed(() => props.itemInteractions.autoDislikeQueue.formatCountdown(
    props.itemInteractions.autoDislikeQueue.getCountdownRemainingTime(props.item.id),
));
const pausedByHover = ref(false);

function syncHoverPauseState(hovered: boolean): void {
    const hasActiveCountdown = props.itemInteractions.autoDislikeQueue.hasActiveCountdown(props.item.id);

    if (hovered && hasActiveCountdown) {
        pausedByHover.value = true;
        props.itemInteractions.autoDislikeQueue.freezeAll();
        return;
    }

    if (pausedByHover.value) {
        pausedByHover.value = false;
        props.itemInteractions.autoDislikeQueue.unfreezeAll();
    }
}

watch(
    () => props.hovered,
    (hovered) => {
        syncHoverPauseState(hovered);
    },
    { immediate: true },
);

onUnmounted(() => {
    if (pausedByHover.value) {
        pausedByHover.value = false;
        props.itemInteractions.autoDislikeQueue.unfreezeAll();
    }
});
</script>

<template>
    <div class="pointer-events-none absolute inset-0 z-[5]">
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
            v-if="showPromptButton || showDeleteButton"
            class="pointer-events-auto absolute right-2 top-2 flex items-center gap-2"
        >
            <Button
                v-if="showPromptButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-black/50 p-0 text-white hover:bg-black/70"
                aria-label="Show prompt"
                @click.stop="promptDialog.open(item)"
            >
                <Info :size="14" />
            </Button>
            <Button
                v-if="showDeleteButton"
                variant="ghost"
                size="sm"
                class="h-7 w-7 bg-danger-700/80 p-0 text-white hover:bg-danger-600"
                aria-label="Delete local file"
                data-test="local-file-delete-trigger"
                @click.stop="localFileDeletion.actions.open(item)"
            >
                <Trash2 :size="14" />
            </Button>
        </div>

        <div
            v-if="showReactions"
            class="pointer-events-none absolute inset-x-0 bottom-0 flex justify-center px-3 pb-3"
        >
            <div class="pointer-events-auto">
                <FileReactions
                    :file-id="item.id"
                    :reaction="item.reaction ?? null"
                    :previewed-count="item.previewed_count ?? 0"
                    :viewed-count="item.seen_count ?? 0"
                    :current-index="index"
                    :total-items="totalItems"
                    variant="small"
                    @reaction="(type) => onReaction(vibeItem, type)"
                />
            </div>
        </div>

        <DislikeProgressBar
            v-if="showDislikeProgress"
            :progress="itemInteractions.autoDislikeQueue.getCountdownProgress(item.id)"
            :countdown="countdownLabel"
            :is-frozen="itemInteractions.autoDislikeQueue.isFrozen.value"
            :is-hovered="hovered"
        />
    </div>
</template>
