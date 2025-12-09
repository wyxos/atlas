<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue';
import { Masonry } from '@wyxos/vibe';
import { Loader2, Plus, X } from 'lucide-vue-next';
import Pill from '../components/ui/Pill.vue';
import TabPanel from '../components/ui/TabPanel.vue';
import BrowseTab from '../components/BrowseTab.vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '../components/ui/select';
import { useBrowseTabs, type MasonryItem, type BrowseTabData } from '../composables/useBrowseTabs';
import { useBackfill } from '../composables/useBackfill';

type GetPageResult = {
    items: MasonryItem[];
    nextPage: string | number | null; // Can be cursor string or number
};

const items = ref<MasonryItem[]>([]);
const masonry = ref<InstanceType<typeof Masonry> | null>(null);
const currentPage = ref<string | number | null>(1); // Starts as 1, becomes cursor string, can be null
const nextCursor = ref<string | number | null>(null); // The next page/cursor from API (service handles format)
const loadAtPage = ref<string | number | null>(null); // Initial page to load - set to 1 only when needed, null prevents auto-load
const isTabRestored = ref(false); // Track if we restored from a tab to prevent duplicate loading
const pendingRestoreNextCursor = ref<string | number | null>(null); // Holds the saved cursor we should load first after restoring a tab
const isPanelMinimized = ref(false);

// Overlay state for highlighting a clicked masonry item
const masonryContainer = ref<HTMLElement | null>(null);
const tabContentContainer = ref<HTMLElement | null>(null); // Tab content container (includes header, masonry, footer)
const overlayRect = ref<{ top: number; left: number; width: number; height: number } | null>(null);
const overlayImage = ref<{ src: string; srcset?: string; sizes?: string; alt?: string } | null>(null);
const overlayBorderRadius = ref<string | null>(null);
const overlayKey = ref(0); // Key to force image element recreation on each click
const overlayIsAnimating = ref(false); // Track if overlay is animating to center
const overlayImageSize = ref<{ width: number; height: number } | null>(null); // Store original image size
const overlayIsFilled = ref(false); // Track if overlay has expanded to fill container
const backdropOpacity = ref(0); // Backdrop opacity for smooth fade-in
const imageCenterPosition = ref<{ top: number; left: number } | null>(null); // Exact center position when filled

function closeOverlay(): void {
    overlayKey.value++;
    overlayIsAnimating.value = false;
    overlayIsFilled.value = false;
    backdropOpacity.value = 0;
    overlayImageSize.value = null;
    imageCenterPosition.value = null;
    overlayRect.value = null;
    overlayImage.value = null;
    overlayBorderRadius.value = null;
}

