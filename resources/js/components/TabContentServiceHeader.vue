<script setup lang="ts">
import { computed } from 'vue';
import { ChevronDown, ChevronsUp, PanelRightOpen, Play } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { queueManager } from '@/composables/useQueue';
import { useBrowseGlobalStartPanel } from '@/composables/useBrowseGlobalStartPanel';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import TabFilter from './TabFilter.vue';
import LocalSourceDropdown from '@/components/tab-filter/LocalSourceDropdown.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
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
    goToFirstPage: () => void | Promise<void>;
    loadNextPage: () => void | Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
    localService: null,
    masonry: null,
    filterSheetOpen: false,
});

const globalStartPanel = useBrowseGlobalStartPanel();
const queuedReactionCount = queueManager.collection.getAllComputed();
const localSourceOptions = computed(() => createLocalSourceOptions(props.availableSources));
const feedLabel = computed(() => props.form.data.feed === 'local' ? 'Library' : 'Online');
</script>

<template>
    <div class="px-4 py-3 border-b border-twilight-indigo-500/50 bg-prussian-blue-700/50"
        data-test="service-selection-header">
        <div class="flex items-center gap-3">
            <div>
                <Select :model-value="form.data.feed"
                    @update:model-value="(value) => updateFeed(value as 'online' | 'local')"
                    :disabled="masonry?.isLoading">
                    <SelectTrigger class="w-[120px]" data-test="source-type-select-trigger">
                        <span class="truncate">{{ feedLabel }}</span>
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="online" data-test="source-type-online">Online</SelectItem>
                        <SelectItem value="local" data-test="source-type-local">Library</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div v-if="form.data.feed === 'online'" class="flex-1">
                <Select :model-value="form.data.service"
                    @update:model-value="(value) => updateService(value as string)"
                    :disabled="masonry?.isLoading">
                    <SelectTrigger class="w-[200px]" data-test="service-select-trigger">
                        <SelectValue placeholder="Select a service..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="service in availableServices.filter((entry) => entry.key !== 'local')"
                            :key="service.key" :value="service.key" data-test="service-select-item">
                            {{ service.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
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
                v-if="globalStartPanel"
                type="button"
                size="sm"
                variant="secondary"
                class="h-10 gap-2 px-3"
                data-test="global-start-panel-button"
                aria-controls="browse-global-start-panel"
                :aria-expanded="String(globalStartPanel.isOpen.value)"
                title="Open reaction queue"
                @click="globalStartPanel.toggle"
            >
                <PanelRightOpen :size="14" />
                <span>Queue</span>
                <span
                    v-if="queuedReactionCount.length > 0"
                    class="ml-1 inline-flex min-w-5 justify-center rounded-full bg-smart-blue-500 px-1.5 text-[11px] font-semibold text-white"
                    data-test="global-start-panel-queue-count"
                >
                    {{ queuedReactionCount.length }}
                </span>
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
