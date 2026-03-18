<script setup lang="ts">
import { computed, toRef } from 'vue';
import { Sheet, SheetContent, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SlidersHorizontal } from 'lucide-vue-next';
import { Masonry } from '@wyxos/vibe';
import Input from '@/components/ui/input/Input.vue';
import type { ServiceOption } from '@/lib/browseCatalog';
import TabFilterFieldControl from '@/components/tab-filter/TabFilterFieldControl.vue';
import TabFilterLimitField from '@/components/tab-filter/TabFilterLimitField.vue';
import { useTabFilterState } from '@/composables/useTabFilterState';
import { shouldShowTabFilterDescriptionBelow, type TabFilterFieldUpdate } from '@/utils/tabFilter';

interface Props {
    open: boolean;
    availableServices: ServiceOption[];
    localDef?: ServiceOption | null;
    masonry?: InstanceType<typeof Masonry> | null;
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
const selectedLocalPresetLabel = filter.derived.selectedLocalPresetLabel;
const localPageInput = filter.models.localPageInput;
const onlineServices = computed(() => props.availableServices.filter((service) => service.key !== 'local'));

function updateLimit(value: string): void {
    form.data.limit = value;
}

function updateSource(value: string): void {
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
                    <Select :model-value="form.data.service" @update:model-value="(value) => filter.actions.updateService(String(value))">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in onlineServices" :key="service.key" :value="service.key">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <template v-if="isLocalFeed && activeSchema">
                    <div class="form-field">
                        <label class="form-label">Preset</label>
                        <Select
                            :model-value="selectedLocalPreset"
                            @update:model-value="(value) => filter.actions.applyLocalPreset(String(value))"
                        >
                            <SelectTrigger class="w-full">
                                <span class="truncate">
                                    {{ selectedLocalPresetLabel || 'Select a preset…' }}
                                </span>
                            </SelectTrigger>
                            <SelectContent>
                                <template v-for="group in localPresetGroups" :key="group.label">
                                    <div class="px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-twilight-indigo-400/80">
                                        {{ group.label }}
                                    </div>
                                    <SelectItem v-for="preset in group.presets" :key="preset.value" :value="preset.value">
                                        {{ preset.label }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                        <p class="form-help">
                            Presets set sensible defaults. You can tweak fields below after applying.
                        </p>
                    </div>

                    <TabFilterLimitField :model-value="form.data.limit" @update:model-value="updateLimit" />

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
                        <p class="form-help">Jump to a specific local page (1-based).</p>
                    </div>

                    <div v-if="localSourceField" class="form-field">
                        <label class="form-label">{{ localSourceField.label }}</label>
                        <Select :model-value="form.data.source" @update:model-value="(value) => updateSource(String(value))">
                            <SelectTrigger class="w-full">
                                <SelectValue :placeholder="localSourceField.placeholder || 'Select…'" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in localSourceField.options ?? []"
                                    :key="String(option.value)"
                                    :value="option.value as any"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
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
                    <TabFilterLimitField :model-value="form.data.limit" @update:model-value="updateLimit" />

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
