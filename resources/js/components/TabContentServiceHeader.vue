<script setup lang="ts">
import { ChevronDown, Play, RotateCcw, X } from 'lucide-vue-next';
import type { MasonryInstance } from '@wyxos/vibe';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import TabFilter from './TabFilter.vue';
import ModerationRulesManager from './moderation/ModerationRulesManager.vue';
import type { BrowseFormInstance } from '@/composables/useBrowseForm';
import type { LoadedItemsAction } from '@/composables/useTabContentLoadedItemsActions';
import type { ServiceOption } from '@/lib/browseCatalog';

interface Props {
    form: BrowseFormInstance;
    availableServices: ServiceOption[];
    availableSources: string[];
    localService: ServiceOption | null;
    masonry: MasonryInstance | null;
    filterSheetOpen: boolean;
    updateFilterSheetOpen: (value: boolean) => void;
    updateFeed: (value: 'online' | 'local') => void;
    updateService: (service: string) => void;
    updateSource: (source: string) => void;
    applyService: () => void | Promise<void>;
    applyFilters: () => void | Promise<void>;
    resetFilters: () => void;
    loadedItemsCount: number;
    activeLoadedItemsAction: LoadedItemsAction | null;
    onRunLoadedItemsAction: (action: LoadedItemsAction) => void | Promise<void>;
    cancelMasonryLoad: () => void;
    loadNextPage: () => void | Promise<void>;
}

const props = withDefaults(defineProps<Props>(), {
    localService: null,
    masonry: null,
    filterSheetOpen: false,
    activeLoadedItemsAction: null,
    loadedItemsCount: 0,
});

function isLoadedItemsActionBusy(action: LoadedItemsAction): boolean {
    return props.activeLoadedItemsAction === action;
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

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        size="sm"
                        variant="ghost"
                        :loading="activeLoadedItemsAction !== null"
                        class="h-10 px-3 gap-2"
                        data-test="loaded-items-menu-trigger"
                        title="Run actions on loaded items in this tab"
                        :disabled="masonry?.isLoading"
                    >
                        <RotateCcw :size="14" />
                        <span>Loaded Items</span>
                        <ChevronDown :size="14" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    class="w-64 border-twilight-indigo-500 bg-prussian-blue-600 text-twilight-indigo-100"
                >
                    <DropdownMenuLabel class="text-smart-blue-100">
                        {{ `Loaded items (${loadedItemsCount})` }}
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-favorite-all"
                        @select="onRunLoadedItemsAction('love')"
                    >
                        {{ isLoadedItemsActionBusy('love') ? 'Favoriting...' : 'Favorite all' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-like-all"
                        @select="onRunLoadedItemsAction('like')"
                    >
                        {{ isLoadedItemsActionBusy('like') ? 'Liking...' : 'Like all' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-funny-all"
                        @select="onRunLoadedItemsAction('funny')"
                    >
                        {{ isLoadedItemsActionBusy('funny') ? 'Updating...' : 'Funny all' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-dislike-all"
                        @select="onRunLoadedItemsAction('dislike')"
                    >
                        {{ isLoadedItemsActionBusy('dislike') ? 'Disliking...' : 'Dislike all' }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-increment-preview-4"
                        @select="onRunLoadedItemsAction('increment-preview-4')"
                    >
                        {{ isLoadedItemsActionBusy('increment-preview-4') ? 'Incrementing...' : 'Increment previewed 4' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer focus:bg-smart-blue-700/50 focus:text-white"
                        data-test="loaded-items-reset-previewed"
                        @select="onRunLoadedItemsAction('reset-previewed')"
                    >
                        {{ isLoadedItemsActionBusy('reset-previewed') ? 'Resetting...' : 'Reset previewed' }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator class="bg-twilight-indigo-500" />
                    <DropdownMenuItem
                        :disabled="activeLoadedItemsAction !== null || loadedItemsCount === 0"
                        class="cursor-pointer text-danger-200 focus:bg-danger-600/20 focus:text-danger-100"
                        data-test="loaded-items-blacklist-all"
                        @select="onRunLoadedItemsAction('blacklist')"
                    >
                        {{ isLoadedItemsActionBusy('blacklist') ? 'Blacklisting...' : 'Blacklist all' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button @click="cancelMasonryLoad" size="sm" variant="ghost" class="h-10 w-10" color="danger"
                data-test="cancel-loading-button" title="Cancel loading" :disabled="!masonry?.isLoading">
                <X :size="14" />
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
    </div>
</template>