async function onMasonryClick(e: MouseEvent) {
    const container = masonryContainer.value;
    const tabContent = tabContentContainer.value;
    if (!container || !tabContent) return;

    const target = e.target as HTMLElement | null;
    if (!target) return;

    // Find the nearest masonry item element
    const itemEl = target.closest('.masonry-item') as HTMLElement | null;

    if (!itemEl || !container.contains(itemEl)) {
        // Clicked outside an item → clear overlay
        closeOverlay();
        return;
    }

    // Compute position relative to the tab content container (not masonry container)
    const itemBox = itemEl.getBoundingClientRect();
    const tabContentBox = tabContent.getBoundingClientRect();

    const top = itemBox.top - tabContentBox.top;
    const left = itemBox.left - tabContentBox.left;
    const width = itemBox.width;
    const height = itemBox.height;

    // Try to find an <img> inside the clicked masonry item
    const imgEl = itemEl.querySelector('img') as HTMLImageElement | null;
    if (!imgEl) {
        // No image found -> clear overlay (requirement is to show the same image)
        closeOverlay();
        return;
    }

    // Copy image attributes
    const src = imgEl.currentSrc || imgEl.getAttribute('src') || '';
    const srcset = imgEl.getAttribute('srcset') || undefined;
    const sizes = imgEl.getAttribute('sizes') || undefined;
    const alt = imgEl.getAttribute('alt') || '';

    // Compute the border radius from the masonry item so the overlay matches
    const computed = window.getComputedStyle(itemEl);
    const radius = computed.borderRadius || '';

    // Increment key to force image element recreation (prevents showing previous image)
    overlayKey.value++;

    // Store original image size to maintain it when container expands
    overlayImageSize.value = { width, height };
    overlayIsFilled.value = false;

    // Set initial position at clicked item location
    overlayRect.value = { top, left, width, height };
    overlayImage.value = { src, srcset, sizes, alt };
    overlayBorderRadius.value = radius || null;
    overlayIsAnimating.value = false;
    backdropOpacity.value = 0; // Start at 0 for fade-in

    // Animate to center after DOM update
    await nextTick();

    // Fade in backdrop smoothly
    requestAnimationFrame(() => {
        backdropOpacity.value = 0.75;
    });

    // Use requestAnimationFrame to ensure initial render is complete
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            const tabContent = tabContentContainer.value;
            if (!tabContent || !overlayRect.value) return;

            const tabContentBox = tabContent.getBoundingClientRect();
            const containerWidth = tabContentBox.width;
            const containerHeight = tabContentBox.height;

            // Calculate center position (centered both horizontally and vertically)
            // Round to avoid subpixel rendering issues
            const centerLeft = Math.round((containerWidth - width) / 2);
            const centerTop = Math.round((containerHeight - height) / 2);

            // Don't set imageCenterPosition yet - wait until container is filled
            // This prevents the image from switching positioning methods mid-animation

            // Mark as animating and update to center position
            overlayIsAnimating.value = true;
            overlayRect.value = {
                top: centerTop,
                left: centerLeft,
                width,
                height,
            };

            // After center animation completes (500ms), animate to fill container
            setTimeout(() => {
                if (!tabContent || !overlayRect.value || !overlayImageSize.value) return;

                // Mark as filled and update to fill entire tab content container
                // Keep object-cover during fill animation - don't switch to absolute positioning yet
                overlayIsFilled.value = true;
                overlayRect.value = {
                    top: 0,
                    left: 0,
                    width: containerWidth,
                    height: containerHeight,
                };

                // Don't switch to absolute positioning - keep using flexbox centering
                // This avoids any pixel glitches from switching positioning methods
            }, 500); // Match the transition duration
        });
    });
}

// Service selection state
const availableServices = ref<Array<{ key: string; label: string; defaults?: Record<string, any> }>>([]);
const selectedService = ref<string>(''); // Service selected in UI (not yet applied)
const isApplyingService = ref(false);

// Backfill state and handlers
const {
    backfill,
    onBackfillStart,
    onBackfillTick,
    onBackfillStop,
    onBackfillRetryStart,
    onBackfillRetryTick,
    onBackfillRetryStop,
} = useBackfill();

// Computed property to display page value (defaults to 1 if null)
const displayPage = computed(() => currentPage.value ?? 1);

// Get current tab's service
const currentTabService = computed(() => {
    if (!activeTabId.value) return null;
    const tab = getActiveTab();
    return tab?.queryParams?.service as string | null;
});

// Check if current tab has a service selected
const hasServiceSelected = computed(() => {
    const service = currentTabService.value;
    return typeof service === 'string' && service.length > 0;
});

// Fetch available services
async function fetchServices(): Promise<void> {
    try {
        // Fetch services from browse endpoint (will return services metadata)
        const response = await window.axios.get('/api/browse?page=1&limit=1');
        availableServices.value = response.data.services || [];

        // Fallback to default services if none returned
        if (availableServices.value.length === 0) {
            availableServices.value = [
                { key: 'civit-ai-images', label: 'CivitAI Images' },
                { key: 'wallhaven', label: 'Wallhaven' },
            ];
        }
    } catch (error) {
        console.error('Failed to fetch services:', error);
        // Fallback to default services
        availableServices.value = [
            { key: 'civit-ai-images', label: 'CivitAI Images' },
            { key: 'wallhaven', label: 'Wallhaven' },
        ];
    }
}

