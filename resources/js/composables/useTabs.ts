import { ref } from 'vue';
import { useToast } from '@/components/ui/toast/use-toast';
import StatusToast from '@/components/toasts/StatusToast.vue';
import {
    destroyBatch as tabsDestroyBatch,
    index as tabsIndex,
    reorder as tabsReorder,
    setActive as tabsSetActive,
    store as tabsStore,
    update as tabsUpdate,
} from '@/actions/App/Http/Controllers/TabController';

const NO_CACHE_REQUEST_CONFIG = {
    headers: {
        'Cache-Control': 'no-cache',
        Pragma: 'no-cache',
    },
};

export type FeedItem = {
    id: number; // Database file ID
    width: number;
    height: number;
    page: number;
    key: string; // Combined key from backend: "page-id"
    index: number;
    src: string; // Preview/thumbnail URL for masonry grid
    preview?: string; // Vibe loader preview URL
    original?: string; // Vibe loader original URL
    timeoutSeconds?: number; // Vibe loader timeout override (seconds)
    originalUrl?: string; // Original full-size URL
    thumbnail?: string; // Thumbnail URL (may be same as src)
    url?: string | null;
    type?: 'image' | 'video';
    media_kind?: 'image' | 'video' | 'audio' | 'file';
    notFound?: boolean;
    reaction?: { type: string } | null;
    previewed_count?: number;
    seen_count?: number;
    will_auto_dislike?: boolean;
    auto_disliked?: boolean;
    auto_dislike_rule?: { id: number; name: string } | null;
    blacklisted_at?: string | null;
    blacklist_reason?: string | null;
    blacklist_type?: 'manual' | 'auto' | null;
    blacklist_rule?: { id: number; name: string } | null;
    downloaded?: boolean;
    [key: string]: unknown;
};

export type TabData = {
    id: number;
    label: string;
    customLabel?: string | null;
    params: Record<string, string | number | boolean | null | Array<unknown>>;
    position: number;
    isActive: boolean;
    feed?: 'online' | 'local';
    updatedAt: string | null;
};

export type OnTabSwitchCallback = (tabId: number) => Promise<void> | void;

type CreateTabOptions = {
    label?: string;
    customLabel?: string | null;
    params?: Record<string, string | number | boolean | null | Array<unknown>>;
    activate?: boolean;
};

type CloseTabsOptions = {
    preferredTabId?: number | null;
};

type ApiTabData = {
    id: number;
    label: string;
    custom_label?: string | null;
    params?: Record<string, string | number | boolean | null | Array<unknown>>;
    position?: number;
    is_active?: boolean;
    updated_at?: string | null;
};

type TabsSnapshot = {
    tabs: TabData[];
    activeTabId: number | null;
    recentTabIds: number[];
};

