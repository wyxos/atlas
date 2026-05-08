<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue';
import { Plus } from 'lucide-vue-next';
import TabPanel from '../components/ui/TabPanel.vue';
import Tab from '../components/Tab.vue';
import TabContentV2 from '../components/TabContentV2.vue';
import { Button } from '@/components/ui/button';
import { provideBrowseGlobalStartPanel } from '@/composables/useBrowseGlobalStartPanel';
import { useTabs } from '@/composables/useTabs';
import { undoLatestQueuedReaction } from '@/utils/reactionQueue';
import type { ReactionType } from '@/types/reaction';

type ContainerTabPayload = {
    label: string;
    params: Record<string, unknown>;
};

type DropIndicator = 'before' | 'after';
type TabContentV2Handle = {
    cancelFill?: () => void;
    stopAutoScroll?: () => void;
};

const isPanelMinimized = ref(false);
const activeTabContentRef = ref<TabContentV2Handle | null>(null);
const browseTabListRef = ref<HTMLElement | null>(null);
const draggedTabId = ref<number | null>(null);
const dropTargetTabId = ref<number | null>(null);
const dropIndicator = ref<DropIndicator | null>(null);
const tabMasonryLoadingStates = ref<Map<number, boolean>>(new Map());
const tabDataLoadingStates = ref<Map<number, boolean>>(new Map());

provideBrowseGlobalStartPanel();

async function switchTab(tabId: number, skipActiveCheck: boolean = false): Promise<void> {
    if (!skipActiveCheck && activeTabId.value === tabId) {
        return;
    }

    const tab = tabs.value.find(currentTab => currentTab.id === tabId);
    if (!tab) {
        return;
    }

    const previousActiveTabId = activeTabId.value;
    if (previousActiveTabId !== null && previousActiveTabId !== tabId) {
        activeTabContentRef.value?.cancelFill?.();
        activeTabContentRef.value?.stopAutoScroll?.();
    }

    activeTabId.value = tabId;

    if (!tab.isActive) {
        try {
            await setActiveTab(tabId);
        } catch (error) {
            activeTabId.value = previousActiveTabId;
            throw error;
        }
    }
}

const {
    tabs,
    activeTabId,
    loadTabs: loadTabsFromComposable,
    createTab,
    closeTabs,
    duplicateTab,
    getActiveTab,
    reorderTabs,
    updateTabLabel,
    updateTabCustomLabel,
    setActiveTab,
} = useTabs(switchTab);

const activeTab = computed(() => getActiveTab());

function handleReaction(fileId: number, type: ReactionType): void {
    void fileId;
    void type;
}

function handleTabMasonryLoadingChange(tabId: number, isLoading: boolean): void {
    if (isLoading) {
        tabMasonryLoadingStates.value.set(tabId, true);
        return;
    }

    tabMasonryLoadingStates.value.delete(tabId);
}

function handleTabDataLoadingChange(tabId: number, isLoading: boolean): void {
    if (isLoading) {
        tabDataLoadingStates.value.set(tabId, true);
        return;
    }

    tabDataLoadingStates.value.delete(tabId);
}

function isTabDataLoading(tabId: number): boolean {
    return tabDataLoadingStates.value.get(tabId) ?? false;
}

function handleMasonryLoadingChangeFromTab(isLoading: boolean): void {
    if (activeTabId.value !== null) {
        handleTabMasonryLoadingChange(activeTabId.value, isLoading);
    }
}

function handleTabDataLoadingChangeFromTab(isLoading: boolean): void {
    if (activeTabId.value !== null) {
        handleTabDataLoadingChange(activeTabId.value, isLoading);
    }
}

function handleUpdateTabLabel(label: string): void {
    if (activeTabId.value === null) {
        return;
    }

    updateTabLabel(activeTabId.value, label);
}

function handleRenameTab(tabId: number, customLabel: string | null): void {
    updateTabCustomLabel(tabId, customLabel);
}

function getTabListScrollContainer(): HTMLElement | null {
    return browseTabListRef.value?.parentElement ?? null;
}

function getScrollPaddingTop(element: HTMLElement): number {
    const paddingTop = Number.parseFloat(window.getComputedStyle(element).paddingTop);

    return Number.isNaN(paddingTop) ? 0 : paddingTop;
}