// Apply selected service to current tab
async function applyService(): Promise<void> {
    if (!activeTabId.value || !selectedService.value || isApplyingService.value) {
        return;
    }

    isApplyingService.value = true;
    try {
        const tab = getActiveTab();
        if (!tab) {
            return;
        }

        // Update tab's queryParams with service
        const updatedQueryParams = {
            ...tab.queryParams,
            service: selectedService.value,
            page: 1, // Reset to page 1 when changing service
            next: null,
        };

        // Clear existing items and reset pagination
        items.value = [];
        currentPage.value = 1;
        nextCursor.value = null;
        loadAtPage.value = 1;

        // Update tab
        updateActiveTab([], [], updatedQueryParams);

        // Reset masonry and trigger load
        if (masonry.value) {
            if (masonry.value.isLoading) {
                masonry.value.cancelLoad();
            }
            masonry.value.destroy();
        }

        await nextTick();

        // Trigger initial load
        if (masonry.value && loadAtPage.value !== null) {
            // Masonry will auto-load when loadAtPage is set
        }
    } catch (error) {
        console.error('Failed to apply service:', error);
    } finally {
        isApplyingService.value = false;
        selectedService.value = ''; // Clear selection after applying
    }
}

// Tab switching function - needs to stay here as it interacts with masonry
async function switchTab(tabId: number): Promise<void> {
    const tab = tabs.value.find(t => t.id === tabId);
    if (!tab) {
        return;
    }

    // Destroy and re-initialize masonry instance for clean state
    if (masonry.value) {
        if (masonry.value.isLoading) {
            masonry.value.cancelLoad();
        }
        // Destroy the instance to reset all internal state
        masonry.value.destroy();
    }

    activeTabId.value = tabId;
    const tabHasRestorableItems = (tab.fileIds?.length ?? 0) > 0 || (tab.itemsData?.length ?? 0) > 0;
    isTabRestored.value = tabHasRestorableItems;

    // Restore selected service for UI
    const serviceFromQuery = tab.queryParams?.service as string | null;
    selectedService.value = serviceFromQuery || '';

    // Restore both page and next from queryParams (service handles format conversion)
    // IMPORTANT: Always restore from saved queryParams - don't default to 1 if the tab has been scrolled
    const pageFromQuery = tab.queryParams?.page;
    const nextFromQuery = tab.queryParams?.next;
    pendingRestoreNextCursor.value = tabHasRestorableItems ? (nextFromQuery ?? null) : null;

    // Check if tab has files - if so, load items lazily FIRST
    // This ensures we have the full tab state before setting pagination
    if (tab.fileIds && tab.fileIds.length > 0 && tab.itemsData.length === 0) {
        // Load items for this tab
        try {
            const loadedItems = await loadTabItems(tabId);
            tab.itemsData = loadedItems;
        } catch (error) {
            console.error('Failed to load tab items:', error);
            // Continue with empty items
        }
    }

    // Restore currentPage from saved queryParams AFTER loading items
    // Only default to 1 if page is truly missing (new tab that hasn't loaded anything yet)
    if (pageFromQuery !== undefined && pageFromQuery !== null) {
        currentPage.value = pageFromQuery;
    } else {
        // No page in queryParams - this is a new tab, start at page 1
        currentPage.value = 1;
    }

    // Restore nextCursor from saved queryParams
    if (nextFromQuery !== undefined && nextFromQuery !== null) {
        nextCursor.value = nextFromQuery;
    } else {
        nextCursor.value = null;
    }

    // Set loadAtPage and prepare for masonry initialization
    // IMPORTANT: Only auto-load if tab has a service selected
    // Check if service exists and is a non-empty string
    const serviceValue = tab.queryParams?.service;
    const hasService = typeof serviceValue === 'string' && serviceValue.length > 0;

    if (tab.itemsData && tab.itemsData.length > 0) {
        // We have pre-loaded items - set loadAtPage to null to prevent auto-load
        // We'll use masonry.init() to properly set up pagination state
        loadAtPage.value = null;
        // Clear items first - init() will add them back
        items.value = [];
    } else if (hasService) {
        // Tab has service - check if we have query params to restore, otherwise start from beginning
        // Note: currentPage and nextCursor are already set from query params above, don't reset them
        if (pageFromQuery !== undefined && pageFromQuery !== null) {
            // We have a page from query params, use it for loading
            loadAtPage.value = pageFromQuery;
        } else {
            // No query params, start from beginning
            loadAtPage.value = 1;
            // currentPage is already set to 1 above if no query params, so no need to reset
        }
        items.value = [];
    } else {
        // No service selected - don't auto-load
        loadAtPage.value = null;
        items.value = [];
    }

    // Wait for next tick to ensure masonry component is ready
    await nextTick();

    // If we have pre-loaded items, use masonry.init() to properly initialize
    // This sets up pagination history and prevents auto-loading
    if (tab.itemsData && tab.itemsData.length > 0 && masonry.value) {
        const pageValue = pageFromQuery !== undefined && pageFromQuery !== null ? pageFromQuery : 1;
        const nextValue = nextFromQuery !== undefined && nextFromQuery !== null ? nextFromQuery : null;

        // Ensure currentPage and nextCursor are set before init (they should already be set above, but double-check)
        // This ensures displayPage computed shows the correct value
        if (pageValue !== undefined && pageValue !== null) {
            currentPage.value = pageValue;
        }
        if (nextValue !== undefined && nextValue !== null) {
            nextCursor.value = nextValue;
        }

        // Initialize masonry with pre-loaded items, current page, and next page
        // init() will add items to masonry and set up pagination history correctly
        masonry.value.init(tab.itemsData, pageValue, nextValue);

        // Wait for DOM to update
        await nextTick();

        // After init, the layout will be calculated, but container dimensions might not be final yet
        // The masonry component's ResizeObserver will handle dimension changes automatically
        // However, we need to ensure layout refreshes when container is ready
        // Use requestAnimationFrame (not a timeout) to wait for browser render cycle
        // This ensures container has final dimensions after tab panel animation completes
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                // Refresh layout after container dimensions have settled
                if (masonry.value && items.value.length > 0) {
                    masonry.value.refreshLayout(items.value);
                }
            });
        });
    }

    // Reset the flag - masonry is now properly initialized
    isTabRestored.value = false;
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
} = useBrowseTabs(switchTab);