export function useTabs(onTabSwitch?: OnTabSwitchCallback) {
    const TAB_SAVE_DEBOUNCE_MS = 500;
    const toast = useToast();
    const tabs = ref<TabData[]>([]);
    const activeTabId = ref<number | null>(null);
    const isLoadingTabs = ref(false);
    const recentTabIds = ref<number[]>([]);
    const saveTabDebounceTimers = new Map<number, number>();

    function cloneParams(params: TabData['params']): TabData['params'] {
        return Object.fromEntries(
            Object.entries(params).map(([key, value]) => [key, Array.isArray(value) ? [...value] : value]),
        ) as TabData['params'];
    }

    function cloneTab(tab: TabData): TabData {
        return {
            ...tab,
            params: cloneParams(tab.params),
        };
    }

    function getUpdatedAtTimestamp(updatedAt: string | null | undefined): number {
        if (!updatedAt) {
            return 0;
        }

        const timestamp = Date.parse(updatedAt);

        return Number.isNaN(timestamp) ? 0 : timestamp;
    }

    function sortTabsByPosition(): void {
        tabs.value.sort((left, right) => left.position - right.position);
    }

    function syncActiveState(tabId: number | null): void {
        activeTabId.value = tabId;
        tabs.value.forEach(tab => {
            tab.isActive = tabId !== null && tab.id === tabId;
        });
    }

    function pruneRecentTabIds(): void {
        const currentIds = new Set(tabs.value.map(tab => tab.id));
        recentTabIds.value = recentTabIds.value.filter(id => currentIds.has(id));
    }

    function recordTabFocus(tabId: number): void {
        if (!tabs.value.some(tab => tab.id === tabId)) {
            return;
        }

        recentTabIds.value = recentTabIds.value.filter(id => id !== tabId);
        recentTabIds.value.push(tabId);
    }

    function seedRecentTabIds(): void {
        recentTabIds.value = [...tabs.value]
            .sort((left, right) => {
                const timestampDiff = getUpdatedAtTimestamp(left.updatedAt) - getUpdatedAtTimestamp(right.updatedAt);
                if (timestampDiff !== 0) {
                    return timestampDiff;
                }

                return left.position - right.position;
            })
            .map(tab => tab.id);

        if (activeTabId.value !== null) {
            recordTabFocus(activeTabId.value);
        }
    }

    function showTabsError(title: string, description: string): void {
        const toastId = `tabs-${Date.now()}`;

        toast(
            {
                component: StatusToast,
                props: {
                    toastId,
                    variant: 'error',
                    title,
                    description,
                },
            },
            {
                id: toastId,
                closeButton: false,
                closeOnClick: false,
            },
        );
    }

    function createSnapshot(): TabsSnapshot {
        return {
            tabs: tabs.value.map(cloneTab),
            activeTabId: activeTabId.value,
            recentTabIds: [...recentTabIds.value],
        };
    }

    function restoreSnapshot(snapshot: TabsSnapshot): void {
        tabs.value = snapshot.tabs.map(cloneTab);
        activeTabId.value = snapshot.activeTabId;
        recentTabIds.value = [...snapshot.recentTabIds];
    }

    function mapTabData(tab: ApiTabData): TabData {
        const params = tab.params || {};

        return {
            id: tab.id,
            label: tab.label,
            customLabel: tab.custom_label ?? null,
            params,
            position: tab.position ?? 0,
            isActive: tab.is_active ?? false,
            feed: (params.feed === 'local' ? 'local' : 'online') as 'online' | 'local',
            updatedAt: tab.updated_at ?? null,
        };
    }

    function hasTabPayload(tabData: ApiTabData | null | undefined): tabData is ApiTabData {
        return typeof tabData?.id === 'number' && typeof tabData.label === 'string';
    }

    function updateTabFromApi(tabId: number, tabData: ApiTabData | null | undefined): void {
        if (!hasTabPayload(tabData)) {
            return;
        }

        const tabIndex = tabs.value.findIndex(tab => tab.id === tabId);
        if (tabIndex === -1) {
            return;
        }

        const mappedTab = mapTabData(tabData);
        const currentTab = tabs.value[tabIndex];
        tabs.value[tabIndex] = {
            ...currentTab,
            ...mappedTab,
            params: cloneParams(mappedTab.params),
        };
        sortTabsByPosition();
    }

    function resolveAdjacentTabId(closingIds: Set<number>, survivingTabs: TabData[]): number | null {
        if (activeTabId.value !== null) {
            const currentActiveIndex = tabs.value.findIndex(tab => tab.id === activeTabId.value);

            if (currentActiveIndex !== -1) {
                for (let index = currentActiveIndex + 1; index < tabs.value.length; index += 1) {
                    const candidate = tabs.value[index];
                    if (!closingIds.has(candidate.id)) {
                        return candidate.id;
                    }
                }

                for (let index = currentActiveIndex - 1; index >= 0; index -= 1) {
                    const candidate = tabs.value[index];
                    if (!closingIds.has(candidate.id)) {
                        return candidate.id;
                    }
                }
            }
        }

        return survivingTabs[0]?.id ?? null;
    }

    function resolveNextActiveTab(closingIds: number[], preferredTabId: number | null = null): number | null {
        const closingIdSet = new Set(closingIds);
        const survivingTabs = tabs.value.filter(tab => !closingIdSet.has(tab.id));

        if (survivingTabs.length === 0) {
            return null;
        }

        if (activeTabId.value !== null && !closingIdSet.has(activeTabId.value)) {
            return activeTabId.value;
        }

        if (preferredTabId !== null && survivingTabs.some(tab => tab.id === preferredTabId)) {
            return preferredTabId;
        }

        for (let index = recentTabIds.value.length - 1; index >= 0; index -= 1) {
            const recentTabId = recentTabIds.value[index];
            if (!closingIdSet.has(recentTabId) && survivingTabs.some(tab => tab.id === recentTabId)) {
                return recentTabId;
            }
        }

        return resolveAdjacentTabId(closingIdSet, survivingTabs);
    }

    function applyOrderedIds(orderedIds: number[]): void {
        const tabMap = new Map(tabs.value.map(tab => [tab.id, cloneTab(tab)]));
        tabs.value = orderedIds.map((tabId, index) => {
            const tab = tabMap.get(tabId);
            if (!tab) {
                throw new Error(`Missing tab ${tabId} during reorder.`);
            }

            return {
                ...tab,
                position: index,
            };
        });
    }

    async function loadTabs(): Promise<void> {
        isLoadingTabs.value = true;
        try {
            const { data } = await window.axios.get(tabsIndex.url(), NO_CACHE_REQUEST_CONFIG);
            tabs.value = data.map((tab: ApiTabData) => mapTabData(tab));
            sortTabsByPosition();
            syncActiveState(tabs.value.find(tab => tab.isActive)?.id ?? null);
            seedRecentTabIds();
        } catch (error) {
            console.error('Failed to load tabs:', error);
            throw error;
        } finally {
            isLoadingTabs.value = false;
        }
    }

    async function createTab(options: CreateTabOptions = {}): Promise<TabData> {
        const { label, customLabel, params, activate = true } = options;
        const maxPosition = tabs.value.length > 0
            ? Math.max(...tabs.value.map(tab => tab.position))
            : -1;

        const normalizedParams = params
            ? Object.fromEntries(
                Object.entries(params).filter(([, value]) => value !== undefined),
            ) as Record<string, string | number | boolean | null | Array<unknown>>
            : undefined;

        const newTab: TabData = {
            id: 0,
            label: label ?? `Browse ${tabs.value.length + 1}`,
            customLabel: customLabel ?? null,
            params: normalizedParams ?? {},
            position: maxPosition + 1,
            isActive: false,
            feed: normalizedParams?.feed === 'local' ? 'local' : 'online',
            updatedAt: null,
        };

        try {
            const { data } = await window.axios.post(tabsStore.url(), {
                label: newTab.label,
                custom_label: newTab.customLabel,
                params: newTab.params,
                position: newTab.position,
            });

            const createdTab = mapTabData(data);
            tabs.value.push(createdTab);
            sortTabsByPosition();

            if (activate) {
                const previousActiveTabId = activeTabId.value;

                try {
                    if (onTabSwitch) {
                        await onTabSwitch(createdTab.id);
                    } else {
                        syncActiveState(createdTab.id);
                        await setActiveTab(createdTab.id);
                    }
                } catch (error) {
                    syncActiveState(previousActiveTabId);
                    throw error;
                }
            }

            return tabs.value.find(tab => tab.id === createdTab.id) ?? createdTab;
        } catch (error) {
            console.error('Failed to create tab:', error);
            throw error;
        }
    }

    async function duplicateTab(tabId: number): Promise<TabData | undefined> {
        const sourceTab = tabs.value.find(tab => tab.id === tabId);
        if (!sourceTab) {
            return undefined;
        }

        const createdTab = await createTab({
            label: sourceTab.label,
            customLabel: sourceTab.customLabel ?? null,
            params: cloneParams(sourceTab.params),
            activate: sourceTab.id === activeTabId.value,
        });

        const orderedIds = tabs.value.map(tab => tab.id);
        const sourceIndex = orderedIds.indexOf(tabId);
        const createdIndex = orderedIds.indexOf(createdTab.id);

        if (sourceIndex === -1 || createdIndex === -1 || createdIndex === sourceIndex + 1) {
            return tabs.value.find(tab => tab.id === createdTab.id) ?? createdTab;
        }

        const nextOrderedIds = orderedIds.filter(id => id !== createdTab.id);
        nextOrderedIds.splice(sourceIndex + 1, 0, createdTab.id);
        await reorderTabs(nextOrderedIds);

        return tabs.value.find(tab => tab.id === createdTab.id) ?? createdTab;
    }

    async function closeTab(tabId: number): Promise<void> {
        await closeTabs([tabId]);
    }

    async function closeTabs(tabIds: number[], options: CloseTabsOptions = {}): Promise<number[]> {
        const ids = [...new Set(tabIds)].filter(tabId => tabs.value.some(tab => tab.id === tabId));
        if (ids.length === 0) {
            return [];
        }

        const snapshot = createSnapshot();
        const nextActiveTabId = resolveNextActiveTab(ids, options.preferredTabId ?? null);
        const requestNextActiveTabId = activeTabId.value !== null && !ids.includes(activeTabId.value)
            ? null
            : nextActiveTabId;

        tabs.value = tabs.value
            .filter(tab => !ids.includes(tab.id))
            .map(cloneTab);
        pruneRecentTabIds();
        syncActiveState(nextActiveTabId);

        try {
            const { data } = await window.axios.post(tabsDestroyBatch.url(), {
                ids,
                next_active_id: requestNextActiveTabId,
            });

            syncActiveState(typeof data.active_tab_id === 'number' ? data.active_tab_id : null);

            if (tabs.value.length === 0) {
                await createTab();
            }

            return ids;
        } catch (error) {
            restoreSnapshot(snapshot);
            showTabsError('Failed to close tabs.', 'Your tab layout was restored.');
            console.error('Failed to close tabs:', error);
            throw error;
        }
    }

    function getActiveTab(): TabData | undefined {
        if (!activeTabId.value) {
            return undefined;
        }

        return tabs.value.find(tab => tab.id === activeTabId.value);
    }

    function updateTabLabel(tabId: number, label: string): void {
        const tab = tabs.value.find(currentTab => currentTab.id === tabId);
        if (!tab || tab.label === label) {
            return;
        }

        tab.label = label;
        saveTabDebounced(tab.id);
    }

    function updateTabCustomLabel(tabId: number, customLabel: string | null): void {
        const tab = tabs.value.find(currentTab => currentTab.id === tabId);
        if (!tab || (tab.customLabel ?? null) === customLabel) {
            return;
        }

        tab.customLabel = customLabel;
        saveTabDebounced(tab.id);
    }

    async function reorderTabs(orderedIds: number[]): Promise<void> {
        const currentIds = tabs.value.map(tab => tab.id);
        if (orderedIds.length !== currentIds.length) {
            return;
        }

        if (orderedIds.every((tabId, index) => tabId === currentIds[index])) {
            return;
        }

        const snapshot = createSnapshot();

        try {
            applyOrderedIds(orderedIds);
            await window.axios.post(tabsReorder.url(), {
                ordered_ids: orderedIds,
            });
        } catch (error) {
            restoreSnapshot(snapshot);
            showTabsError('Failed to reorder tabs.', 'Your tab order was restored.');
            console.error('Failed to reorder tabs:', error);
            throw error;
        }
    }

    function saveTabDebounced(tabId: number): void {
        const existingTimer = saveTabDebounceTimers.get(tabId);
        if (existingTimer !== undefined) {
            clearTimeout(existingTimer);
        }

        const timer = window.setTimeout(() => {
            saveTabDebounceTimers.delete(tabId);

            const currentTab = tabs.value.find((tab) => tab.id === tabId);
            if (!currentTab) {
                return;
            }

            void saveTab(currentTab);
        }, TAB_SAVE_DEBOUNCE_MS);

        saveTabDebounceTimers.set(tabId, timer);
    }

    async function saveTab(tab: TabData): Promise<void> {
        try {
            const response = await window.axios.put<ApiTabData | undefined>(tabsUpdate.url(tab.id), {
                label: tab.label,
                custom_label: tab.customLabel ?? null,
                position: tab.position,
            });
            updateTabFromApi(tab.id, response?.data);
        } catch (error) {
            console.error('Failed to save tab:', error);
            throw error;
        }
    }

    async function setActiveTab(tabId: number): Promise<void> {
        try {
            const response = await window.axios.patch<ApiTabData | undefined>(tabsSetActive.url(tabId));
            updateTabFromApi(tabId, response?.data);
            syncActiveState(tabId);
            recordTabFocus(tabId);
        } catch (error) {
            console.error('Failed to set active tab:', error);
            throw error;
        }
    }

    return {
        tabs,
        activeTabId,
        isLoadingTabs,
        loadTabs,
        createTab,
        duplicateTab,
        closeTab,
        closeTabs,
        getActiveTab,
        updateTabLabel,
        updateTabCustomLabel,
        reorderTabs,
        setActiveTab,
    };
}
