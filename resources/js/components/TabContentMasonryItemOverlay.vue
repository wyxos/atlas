<script setup lang="ts">
import { computed } from 'vue';
import { Info } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import Pill from './ui/Pill.vue';
import FileReactions from './FileReactions.vue';
import DislikeProgressBar from './DislikeProgressBar.vue';
import type { FeedItem } from '@/composables/useTabs';
import type { ReactionType } from '@/types/reaction';
import type { TabContentContainerInteractions } from '@/composables/useTabContentContainerInteractions';
import type { TabContentItemInteractions } from '@/composables/useTabContentItemInteractions';
import type { TabContentPromptDialog } from '@/composables/useTabContentPromptDialog';

interface Props {
    item: FeedItem;
    itemsLength: number;
    removeItem: (item: FeedItem) => void;
    containers: TabContentContainerInteractions;
    itemInteractions: TabContentItemInteractions;
    promptDialog: TabContentPromptDialog;
}

const props = defineProps<Props>();

const isHovered = computed(() => props.itemInteractions.state.hoveredItemId.value === props.item.id);
const isPreloaded = computed(() => props.itemInteractions.preload.isItemPreloaded(props.item.id));
const itemContainers = computed(() => props.containers.badges.getContainersForItem(props.item));
const showContainers = computed(() => isHovered.value && isPreloaded.value && itemContainers.value.length > 0);
const showPromptButton = computed(() => isHovered.value && isPreloaded.value);
const showReactions = computed(() => (
    (isHovered.value || props.itemInteractions.reactions.hasActiveReaction(props.item))
    && isPreloaded.value
));
const countdownActive = computed(() => props.itemInteractions.autoDislikeQueue.hasActiveCountdown(props.item.id));
const showDislikeProgress = computed(() => props.item.will_auto_dislike && countdownActive.value);
const currentIndex = computed(() => props.itemInteractions.state.getItemIndex(props.item.id));
const siblingMaskClass = computed(() => (
    props.containers.badges.activeHoveredContainerId.value !== null
    && !props.containers.badges.isSiblingItem(props.item, props.containers.badges.activeHoveredContainerId.value)
        ? 'opacity-100'
        : 'opacity-0'
));
const countdownLabel = computed(() => props.itemInteractions.autoDislikeQueue.formatCountdown(
    props.itemInteractions.autoDislikeQueue.getCountdownRemainingTime(props.item.id),
));

function handleReaction(type: ReactionType): void {
    props.itemInteractions.reactions.onFileReaction(props.item, type);
}
</script>

<template>
    <div class="relative h-full w-full" @mouseenter="(event) => itemInteractions.item.onMouseEnter(event, item)"
        @mouseleave="(event) => itemInteractions.item.onMouseLeave(event, item)" :data-file-id="item.id"
        :class="[
            'overflow-hidden rounded-xl transition-colors transition-opacity duration-200',
            containers.badges.getMasonryItemClasses.value(item),
        ]" @click="(event) => itemInteractions.item.onClick(event, item)"
        @contextmenu="(event) => itemInteractions.item.onContextMenu(event, item)"
        @mousedown="(event) => itemInteractions.item.onMouseDown(event, item)"
        @auxclick="(event) => itemInteractions.item.onAuxClick(event, item)">
        <div class="absolute inset-0 bg-black/50 pointer-events-none transition-opacity duration-200"
            :class="siblingMaskClass" />

        <Transition name="fade">
            <div v-if="showContainers" class="absolute top-2 left-2 z-50 pointer-events-auto flex flex-col gap-1">
                <div v-for="container in itemContainers" :key="container.id" class="cursor-pointer"
                    @mouseenter="containers.pillHandlers.onMouseEnter(container.id)"
                    @mouseleave="containers.pillHandlers.onMouseLeave"
                    @click.stop="(event) => containers.pillHandlers.onClick(container.id, event)"
                    @dblclick.prevent.stop="(event) => containers.pillHandlers.onDoubleClick(container.id, event)"
                    @contextmenu.prevent.stop="(event) => containers.pillHandlers.onContextMenu(container.id, event)"
                    @auxclick.prevent.stop="(event) => containers.pillHandlers.onAuxClick(container.id, event)"
                    @mousedown.stop="containers.pillHandlers.onMouseDown">
                    <Pill :label="container.type" :value="containers.badges.getItemCountForContainerId(container.id)"
                        :variant="containers.badges.getVariantForContainerType(container.type)"
                        :dismissible="containers.isBlacklistable(container) ? 'danger' : false"
                        @dismiss="() => containers.pillHandlers.onDismiss(container)" />
                </div>
            </div>
        </Transition>

        <Transition name="fade">
            <div v-if="showPromptButton" class="absolute top-2 right-2 z-50 pointer-events-auto">
                <Button variant="ghost" size="sm" class="h-7 w-7 p-0 bg-black/50 hover:bg-black/70 text-white"
                    @click.stop="promptDialog.open(item)" aria-label="Show prompt">
                    <Info :size="14" />
                </Button>
            </div>
        </Transition>

        <Transition name="fade">
            <div v-if="showReactions" class="absolute bottom-0 left-0 right-0 flex justify-center pb-2 z-50 pointer-events-auto">
                <FileReactions :file-id="item.id" :reaction="item.reaction as ({ type: string } | null | undefined)"
                    :previewed-count="item.previewed_count" :viewed-count="item.seen_count" :current-index="currentIndex"
                    :total-items="itemsLength" variant="small" :remove-item="removeItem" @reaction="handleReaction" />
            </div>
        </Transition>

        <DislikeProgressBar v-if="showDislikeProgress"
            :progress="itemInteractions.autoDislikeQueue.getCountdownProgress(item.id)" :countdown="countdownLabel"
            :is-frozen="itemInteractions.autoDislikeQueue.isFrozen.value" :is-hovered="isHovered && countdownActive" />
    </div>
</template>