async function scrollTabIntoView(tabId: number | null): Promise<void> {
    if (tabId === null) {
        return;
    }

    await nextTick();

    const scrollContainer = getTabListScrollContainer();
    const tabElement = browseTabListRef.value?.querySelector(`[data-browse-tab-id="${tabId}"]`);

    if (!scrollContainer || !(tabElement instanceof HTMLElement)) {
        return;
    }

    scrollContainer.scrollTo({
        top: Math.max(
            0,
            scrollContainer.scrollTop
                + tabElement.getBoundingClientRect().top
                - scrollContainer.getBoundingClientRect().top
                - getScrollPaddingTop(scrollContainer),
        ),
        behavior: 'smooth',
    });
}

async function handleCreateTab(): Promise<void> {
    const tab = await createTab();
    await scrollTabIntoView(tab.id);
}

async function handleDuplicateTab(tabId: number): Promise<void> {
    const tab = await duplicateTab(tabId);

    if (tab && activeTabId.value === tab.id) {
        await scrollTabIntoView(tab.id);
    }
}

async function handleOpenContainerTab(payload: ContainerTabPayload): Promise<void> {
    await createTab({
        label: payload.label,
        params: payload.params,
        activate: false,
    });
}

function pruneTabLoadingState(tabIds: number[]): void {
    for (const tabId of tabIds) {
        tabMasonryLoadingStates.value.delete(tabId);
        tabDataLoadingStates.value.delete(tabId);
    }
}

function resetDragState(): void {
    draggedTabId.value = null;
    dropTargetTabId.value = null;
    dropIndicator.value = null;
}

async function handleCloseTabs(tabIds: number[], preferredTabId: number | null = null): Promise<void> {
    const ids = [...new Set(tabIds)].filter(tabId => tabs.value.some(tab => tab.id === tabId));
    if (ids.length === 0) {
        return;
    }

    const previousActiveTabId = activeTabId.value;

    try {
        await closeTabs(ids, {
            preferredTabId,
        });
        pruneTabLoadingState(ids);

        if (activeTabId.value !== previousActiveTabId) {
            await scrollTabIntoView(activeTabId.value);
        }
    } finally {
        resetDragState();
    }
}

async function handleCloseTab(tabId: number): Promise<void> {
    await handleCloseTabs([tabId]);
}

async function handleCloseTabsRelative(tabId: number, mode: 'above' | 'below' | 'others'): Promise<void> {
    const currentIndex = tabs.value.findIndex(tab => tab.id === tabId);
    if (currentIndex === -1) {
        return;
    }

    let ids: number[] = [];

    if (mode === 'above') {
        ids = tabs.value.slice(0, currentIndex).map(tab => tab.id);
    } else if (mode === 'below') {
        ids = tabs.value.slice(currentIndex + 1).map(tab => tab.id);
    } else {
        ids = tabs.value
            .filter(tab => tab.id !== tabId)
            .map(tab => tab.id);
    }

    if (ids.length === 0) {
        return;
    }

    await handleCloseTabs(ids, tabId);
}

function isTabDragSource(tabId: number): boolean {
    return draggedTabId.value === tabId;
}

function getTabDropIndicator(tabId: number): DropIndicator | null {
    if (dropTargetTabId.value !== tabId) {
        return null;
    }

    return dropIndicator.value;
}

function moveTabIds(orderedIds: number[], draggedId: number, targetId: number, side: DropIndicator): number[] {
    const remainingIds = orderedIds.filter(tabId => tabId !== draggedId);
    const targetIndex = remainingIds.indexOf(targetId);

    if (targetIndex === -1) {
        return orderedIds;
    }

    const insertIndex = side === 'before' ? targetIndex : targetIndex + 1;
    remainingIds.splice(insertIndex, 0, draggedId);

    return remainingIds;
}

function handleTabDragStart(tabId: number): void {
    draggedTabId.value = tabId;
    dropTargetTabId.value = null;
    dropIndicator.value = null;
}

function handleTabDragOver(tabId: number, side: DropIndicator): void {
    if (draggedTabId.value === null || draggedTabId.value === tabId) {
        return;
    }

    dropTargetTabId.value = tabId;
    dropIndicator.value = side;
}

