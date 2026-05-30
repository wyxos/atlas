<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown, ChevronsUp, ListChecks, Loader2, Play, Unlink, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { queueManager } from '@/composables/useQueue';
import { useBrowseGlobalStartPanel } from '@/composables/useBrowseGlobalStartPanel';
import SearchableDropdown from '@/components/ui/SearchableDropdown.vue';
import TabFilter from './TabFilter.vue';
import LocalSourceDropdown from '@/components/tab-filter/LocalSourceDropdown.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
import { serviceDropdownOptions } from '@/utils/browseDropdownOptions';
import { createLocalSourceOptions, type LocalSourceSelection } from '@/utils/localSourceSelection';

interface Props {
    form: BrowseFormInstance;
    availableServices: ServiceOption[];
    availableSources: string[];
    localService: ServiceOption | null;
    masonry: BrowseFeedHandle | null;
    filterSheetOpen: boolean;
    updateFilterSheetOpen: (value: boolean) => void;
    updateFeed: (value: 'online' | 'local') => void;
    updateService: (service: string) => void;
    updateSource: (source: LocalSourceSelection) => void;
    applyService: () => void | Promise<void>;
    applyFilters: () => void | Promise<void>;
    resetFilters: () => void;
    cancelMasonryLoad?: (() => void) | null;
    canRemoveLoadedItems?: boolean;
    goToFirstPage: () => void | Promise<void>;
    loadNextPage: () => void | Promise<void>;
    loadedItemCount?: number;
    removeLoadedItems?: (() => void) | null;
    removingLoadedItems?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    localService: null,
    masonry: null,
    filterSheetOpen: false,
    cancelMasonryLoad: null,
    canRemoveLoadedItems: false,
    loadedItemCount: 0,
    removeLoadedItems: null,
    removingLoadedItems: false,
});

const globalStartPanel = useBrowseGlobalStartPanel();
const queuedReactionCount = queueManager.collection.getAllComputed();
const localSourceOptions = computed(() => createLocalSourceOptions(props.availableSources));
const queuedReactionTotal = computed(() => queuedReactionCount.value.length);
const queuedReactionCountLabel = computed(() => queuedReactionTotal.value > 99 ? '99+' : String(queuedReactionTotal.value));
const queueButtonLabel = computed(() => queuedReactionTotal.value > 0
    ? `Open reaction queue (${queuedReactionTotal.value} queued)`
    : 'Open reaction queue');
const removeLoadedItemsLabel = computed(() => {
    if (props.loadedItemCount === 1) {
        return 'Remove 1 loaded item from this tab';
    }

    return props.loadedItemCount > 0
        ? `Remove ${props.loadedItemCount} loaded items from this tab`
        : 'Remove loaded items from this tab';
});
const feedOptions = [
    { label: 'Online', value: 'online' },
    { label: 'Library', value: 'local' },
];
const onlineServiceOptions = computed(() => serviceDropdownOptions(props.availableServices.filter((entry) => entry.key !== 'local')));
</script>

<template>
    <div class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
        data-test="service-selection-header">
        <div class="flex items-center gap-3">
            <div>
                <SearchableDropdown
                    :model-value="form.data.feed"
                    :options="feedOptions"
                    trigger-class="w-[120px]"
                    search-placeholder="Search sources..."
                    data-test="source-type-select-trigger"
                    @update:model-value="(value) => updateFeed(String(value) as 'online' | 'local')"
                    :disabled="masonry?.isLoading"
                />
            </div>

            <div v-if="form.data.feed === 'online'" class="flex-1">
                <SearchableDropdown
                    :model-value="form.data.service"
                    :options="onlineServiceOptions"
                    trigger-class="w-[200px]"
                    placeholder="Select a service..."
                    search-placeholder="Search services..."
                    data-test="service-select-trigger"
                    @update:model-value="(value) => updateService(String(value))"
                    :disabled="masonry?.isLoading"
                />
            </div>

            <div v-if="form.data.feed === 'local'" class="flex-1">
                <LocalSourceDropdown
                    :model-value="form.data.source"
                    :options="localSourceOptions"
                    :disabled="masonry?.isLoading"
                    trigger-class="w-[220px]"
                    @update:model-value="updateSource"
                />
            </div>

            <TabFilter :open="filterSheetOpen" :available-services="availableServices" :local-def="localService"
                :masonry="masonry" @update:open="updateFilterSheetOpen" @reset="resetFilters" @apply="applyFilters" />

            <ModerationRulesManager :disabled="masonry?.isLoading" />
            <slot />

            <Button
                v-if="removeLoadedItems"
                type="button"
                size="icon-lg"
                variant="ghost"
                color="danger"
                class="h-10 w-10 p-0"
                data-test="remove-loaded-items-button"
                :disabled="!canRemoveLoadedItems || Boolean(masonry?.isLoading) || removingLoadedItems"
                :aria-label="removeLoadedItemsLabel"
                :title="removeLoadedItemsLabel"
                @click="removeLoadedItems"
            >
                <Loader2 v-if="removingLoadedItems" :size="16" class="animate-spin" />
                <Unlink v-else :size="16" />
            </Button>

            <Button
                v-if="globalStartPanel"
                type="button"
                size="icon-lg"
                variant="secondary"
                class="relative h-10 w-10 p-0"
                data-test="global-start-panel-button"
                aria-controls="browse-global-start-panel"
                :aria-expanded="String(globalStartPanel.isOpen.value)"
                :aria-label="queueButtonLabel"
                :title="queueButtonLabel"
                @click="globalStartPanel.toggle"
            >
                <ListChecks :size="16" />
                <span
                    v-if="queuedReactionTotal > 0"
                    class="absolute -right-1 -top-1 inline-flex min-w-5 justify-center rounded-full bg-smart-blue-500 px-1.5 text-[11px] font-semibold leading-5 text-white"
                    data-test="global-start-panel-queue-count"
                >
                    {{ queuedReactionCountLabel }}
                </span>
            </Button>

            <Button
                v-if="masonry?.isLoading"
                type="button"
                size="icon-lg"
                variant="ghost"
                color="danger"
                class="h-10 w-10 p-0"
                data-test="cancel-loading-button"
                title="Cancel loading"
                aria-label="Cancel loading"
                :disabled="cancelMasonryLoad === null"
                @click="cancelMasonryLoad?.()"
            >
                <X :size="16" />
            </Button>

            <Button @click="goToFirstPage" size="sm" variant="ghost" class="h-10 w-10"
                data-test="go-first-page-button" title="Go to first page"
                :disabled="masonry?.isLoading">
                <ChevronsUp :size="14" />
            </Button>

            <Button @click="loadNextPage" size="sm" variant="ghost" class="h-10 w-10"
                data-test="load-next-page-button" title="Load next page"
                :disabled="masonry?.isLoading">
                <ChevronDown :size="14" />
            </Button>

            <Button @click="applyService" size="sm" class="h-10 w-10" data-test="apply-service-button"
                :loading="masonry?.isLoading"
                :disabled="masonry?.isLoading || (form.data.feed === 'online' && !form.data.service)"
                title="Apply selected service">
                <Play :size="14" />
            </Button>
        </div>
    </div>
</template>