const layout = {
    gutterX: 12,
    gutterY: 12,
    sizes: { base: 1, sm: 2, md: 3, lg: 4, '2xl': 10 },
};

async function getNextPage(page: number | string): Promise<GetPageResult> {
    // IMPORTANT: Don't load if no service is selected
    if (!hasServiceSelected.value) {
        return {
            items: [],
            nextPage: null,
        };
    }

    // If we're restoring a tab and already have items, and masonry is trying to load page 1,
    // If we're restoring a tab and already have items, prevent any loading
    // Masonry should only load when user scrolls to bottom, not during restoration
    if (isTabRestored.value) {
        // Return empty result to prevent loading during tab restoration
        return {
            items: [],
            nextPage: nextCursor.value,
        };
    }

    // Determine actual cursor/page to request. When restoring, Masonry may request page 1.
    let pageToRequest: string | number = page;
    if (pendingRestoreNextCursor.value !== null) {
        pageToRequest = pendingRestoreNextCursor.value;
        pendingRestoreNextCursor.value = null;
    }

    // Always pass as 'page' parameter - service will handle conversion
    const url = new URL('/api/browse', window.location.origin);
    url.searchParams.set('page', String(pageToRequest));

    // Include service parameter if available
    const currentService = currentTabService.value;
    if (currentService) {
        url.searchParams.set('source', currentService);
    }

    const response = await window.axios.get(url.toString());
    const data = response.data;

    // Update currentPage to the page we just loaded
    // Only skip if we're restoring a tab and already have items (to prevent reset during restoration)
    if (!isTabRestored.value || items.value.length === 0) {
        // Update current page to the page/cursor we just used
        currentPage.value = pageToRequest;
    }

    // Update next cursor from API response (for local state, used for loading more)
    nextCursor.value = data.nextPage; // This is the cursor/page string from CivitAI

    // Update active tab with new items - this is the single source of truth for tab updates
    // Only update if we're not restoring a tab (to prevent overwriting restored state)
    if (activeTabId.value && !isTabRestored.value) {
        const activeTab = getActiveTab();
        if (activeTab) {
            // Append new items to existing items (masonry will update items.value separately)
            const updatedItemsData = [...activeTab.itemsData, ...data.items];
            // Extract database file IDs from all items in the tab
            const updatedFileIds = updatedItemsData.map(item => item.id);
            // Store both page and next in queryParams (service handles format conversion)
            const updatedQueryParams = {
                ...activeTab.queryParams,
                page: pageToRequest,
                next: data.nextPage,
            };
            updateActiveTab(updatedItemsData, updatedFileIds, updatedQueryParams);
        }
    }

    return {
        items: data.items,
        nextPage: data.nextPage, // Pass cursor to Masonry for next request
    };
}


