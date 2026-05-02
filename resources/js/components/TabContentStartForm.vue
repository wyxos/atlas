<script setup lang="ts">
import { computed, watch } from 'vue';
import { Play } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from './ui/select';
import { Switch } from '@/components/ui/switch';
import Input from '@/components/ui/input/Input.vue';
import TabFilterFieldControl from '@/components/tab-filter/TabFilterFieldControl.vue';
import TabFilterLimitField from '@/components/tab-filter/TabFilterLimitField.vue';
import { useBrowseForm } from '@/composables/useBrowseForm';
import type { ServiceOption } from '@/lib/browseCatalog';
import {
    LOCAL_TAB_FILTER_PRESET_GROUPS,
    LOCAL_TAB_FILTER_PRESETS,
    getLocalSourceField,
    getTabFilterLimitOptions,
    getVisibleTabFilterFields,
    isTabFilterFieldDisabled,
    shouldShowTabFilterDescriptionBelow,
    type TabFilterFieldUpdate,
} from '@/utils/tabFilter';

interface Props {
    availableServices: ServiceOption[];
    localService: ServiceOption | null | undefined;
    isLoading: boolean;
    setLocalMode: (value: boolean) => void;
    updateService: (service: string) => void;
    updateSource: (source: string) => void;
    applyService: () => void | Promise<void>;
}

const props = defineProps<Props>();
const form = useBrowseForm();

const inputClass = 'text-twilight-indigo-100 placeholder:text-twilight-indigo-300';
const onlineServices = computed(() => props.availableServices.filter((entry) => entry.key !== 'local'));
const selectedServiceDef = computed(() => {
    if (form.data.feed !== 'online' || !form.data.service) {
        return null;
    }

    return onlineServices.value.find((service) => service.key === form.data.service) ?? null;
});
const activeSchema = computed(() => (
    form.data.feed === 'local'
        ? props.localService?.schema ?? null
        : selectedServiceDef.value?.schema ?? null
));
const visibleServiceFields = computed(() => getVisibleTabFilterFields(activeSchema.value, form.data.feed));
const localSourceField = computed(() => (
    form.data.feed === 'local' ? getLocalSourceField(activeSchema.value) : null
));
const limitOptions = computed(() => getTabFilterLimitOptions(form.data.feed, activeSchema.value));
const selectedLocalPreset = computed<string>({
    get() {
        if (form.data.feed !== 'local') {
            return '';
        }

        const rawPreset = form.data.serviceFilters.local_preset;
        return typeof rawPreset === 'string' ? rawPreset : '';
    },
    set(value) {
        if (form.data.feed !== 'local') {
            return;
        }

        if (value.length > 0) {
            form.data.serviceFilters.local_preset = value;
            return;
        }

        delete form.data.serviceFilters.local_preset;
    },
});
const selectedLocalPresetLabel = computed(() => {
    if (!selectedLocalPreset.value) {
        return null;
    }

    return LOCAL_TAB_FILTER_PRESETS.find((preset) => preset.value === selectedLocalPreset.value)?.label ?? selectedLocalPreset.value;
});
const localPageInput = computed<number>({
    get() {
        const raw = form.data.page;
        const page = typeof raw === 'number' ? raw : Number(raw);
        return Number.isFinite(page) && page >= 1 ? Math.floor(page) : 1;
    },
    set(value) {
        const page = typeof value === 'number' ? value : Number(value);
        form.data.page = Number.isFinite(page) && page >= 1 ? Math.floor(page) : 1;
    },
});

function updateLimit(value: string): void {
    form.data.limit = value;
}

function updateLocalPage(value: string | number): void {
    const page = typeof value === 'number' ? value : Number(value);
    localPageInput.value = page;
}

function handleSourceUpdate(value: string): void {
    form.data.source = value;
    props.updateSource(value);
}

function handleFieldUpdate(field: TabFilterFieldUpdate): void {
    form.data.serviceFilters[field.uiKey] = field.value;
}

function applyLocalPreset(value: string): void {
    selectedLocalPreset.value = value;

    const preset = LOCAL_TAB_FILTER_PRESETS.find((item) => item.value === value);
    if (!preset) {
        return;
    }

    form.data.serviceFilters = {
        ...form.data.serviceFilters,
        ...preset.filters,
    };
    form.data.page = 1;
    ensureRandomSeed();
}

function ensureRandomSeed(): void {
    if (form.data.feed !== 'local') {
        return;
    }

    if (form.data.serviceFilters.sort !== 'random') {
        return;
    }

    const rawSeed = form.data.serviceFilters.seed;
    const seed = typeof rawSeed === 'number' ? rawSeed : Number(rawSeed);
    if (Number.isFinite(seed) && seed > 0) {
        return;
    }

    form.data.serviceFilters.seed = Math.floor(Date.now() / 1000);
}