async function handleTabDrop(tabId: number, side: DropIndicator): Promise<void> {
    const draggedId = draggedTabId.value;
    const currentOrderedIds = tabs.value.map(tab => tab.id);
    resetDragState();

    if (draggedId === null || draggedId === tabId) {
        return;
    }

    const nextOrderedIds = moveTabIds(currentOrderedIds, draggedId, tabId, side);
    await reorderTabs(nextOrderedIds);
}

function handleTabDragEnd(): void {
    resetDragState();
}

function isEditableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return target.isContentEditable
        || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
        || target.closest('[contenteditable="true"]') !== null;
}

function handleUndoShortcut(event: KeyboardEvent): void {
    if (event.defaultPrevented || event.repeat) {
        return;
    }

    if (event.key.toLowerCase() !== 'z') {
        return;
    }

    if ((!event.ctrlKey && !event.metaKey) || event.shiftKey || event.altKey) {
        return;
    }

    if (isEditableTarget(event.target)) {
        return;
    }

    if (!undoLatestQueuedReaction()) {
        return;
    }

    event.preventDefault();
}

async function loadTabs(): Promise<void> {
    try {
        await loadTabsFromComposable();
    } catch {
        // Error already logged in composable.
    }
}

onMounted(async () => {
    window.addEventListener('keydown', handleUndoShortcut);
    await loadTabs();
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleUndoShortcut);
});
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="flex-1 min-h-0 relative flex">
            <TabPanel :model-value="true" v-model:is-minimized="isPanelMinimized">
                <template #tabs="{ isMinimized }">
                    <div ref="browseTabListRef" class="flex flex-col gap-2" data-test="browse-tab-list">
                        <Tab
                            v-for="(tab, index) in tabs"
                            :key="tab.id"
                            :id="tab.id"
                            :label="tab.label"
                            :custom-label="tab.customLabel ?? null"
                            :is-active="tab.id === activeTabId"
                            :is-minimized="isMinimized"
                            :is-loading="isTabDataLoading(tab.id)"
                            :is-masonry-loading="tabMasonryLoadingStates.get(tab.id) ?? false"
                            :is-dragging="isTabDragSource(tab.id)"
                            :drop-indicator="getTabDropIndicator(tab.id)"
                            :can-close-above="index > 0"
                            :can-close-below="index < tabs.length - 1"
                            :can-close-others="tabs.length > 1"
                            :data-browse-tab-id="tab.id"
                            :data-test="`browse-tab-${tab.id}`"
                            @click="switchTab(tab.id)"
                            @close="handleCloseTab(tab.id)"
                            @rename="handleRenameTab(tab.id, $event)"
                            @duplicate="handleDuplicateTab(tab.id)"
                            @close-above="handleCloseTabsRelative(tab.id, 'above')"
                            @close-below="handleCloseTabsRelative(tab.id, 'below')"
                            @close-others="handleCloseTabsRelative(tab.id, 'others')"
                            @drag-start="handleTabDragStart(tab.id)"
                            @drag-over="handleTabDragOver(tab.id, $event)"
                            @drag-drop="handleTabDrop(tab.id, $event)"
                            @drag-end="handleTabDragEnd"
                        />
                    </div>
                </template>
                <template #footer="{ isMinimized }">
                    <Button
                        variant="dashed"
                        size="sm"
                        :class="['w-full rounded h-8', isMinimized ? 'justify-center' : 'justify-start']"
                        aria-label="New tab"
                        data-test="create-tab-button"
                        @click="handleCreateTab"
                    >
                        <Plus :size="16" />
                        <span
                            v-show="!isMinimized"
                            class="ml-2 transition-opacity duration-200"
                            :class="!isMinimized ? 'opacity-100' : 'opacity-0'"
                        >
                            New Tab
                        </span>
                    </Button>
                </template>
            </TabPanel>
            <div class="flex-1 min-h-0 transition-all duration-300 flex flex-col relative">
                <TabContentV2
                    v-if="activeTab"
                    :key="activeTab.id"
                    ref="activeTabContentRef"
                    :tab-id="activeTab.id"
                    :available-services="[]"
                    :on-reaction="handleReaction"
                    :on-loading-change="handleMasonryLoadingChangeFromTab"
                    :on-tab-data-loading-change="handleTabDataLoadingChangeFromTab"
                    :on-update-tab-label="handleUpdateTabLabel"
                    :on-open-container-tab="handleOpenContainerTab"
                />
                <div v-else class="flex items-center justify-center h-full" data-test="no-tabs-message">
                    <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease-in-out;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
