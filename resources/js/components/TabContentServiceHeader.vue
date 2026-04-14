<script setup lang="ts">
import { Ban, ChevronDown, ChevronsUp, Eye, Heart, LockKeyhole, LockKeyholeOpen, Play, ThumbsDown, ThumbsUp, X } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { LoadedItemsBulkAction } from '@/composables/useTabContentItemInteractions';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import TabFilter from './TabFilter.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';

const bulkActionButtons: Array<{
    action: LoadedItemsBulkAction;
    color?: 'default' | 'danger';
    dataTest: string;
    icon: typeof Eye;
    label: string;
    title: string;
    variant: 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link' | 'dashed';
}> = [
    {
        action: 'preview-to-4-and-remove',
        dataTest: 'loaded-items-preview-to-four-button',
        icon: Eye,
        label: 'Preview to 4',
        title: 'Set all loaded items to preview count 4 and remove them from the grid',
        variant: 'outline',
    },
    {
        action: 'like',
        dataTest: 'loaded-items-like-button',
        icon: ThumbsUp,
        label: 'Like all',
        title: 'Run the ALT + left click reaction on all loaded items',
        variant: 'outline',
    },
    {
        action: 'love',
        dataTest: 'loaded-items-love-button',
        icon: Heart,
        label: 'Love all',
        title: 'Run the ALT + middle click reaction on all loaded items',
        variant: 'outline',
    },
    {
        action: 'dislike',
        color: 'danger',
        dataTest: 'loaded-items-dislike-button',
        icon: ThumbsDown,
        label: 'Dislike all',
        title: 'Run the ALT + right click reaction on all loaded items',
        variant: 'outline',
    },
    {
        action: 'blacklist',
        color: 'danger',
        dataTest: 'loaded-items-blacklist-button',
        icon: Ban,
        label: 'Blacklist all',
        title: 'Blacklist every loaded item and remove it from the grid',
        variant: 'destructive',
    },
];

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
    updateSource: (source: string) => void;
    applyService: () => void | Promise<void>;
    applyFilters: () => void | Promise<void>;
    resetFilters: () => void;
    cancelMasonryLoad: () => void;
    goToFirstPage: () => void | Promise<void>;
    loadNextPage: () => void | Promise<void>;
    loadedItemsCount: number;
    performLoadedItemsBulkAction: (action: LoadedItemsBulkAction) => void | Promise<number>;
}

const props = withDefaults(defineProps<Props>(), {
    localService: null,
    masonry: null,
    filterSheetOpen: false,
    loadedItemsCount: 0,
});

function handleLoadedItemsBulkAction(action: LoadedItemsBulkAction): void {
    void props.performLoadedItemsBulkAction(action);
}

function togglePageLoadingLock(): void {
    if (!props.masonry?.lockPageLoading || !props.masonry?.unlockPageLoading) {
        return;
    }

    if (props.masonry.pageLoadingLocked) {
        props.masonry.unlockPageLoading();
        return;
    }

    props.masonry.lockPageLoading();
}
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
                        <SelectValue placeholder="Online" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="online" data-test="source-type-online">Online</SelectItem>
                        <SelectItem value="local" data-test="source-type-local">Local</SelectItem>
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
                <Select :model-value="form.data.source" @update:model-value="(value) => updateSource(value as string)"
                    :disabled="masonry?.isLoading">
                    <SelectTrigger class="w-[200px]" data-test="source-select-trigger">
                        <SelectValue placeholder="Select a source..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="source in availableSources" :key="source" :value="source"
                            data-test="source-select-item">
                            {{ source }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <TabFilter :open="filterSheetOpen" :available-services="availableServices" :local-def="localService"
                :masonry="masonry" @update:open="updateFilterSheetOpen" @reset="resetFilters" @apply="applyFilters" />

            <ModerationRulesManager :disabled="masonry?.isLoading" />
            <slot />

            <Button @click="cancelMasonryLoad" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                data-test="cancel-loading-button" title="Cancel loading" :disabled="!masonry?.isLoading">
                <X :size="14" />
            </Button>

            <Button @click="goToFirstPage" size="sm" variant="ghost" class="h-10 w-10"
                data-test="go-first-page-button" title="Go to first page"
                :disabled="masonry?.isLoading">
                <ChevronsUp :size="14" />
            </Button>

            <Button @click="loadNextPage" size="sm" variant="ghost" class="h-10 w-10"
                data-test="load-next-page-button" title="Load next page"
                :disabled="masonry?.isLoading || masonry?.hasReachedEnd">
                <ChevronDown :size="14" />
            </Button>

            <Button @click="applyService" size="sm" class="h-10 w-10" data-test="apply-service-button"
                :loading="masonry?.isLoading"
                :disabled="masonry?.isLoading || (form.data.feed === 'online' && !form.data.service)"
                title="Apply selected service">
                <Play :size="14" />
            </Button>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-white/10 pt-3">
            <span class="mr-2 text-[0.65rem] font-semibold uppercase tracking-[0.2em] text-twilight-indigo-300/80">
                Loaded {{ loadedItemsCount }}
            </span>

            <Button
                size="sm"
                variant="ghost"
                class="h-8"
                data-test="page-loading-lock-button"
                :aria-label="masonry?.pageLoadingLocked ? 'Unlock page loading' : 'Lock page loading'"
                :aria-pressed="masonry?.pageLoadingLocked ? 'true' : 'false'"
                :disabled="!masonry?.lockPageLoading || !masonry?.unlockPageLoading"
                :title="masonry?.pageLoadingLocked ? 'Unlock Vibe page loading' : 'Lock Vibe page loading'"
                @click="togglePageLoadingLock"
            >
                <component :is="masonry?.pageLoadingLocked ? LockKeyholeOpen : LockKeyhole" :size="14" />
                <span>{{ masonry?.pageLoadingLocked ? 'Unlock paging' : 'Lock paging' }}</span>
            </Button>

            <Button
                v-for="action in bulkActionButtons"
                :key="action.dataTest"
                size="sm"
                class="h-8"
                :color="action.color"
                :data-test="action.dataTest"
                :disabled="masonry?.isLoading || loadedItemsCount === 0"
                :title="action.title"
                :variant="action.variant"
                @click="handleLoadedItemsBulkAction(action.action)"
            >
                <component :is="action.icon" :size="14" />
                <span>{{ action.label }}</span>
            </Button>
        </div>
    </div>
</template>