watch(
    [onlineServices, () => form.data.feed],
    ([services]) => {
        if (form.data.feed !== 'online' || form.data.service || services.length === 0) {
            return;
        }

        props.updateService(services[0].key);
    },
    { immediate: true },
);

watch(
    () => [form.data.feed, form.data.serviceFilters.sort],
    () => ensureRandomSeed(),
    { immediate: true },
);
</script>

<template>
    <div class="flex h-full min-h-0 justify-end overflow-hidden bg-prussian-blue-950/30" data-test="new-tab-form">
        <section
            class="flex h-full min-h-0 w-full max-w-[34rem] flex-col border-l border-white/10 bg-prussian-blue-900/95 shadow-[-40px_0_120px_-80px_rgba(0,0,0,0.9)]"
            data-test="new-tab-setup-sheet"
        >
            <header class="border-b border-white/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-twilight-indigo-400">New tab</p>
                <h2 class="mt-1 text-xl font-semibold text-twilight-indigo-100">Start Browsing</h2>
            </header>

            <div class="flex-1 space-y-6 overflow-y-auto p-6">
                <div class="form-field">
                    <label class="form-label">Source</label>
                    <div class="flex h-10 items-center justify-between rounded-md border border-twilight-indigo-500/40 bg-prussian-blue-800/70 px-3">
                        <span class="text-sm text-twilight-indigo-300"
                            :class="{ 'text-twilight-indigo-100 font-medium': !form.isLocalMode.value }">Online</span>
                        <Switch :model-value="form.isLocalMode.value"
                            @update:model-value="setLocalMode"
                            data-test="source-type-switch" />
                        <span class="text-sm text-twilight-indigo-300"
                            :class="{ 'text-twilight-indigo-100 font-medium': form.isLocalMode.value }">Local</span>
                    </div>
                </div>

                <div v-if="form.data.feed === 'online'" class="form-field">
                    <label class="form-label">Service</label>
                    <Select :model-value="form.data.service" @update:model-value="(value) => updateService(value as string)" :disabled="isLoading">
                        <SelectTrigger class="w-full" data-test="service-select-trigger">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in onlineServices"
                                :key="service.key" :value="service.key" data-test="service-select-item">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <template v-if="form.data.feed === 'local' && activeSchema">
                    <div class="form-field">
                        <label class="form-label">Preset</label>
                        <Select
                            :model-value="selectedLocalPreset"
                            :disabled="isLoading"
                            @update:model-value="(value) => applyLocalPreset(String(value))"
                        >
                            <SelectTrigger class="w-full">
                                <span class="truncate">
                                    {{ selectedLocalPresetLabel || 'Select a preset…' }}
                                </span>
                            </SelectTrigger>
                            <SelectContent>
                                <template v-for="group in LOCAL_TAB_FILTER_PRESET_GROUPS" :key="group.label">
                                    <div class="px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-twilight-indigo-400/80">
                                        {{ group.label }}
                                    </div>
                                    <SelectItem v-for="preset in group.presets" :key="preset.value" :value="preset.value">
                                        {{ preset.label }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
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
                            :disabled="isLoading"
                            @update:model-value="updateLocalPage"
                        />
                    </div>

                    <div v-if="localSourceField" class="form-field">
                        <label class="form-label">{{ localSourceField.label }}</label>
                        <Select :model-value="form.data.source" :disabled="isLoading" @update:model-value="(value) => handleSourceUpdate(String(value))">
                            <SelectTrigger class="w-full" data-test="source-select-trigger">
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
                        :disabled="isLoading || isTabFilterFieldDisabled(field, form.data.feed, form.data.serviceFilters)"
                        :input-class="inputClass"
                        @update:model-value="(value) => handleFieldUpdate({ uiKey: field.uiKey, value })"
                    />
                </template>

                <template v-if="form.data.feed === 'online' && selectedServiceDef">
                    <TabFilterLimitField :model-value="form.data.limit" :options="limitOptions" @update:model-value="updateLimit" />

                    <TabFilterFieldControl
                        v-for="field in visibleServiceFields"
                        :key="field.uiKey"
                        :field="field"
                        :model-value="form.data.serviceFilters[field.uiKey]"
                        :disabled="isLoading"
                        :input-class="inputClass"
                        @update:model-value="(value) => handleFieldUpdate({ uiKey: field.uiKey, value })"
                    />
                </template>
            </div>

            <footer class="border-t border-white/10 p-6">
                <Button @click="applyService" size="sm" class="w-full" data-test="play-button"
                    :disabled="form.data.feed === 'online' && !form.data.service">
                    <Play :size="16" />
                    <span>Start</span>
                </Button>
            </footer>
        </section>
    </div>
</template>