// Tab management function
// Flow: Load tabs (without files) > Determine focus tab > If has files, load them > Restore query params
async function loadTabs(): Promise<void> {
    try {
        // Step 1: Load all tabs without files (items_data is not included)
        await loadTabsFromComposable();

        // Step 2: Determine which tab to focus (default to first tab if tabs exist)
        if (tabs.value.length > 0 && activeTabId.value === null) {
            const firstTab = tabs.value[0];
            activeTabId.value = firstTab.id;

            // Step 3: If tab has files, load items lazily
            // Step 4: Restore query params (handled in switchTab)
            await switchTab(firstTab.id);
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
    const servicesPromise = fetchServices();

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
                        :is-active="tab.id === activeTabId" :is-minimized="isMinimized" @click="switchTab(tab.id)"
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
            <div ref="tabContentContainer" class="flex-1 min-h-0 transition-all duration-300 flex flex-col relative">
                <!-- Service Selection Header -->
                <div v-if="activeTabId !== null"
                    class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
                    data-test="service-selection-header">
                    <div class="flex items-center gap-3">
                        <div class="flex-1">
                            <Select v-model="selectedService" :disabled="isApplyingService">
                                <SelectTrigger class="w-[200px]" data-test="service-select-trigger">
                                    <SelectValue
                                        :placeholder="hasServiceSelected ? (availableServices.find(s => s.key === currentTabService)?.label || currentTabService || undefined) : 'Select a service...'" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="service in availableServices" :key="service.key"
                                        :value="service.key" data-test="service-select-item">
                                        {{ service.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button @click="applyService"
                            :disabled="!selectedService || isApplyingService || selectedService === currentTabService"
                            size="sm" data-test="apply-service-button">
                            <Loader2 v-if="isApplyingService" :size="14" class="mr-2 animate-spin" />
                            Apply
                        </Button>
                    </div>
                </div>

                <!-- Masonry Content -->
                <div class="flex-1 min-h-0">
                    <div v-if="activeTabId !== null && hasServiceSelected" class="relative h-full"
                        ref="masonryContainer" @click="onMasonryClick">
                        <Masonry :key="activeTabId" ref="masonry" v-model:items="items" :get-next-page="getNextPage"
                            :load-at-page="loadAtPage" :layout="layout" layout-mode="auto" :mobile-breakpoint="768"
                            :skip-initial-load="items.length > 0" :backfill-enabled="true" :backfill-delay-ms="2000"
                            :backfill-max-calls="Infinity" @backfill:start="onBackfillStart"
                            @backfill:tick="onBackfillTick" @backfill:stop="onBackfillStop"
                            @backfill:retry-start="onBackfillRetryStart" @backfill:retry-tick="onBackfillRetryTick"
                            @backfill:retry-stop="onBackfillRetryStop" data-test="masonry-component" />
                    </div>
                    <div v-else-if="activeTabId !== null && !hasServiceSelected"
                        class="flex items-center justify-center h-full" data-test="no-service-message">
                        <p class="text-twilight-indigo-300 text-lg">Select a service to start browsing</p>
                    </div>
                    <div v-else class="flex items-center justify-center h-full" data-test="no-tabs-message">
                        <p class="text-twilight-indigo-300 text-lg">Create a tab to start browsing</p>
                    </div>
                </div>

                <!-- Modal backdrop/mask -->
                <div v-if="overlayRect && overlayImage"
                    class="absolute inset-0 bg-black z-40 transition-opacity duration-500 ease-in-out" :style="{
                        opacity: backdropOpacity,
                    }" />

                <!-- Click overlay -->
                <div v-if="overlayRect && overlayImage" :class="[
                    'absolute border-2 border-red-500 z-50',
                    overlayIsFilled ? 'flex items-center justify-center' : 'overflow-hidden pointer-events-none',
                    overlayIsAnimating ? 'transition-all duration-500 ease-in-out' : ''
                ]" :style="{
                    top: overlayRect.top + 'px',
                    left: overlayRect.left + 'px',
                    width: overlayRect.width + 'px',
                    height: overlayRect.height + 'px',
                    borderRadius: overlayBorderRadius || undefined,
                }">
                    <img :key="overlayKey" :src="overlayImage.src" :srcset="overlayImage.srcset"
                        :sizes="overlayImage.sizes" :alt="overlayImage.alt" :class="[
                            'select-none pointer-events-none',
                            overlayIsFilled ? '' : 'object-cover'
                        ]" :style="overlayImageSize ? {
                            width: overlayImageSize.width + 'px',
                            height: overlayImageSize.height + 'px',
                        } : undefined" draggable="false" />

                    <!-- Close button -->
                    <button v-if="overlayIsFilled" @click="closeOverlay"
                        class="absolute top-4 right-4 z-50 p-2 rounded-full bg-black/50 hover:bg-black/70 text-white transition-colors pointer-events-auto"
                        aria-label="Close overlay" data-test="close-overlay-button">
                        <X :size="20" />
                    </button>
                </div>

                <!-- Status/Pagination Info at Bottom -->
                <div v-if="activeTabId !== null && hasServiceSelected"
                    class="my-2 flex flex-wrap items-center justify-center gap-3" data-test="pagination-info">
                    <!-- Count Pill -->
                    <Pill label="Items" :value="items.length" variant="primary" reversed data-test="items-pill" />
                    <!-- Current Page Pill -->
                    <Pill label="Page" :value="displayPage" variant="neutral" reversed data-test="page-pill" />
                    <!-- Next Page Pill -->
                    <Pill label="Next" :value="nextCursor || 'N/A'" variant="secondary" reversed
                        data-test="next-pill" />
                    <!-- Status Pill -->
                    <Pill :label="'Status'" :value="masonry?.isLoading ? 'Loading...' : 'Ready'"
                        :variant="masonry?.isLoading ? 'danger' : 'success'" reversed data-test="status-pill">
                        <template #value>
                            <span v-if="masonry?.isLoading" class="flex items-center gap-2">
                                <Loader2 :size="14" class="animate-spin" />
                                <span>Loading...</span>
                            </span>
                            <span v-else>Ready</span>
                        </template>
                    </Pill>
                    <!-- Backfill Progress Pills -->
                    <span v-if="backfill.active"
                        class="inline-flex items-stretch rounded overflow-hidden border border-warning-500"
                        data-test="backfill-active-pill">
                        <span
                            class="px-3 py-1 text-xs font-medium transition-colors bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500 flex items-center gap-2">
                            <Loader2 :size="14" class="animate-spin" />
                            <span>{{ backfill.waiting ? 'Waiting' : 'Filling' }}</span>
                        </span>
                        <span
                            class="px-3 py-1 text-xs font-semibold transition-colors bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100 flex items-center gap-3">
                            <span v-if="!backfill.waiting">
                                {{ backfill.fetched }} / {{ backfill.target }} ({{ backfill.calls }} calls)
                            </span>
                            <template v-else>
                                <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                                    <div class="h-full bg-warning-500 transition-[width] duration-100" :style="{
                                        width: Math.max(0, 100 - Math.round((backfill.waitRemainingMs / Math.max(1, backfill.waitTotalMs)) * 100)) + '%',
                                    }" />
                                </div>
                                <span class="text-xs text-warning-100">next in {{ (backfill.waitRemainingMs /
                                    1000).toFixed(1) }}s</span>
                            </template>
                        </span>
                    </span>
                    <span v-if="backfill.retryActive"
                        class="inline-flex items-stretch rounded overflow-hidden border border-warning-500"
                        data-test="backfill-retry-pill">
                        <span
                            class="px-3 py-1 text-xs font-medium transition-colors bg-warning-600 hover:bg-warning-500 text-black border-r border-warning-500 flex items-center gap-2">
                            <Loader2 :size="14" class="animate-spin" />
                            <span>Retry</span>
                        </span>
                        <span
                            class="px-3 py-1 text-xs font-semibold transition-colors bg-prussian-blue-700 hover:bg-prussian-blue-600 text-warning-100 flex items-center gap-3">
                            <div class="h-2 w-20 overflow-hidden rounded bg-muted">
                                <div class="h-full bg-warning-500 transition-[width] duration-100" :style="{
                                    width:
                                        Math.max(
                                            0,
                                            100 - Math.round((backfill.retryWaitRemainingMs / Math.max(1, backfill.retryWaitTotalMs)) * 100),
                                        ) + '%',
                                }" />
                            </div>
                            <span class="text-xs text-warning-100">
                                retry {{ backfill.retryAttempt }} / {{ backfill.retryMax }} in {{
                                    (backfill.retryWaitRemainingMs / 1000).toFixed(1) }}s
                            </span>
                        </span>
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
