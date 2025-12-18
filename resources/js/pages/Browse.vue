<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Plus } from 'lucide-vue-next';
import TabPanel from '../components/ui/TabPanel.vue';
import BrowseTab from '../components/BrowseTab.vue';
import BrowseTabContent from '../components/BrowseTabContent.vue';
import { Button } from '@/components/ui/button';
import { useBrowseTabs, type MasonryItem } from '@/composables/useBrowseTabs';
import { useBrowseService } from '@/composables/useBrowseService';
import { useReactionQueue } from '@/composables/useReactionQueue';
import { createReactionCallback } from '@/utils/reactions';

const isPanelMinimized = ref(false);

// Track masonry loading state per tab (for pill indicator)
const tabMasonryLoadingStates = ref<Map<number, boolean>>(new Map());

// Track tab data loading state per tab (for spinner in tab panel)
const tabDataLoadingStates = ref<Map<number, boolean>>(new Map());

// Reaction queue
const { queueReaction } = useReactionQueue();

// Simplified tab switching - just set active tab ID
async function switchTab(tabId: number, skipActiveCheck: boolean = false): Promise<void> {
    if (!skipActiveCheck && activeTabId.value === tabId) {
        return;
    }

    const tab = tabs.value.find(t => t.id === tabId);
    if (!tab) {
        return;
    }

    activeTabId.value = tabId;

    // Only set the tab as active in the backend if it's not already marked as active
    // This avoids redundant API calls when restoring state from backend
    if (!tab.isActive) {
        await setActiveTab(tabId);
    }
}

// Tab management using composable - pass switchTab callback for UI handling
const {
    tabs,
    activeTabId,
    isLoadingTabs,
    loadTabs: loadTabsFromComposable,
    createTab,
    closeTab,
    getActiveTab,
    updateActiveTab,
    loadTabItems,
    setActiveTab,
} = useBrowseTabs(switchTab);

// Computed property for active tab to ensure proper reactivity
const activeTab = computed(() => getActiveTab());

// Browse service composable - just for fetching available services
const {
    availableServices,
    fetchServices: fetchServicesFromComposable,
} = useBrowseService();

// Handle reaction
async function handleReaction(
    fileId: number,
    type: 'love' | 'like' | 'dislike' | 'funny'
): Promise<void> {
    // Try to find the item from the active tab to get preview URL
    const activeTabData = activeTab.value;
    const item = activeTabData?.itemsData?.find((i: MasonryItem) => i.id === fileId);
    const previewUrl = item?.src;

    queueReaction(fileId, type, createReactionCallback(), previewUrl);
}

// Handle masonry loading state changes from tab content (for pill)
function handleTabMasonryLoadingChange(tabId: number, isLoading: boolean): void {
    if (isLoading) {
        tabMasonryLoadingStates.value.set(tabId, true);
    } else {
        tabMasonryLoadingStates.value.delete(tabId);
    }
}

// Handle tab data loading state changes (for spinner in tab panel)
function handleTabDataLoadingChange(tabId: number, isLoading: boolean): void {
    if (isLoading) {
        tabDataLoadingStates.value.set(tabId, true);
    } else {
        tabDataLoadingStates.value.delete(tabId);
    }
}

// Get tab data loading state (for spinner)
function isTabDataLoading(tabId: number): boolean {
    return tabDataLoadingStates.value.get(tabId) ?? false;
}

// Handle masonry loading change from tab content (wrapper for prop binding)
function handleMasonryLoadingChangeFromTab(isLoading: boolean): void {
    if (activeTabId.value !== null) {
        handleTabMasonryLoadingChange(activeTabId.value, isLoading);
    }
}

// Handle tab data loading change from tab content (wrapper for prop binding)
function handleTabDataLoadingChangeFromTab(isLoading: boolean): void {
    if (activeTabId.value !== null) {
        handleTabDataLoadingChange(activeTabId.value, isLoading);
    }
}


// Tab management function
// Flow: Load tabs (without files) > Determine focus tab > If has files, load them > Restore query params
async function loadTabs(): Promise<void> {
    try {
        // Step 1: Load all tabs without files (items_data is not included)
        await loadTabsFromComposable();

        // Step 2: Only focus a tab if one is already marked as active
        // Don't auto-activate tabs - let user choose if none is active
        if (tabs.value.length > 0 && activeTabId.value === null) {
            const activeTab = tabs.value.find(t => t.isActive);
            
            // Only switch to tab if one is already marked as active
            if (activeTab) {
                // Step 3: If tab has files, load items lazily
                // Step 4: Restore query params (handled in switchTab)
                // Pass skipActiveCheck=true since activeTabId is null, so switchTab will set it
                await switchTab(activeTab.id, true);
            }
            // If no tab is active, activeTabId remains null and user must select one
        }
        // If no tabs exist, render nothing until a tab is created
    } catch (error) {
        // Error already logged in composable
        // Don't create a tab on error - let user create manually
    }
}






// Initialize on mount
onMounted(async () => {
    // Fetch available services first (in parallel with tabs for faster loading)
    const servicesPromise = fetchServicesFromComposable();

    // Load tabs - loadTabs will set the first tab as active if tabs exist
    const tabsPromise = loadTabs();

    // Wait for both to complete
    await Promise.all([servicesPromise, tabsPromise]);
});
</script>

<template>
    <div class="h-full flex flex-col">
        <div class="flex-1 min-h-0 relative flex">
            <TabPanel :model-value="true" v-model:is-minimized="isPanelMinimized">
                <template #tabs="{ isMinimized }">
                    <BrowseTab v-for="tab in tabs" :key="tab.id" :id="tab.id" :label="tab.label"
                        :is-active="tab.id === activeTabId" :is-minimized="isMinimized"
                        :is-loading="isTabDataLoading(tab.id)"
                        :is-masonry-loading="tabMasonryLoadingStates.get(tab.id) ?? false" @click="switchTab(tab.id)"
                        @close="closeTab(tab.id)" :data-test="`browse-tab-${tab.id}`" />
                </template>
                <template #footer="{ isMinimized }">
                    <Button variant="dashed" size="sm" @click="createTab"
                        :class="['w-full rounded h-8', isMinimized ? 'justify-center' : 'justify-start']"
                        aria-label="New tab" data-test="create-tab-button">
                        <Plus :size="16" />
                        <span v-show="!isMinimized" class="ml-2 transition-opacity duration-200"
                            :class="!isMinimized ? 'opacity-100' : 'opacity-0'">New Tab</span>
                    </Button>
                </template>
            </TabPanel>
            <div class="flex-1 min-h-0 transition-all duration-300 flex flex-col relative">
                <BrowseTabContent v-if="activeTab" :key="activeTab.id" :tab="activeTab"
                    :available-services="availableServices" :update-active-tab="updateActiveTab"
                    :load-tab-items="loadTabItems" :on-reaction="handleReaction"
                    :on-loading-change="handleMasonryLoadingChangeFromTab"
                    :on-tab-data-loading-change="handleTabDataLoadingChangeFromTab" />
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
