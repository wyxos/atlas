<script setup lang="ts">
import { computed, toRef } from 'vue';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { SlidersHorizontal } from 'lucide-vue-next';
import Input from '@/components/ui/input/Input.vue';
import SearchableDropdown from '@/components/ui/SearchableDropdown.vue';
import LocalSourceDropdown from '@/components/tab-filter/LocalSourceDropdown.vue';
import type { ServiceOption } from '@/lib/browseCatalog';
import type { BrowseFeedHandle } from '@/types/browse';
import type { LocalSourceSelection } from '@/utils/localSourceSelection';
import TabFilterFieldControl from '@/components/tab-filter/TabFilterFieldControl.vue';
import TabFilterLimitField from '@/components/tab-filter/TabFilterLimitField.vue';
import { useTabFilterState } from '@/composables/useTabFilterState';
import { localPresetDropdownGroups, serviceDropdownOptions, serviceStatusMessage } from '@/utils/browseDropdownOptions';
import { getTabFilterLimitOptions, shouldShowTabFilterDescriptionBelow, type TabFilterFieldUpdate } from '@/utils/tabFilter';

interface Props {
    open: boolean;
    availableServices: ServiceOption[];
    localDef?: ServiceOption | null;
    masonry?: BrowseFeedHandle | null;
}

const props = withDefaults(defineProps<Props>(), {
    open: false,
    localDef: null,
    masonry: null,
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    'apply': [];
    'reset': [];
}>();

const inputClass = 'text-twilight-indigo-100 placeholder:text-twilight-indigo-300';

const filter = useTabFilterState({
    availableServices: toRef(props, 'availableServices'),
    localDef: toRef(props, 'localDef'),
    onApply: () => emit('apply'),
    onReset: () => emit('reset'),
    onOpenChange: (value) => emit('update:open', value),
});

const form = filter.form;
const isOnlineFeed = filter.derived.isOnlineFeed;
const isLocalFeed = filter.derived.isLocalFeed;
const activeSchema = filter.derived.activeSchema;
const selectedServiceDef = filter.derived.selectedServiceDef;
const visibleServiceFields = filter.derived.visibleServiceFields;
const localSourceField = filter.derived.localSourceField;
const localPresetGroups = filter.derived.localPresetGroups;
const selectedLocalPreset = filter.derived.selectedLocalPreset;
const localPageInput = filter.models.localPageInput;
const limitOptions = computed(() => getTabFilterLimitOptions(form.data.feed, activeSchema.value));
const onlineServices = computed(() => props.availableServices.filter((service) => service.key !== 'local'));
const serviceOptions = computed(() => serviceDropdownOptions(onlineServices.value));
const presetGroups = computed(() => localPresetDropdownGroups(localPresetGroups.value));
const selectedServiceStatusMessage = computed(() => serviceStatusMessage(selectedServiceDef.value));

function updateLimit(value: string): void {
    form.data.limit = value;
}

function updateSource(value: LocalSourceSelection): void {
    form.data.source = value;
}

function updateLocalPage(value: string | number): void {
    const page = typeof value === 'number' ? value : Number(value);
    localPageInput.value = page;
}

function handleFieldUpdate(field: TabFilterFieldUpdate): void {
    filter.actions.updateServiceFilterValue(field.uiKey, field.value);
}
</script>

<template>
    <Sheet :open="props.open" @update:open="emit('update:open', $event)">
        <SheetTrigger as-child>
            <Button size="sm" variant="ghost" class="h-10 w-10" data-test="filter-button" :disabled="masonry?.isLoading">
                <SlidersHorizontal :size="14" />
            </Button>
        </SheetTrigger>

        <SheetContent side="right" class="w-full sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>Advanced Filters</SheetTitle>
            </SheetHeader>

            <div class="flex-1 space-y-6 overflow-y-auto p-6">
                <div v-if="isOnlineFeed" class="form-field">
                    <label class="form-label">Service</label>
                    <SearchableDropdown
                        :model-value="form.data.service"
                        :options="serviceOptions"
                        placeholder="Select a service..."
                        search-placeholder="Search services..."
                        @update:model-value="(value) => filter.actions.updateService(String(value))"
                    />
                    <p v-if="selectedServiceStatusMessage" class="mt-2 inline-flex rounded-full border border-danger-400/40 bg-danger-500/15 px-2 py-0.5 text-[11px] font-medium text-danger-100">
                        {{ selectedServiceStatusMessage }}
                    </p>
                </div>

                <template v-if="isLocalFeed && activeSchema">
                    <div class="form-field">
                        <label class="form-label">Preset</label>
                        <SearchableDropdown
                            :model-value="selectedLocalPreset"
                            :groups="presetGroups"
                            placeholder="Select a preset…"
                            search-placeholder="Search presets..."
                            @update:model-value="(value) => filter.actions.applyLocalPreset(String(value))"
                        />
                        <p class="form-help">
                            Presets set sensible defaults. You can tweak fields below after applying.
                        </p>
                    </div>

                    <TabFilterLimitField :model-value="form.data.limit" :options="limitOptions" @update:model-value="updateLimit" />

                    <div class="form-field">
                        <label class="form-label">Page</label>
                        <Input
                            :model-value="localPageInput"
                            type="number"
                            min="1"
                            step="1"
                            placeholder="1"
                            :class="inputClass"
                            @update:model-value="updateLocalPage"
                        />
                        <p class="form-help">Jump to a specific library page (1-based).</p>
                    </div>

                    <div v-if="localSourceField" class="form-field">
                        <label class="form-label">{{ localSourceField.label }}</label>
                        <LocalSourceDropdown
                            :model-value="form.data.source"
                            :options="localSourceField.options ?? []"
                            :placeholder="localSourceField.placeholder || 'Select...'"
                            @update:model-value="updateSource"
                        />
                        <p v-if="shouldShowTabFilterDescriptionBelow(localSourceField)" class="form-help">
                            {{ localSourceField.description }}
                        </p>
                    </div>

                    <TabFilterFieldControl
                        v-for="field in visibleServiceFields"
                        :key="field.uiKey"
                        :field="field"
                        :model-value="form.data.serviceFilters[field.uiKey]"
                        :disabled="filter.actions.isFieldDisabled(field)"
                        :input-class="inputClass"
                        @update:model-value="(value) => handleFieldUpdate({ uiKey: field.uiKey, value })"
                    />
                </template>

                <template v-if="isOnlineFeed && selectedServiceDef">
                    <TabFilterLimitField :model-value="form.data.limit" :options="limitOptions" @update:model-value="updateLimit" />

                    <TabFilterFieldControl
                        v-for="field in visibleServiceFields"
                        :key="field.uiKey"
                        :field="field"
                        :model-value="form.data.serviceFilters[field.uiKey]"
                        :input-class="inputClass"
                        @update:model-value="(value) => handleFieldUpdate({ uiKey: field.uiKey, value })"
                    />
                </template>
            </div>

            <SheetFooter>
                <Button variant="destructive" @click="filter.actions.handleReset">
                    Reset
                </Button>
                <Button variant="default" @click="filter.actions.handleApply">
                    Apply
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
