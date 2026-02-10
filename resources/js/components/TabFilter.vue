<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import Checkbox from '@/components/ui/Checkbox.vue';
import { SlidersHorizontal } from 'lucide-vue-next';
import { useBrowseForm } from '@/composables/useBrowseForm';
import { Masonry } from '@wyxos/vibe';
import Input from '@/components/ui/input/Input.vue';
import type { ServiceOption, ServiceFilterField } from '@/composables/useBrowseService';
import { computed, watch, ref } from 'vue';
import { coerceBoolean } from '@/utils/coerceBoolean';

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

// Use the singleton composable
const form = useBrowseForm();

const selectedServiceDef = computed(() => {
    if (!form.data.service) {
        return null;
    }
    // In online mode, `local` is not a valid service selection.
    if (form.data.feed === 'online' && form.data.service === 'local') {
        return null;
    }
    return props.availableServices.find((s) => s.key === form.data.service) ?? null;
});

const selectedLocalDef = computed(() => props.localDef ?? null);
const inputClass = 'text-twilight-indigo-100 placeholder:text-twilight-indigo-300';

const activeSchema = computed(() => {
    if (form.data.feed === 'local') {
        return selectedLocalDef.value?.schema ?? null;
    }

    return selectedServiceDef.value?.schema ?? null;
});

const localSourceField = computed(() => {
    if (form.data.feed !== 'local') {
        return null;
    }

    const schema = activeSchema.value;
    if (!schema?.fields?.length) {
        return null;
    }

    return schema.fields.find((f) => f.uiKey === 'source') ?? null;
});

const visibleServiceFields = computed(() => {
    const schema = activeSchema.value;
    if (!schema?.fields?.length) {
        return [] as ServiceFilterField[];
    }

    return schema.fields.filter((f) => {
        if (f.type === 'hidden') {
            return false;
        }
        if (f.uiKey === 'page' || f.uiKey === 'limit') {
            return false;
        }
        if (form.data.feed === 'local' && f.uiKey === 'source') {
            return false;
        }
        return true;
    });
});

type LocalPreset = {
    label: string;
    value: string;
    // Only serviceFilters keys go here; global keys (limit/source) are left untouched.
    filters: Record<string, unknown>;
};

const localPresets = computed<LocalPreset[]>(() => {
    if (form.data.feed !== 'local') {
        return [];
    }

    const baseCap = null;
    const moderatedCap = 2;

    return [
        {
            label: 'All',
            value: 'all',
            filters: {
                downloaded: 'any',
                reaction_mode: 'any',
                auto_disliked: 'any',
                blacklisted: 'any',
                blacklist_type: 'any',
                max_previewed_count: baseCap,
                sort: 'downloaded_at',
                seed: null,
            },
        },
        {
            label: 'Reacted (Random)',
            value: 'reacted_random',
            filters: {
                downloaded: 'any',
                reaction_mode: 'reacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'random',
            },
        },
        {
            label: 'Reacted (Newest)',
            value: 'reacted_newest',
            filters: {
                downloaded: 'any',
                reaction_mode: 'reacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'reaction_at',
            },
        },
        {
            label: 'Reacted (Oldest)',
            value: 'reacted_oldest',
            filters: {
                downloaded: 'any',
                reaction_mode: 'reacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'reaction_at_asc',
            },
        },
        {
            label: 'Inbox (Fresh)',
            value: 'inbox_fresh',
            filters: {
                downloaded: 'any',
                reaction_mode: 'unreacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'created_at',
            },
        },
        {
            label: 'Inbox (Newest)',
            value: 'inbox_newest',
            filters: {
                downloaded: 'any',
                reaction_mode: 'unreacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'created_at',
            },
        },
        {
            label: 'Inbox (Oldest)',
            value: 'inbox_oldest',
            filters: {
                downloaded: 'any',
                reaction_mode: 'unreacted',
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: baseCap,
                sort: 'created_at_asc',
            },
        },
        {
            label: 'Disliked (Manual)',
            value: 'disliked_manual',
            filters: {
                downloaded: 'any',
                reaction_mode: 'types',
                reaction: ['dislike'],
                blacklisted: 'no',
                auto_disliked: 'no',
                max_previewed_count: moderatedCap,
                sort: 'reaction_at',
            },
        },
        {
            label: 'Disliked (Auto)',
            value: 'disliked_auto',
            filters: {
                downloaded: 'any',
                reaction_mode: 'types',
                reaction: ['dislike'],
                blacklisted: 'no',
                auto_disliked: 'yes',
                max_previewed_count: moderatedCap,
                sort: 'reaction_at',
            },
        },
        {
            label: 'Blacklisted (Any)',
            value: 'blacklisted_any',
            filters: {
                downloaded: 'any',
                reaction_mode: 'any',
                blacklisted: 'yes',
                blacklist_type: 'any',
                max_previewed_count: moderatedCap,
                sort: 'blacklisted_at',
            },
        },
        {
            label: 'Blacklisted (Manual)',
            value: 'blacklisted_manual',
            filters: {
                reaction_mode: 'any',
                blacklisted: 'yes',
                blacklist_type: 'manual',
                max_previewed_count: moderatedCap,
                sort: 'blacklisted_at',
            },
        },
        {
            label: 'Blacklisted (Auto)',
            value: 'blacklisted_auto',
            filters: {
                reaction_mode: 'any',
                blacklisted: 'yes',
                blacklist_type: 'auto',
                max_previewed_count: moderatedCap,
                sort: 'blacklisted_at',
            },
        },
    ];
});

function valueOrDefault(field: ServiceFilterField): unknown {
    const existing = form.data.serviceFilters[field.uiKey];
    if (existing !== undefined && existing !== null && existing !== '') {
        return existing;
    }

    return field.default;
}

function checkboxGroupSelection(field: ServiceFilterField): string[] {
    const raw = valueOrDefault(field);
    if (Array.isArray(raw)) {
        return raw.map((v) => String(v));
    }
    return [];
}

function setCheckboxGroupValue(field: ServiceFilterField, value: string, checked: boolean): void {
    // Special-case: local file type filter has an "All" option that is exclusive.
    // Values: all, image, video, audio, other
    if (field.uiKey === 'file_type') {
        const allTypes = ['image', 'video', 'audio', 'other'];
        const current = new Set(checkboxGroupSelection(field));

        if (checked) {
            if (value === 'all') {
                updateServiceFilterValue(field.uiKey, ['all']);
                return;
            }

            current.delete('all');
            current.add(value);

            const hasAll = allTypes.every((t) => current.has(t));
            updateServiceFilterValue(field.uiKey, hasAll ? ['all'] : Array.from(current));
            return;
        }

        // Unchecking
        if (value === 'all') {
            // Keep "all" selected unless the user explicitly selects specific types.
            updateServiceFilterValue(field.uiKey, ['all']);
            return;
        }

        current.delete(value);

        if (current.size === 0) {
            updateServiceFilterValue(field.uiKey, ['all']);
            return;
        }

        updateServiceFilterValue(field.uiKey, Array.from(current));
        return;
    }

    const current = new Set(checkboxGroupSelection(field));

    if (checked) {
        current.add(value);
    } else {
        current.delete(value);
    }

    const ordered = (field.options || [])
        .map((opt) => String(opt.value))
        .filter((v) => current.has(v));

    updateServiceFilterValue(field.uiKey, ordered);
}

function placeholderForField(field: ServiceFilterField): string | undefined {
    if (field.placeholder) {
        return field.placeholder;
    }

    // Prefer using hint text as placeholder for text/number inputs.
    if ((field.type === 'text' || field.type === 'number') && field.description) {
        return field.description;
    }

    return undefined;
}

function shouldShowDescriptionBelow(field: ServiceFilterField): boolean {
    if (!field.description) {
        return false;
    }

    // Boolean renders its hint inline.
    if (field.type === 'boolean') {
        return false;
    }

    // If we're using description as placeholder for inputs, don't repeat it below.
    if ((field.type === 'text' || field.type === 'number') && !field.placeholder) {
        return false;
    }

    return true;
}

function updateService(nextService: string): void {
    // Online-mode service selector must not allow switching to the local pseudo-service.
    if (nextService === 'local') {
        return;
    }
    const defaults = props.availableServices.find((s) => s.key === nextService)?.defaults;
    form.setService(nextService, defaults);
}

function updateServiceFilterValue(uiKey: string, value: unknown): void {
    form.data.serviceFilters[uiKey] = value;
}

function isCheckboxGroupDisabled(field: ServiceFilterField): boolean {
    if (form.data.feed !== 'local') {
        return false;
    }

    // Local reaction types only apply when reaction_mode is "types".
    if (field.uiKey === 'reaction') {
        const mode = String(form.data.serviceFilters.reaction_mode ?? 'any');
        return mode !== 'types';
    }

    return false;
}

// Handle apply button
function handleApply(): void {
    emit('apply');
    emit('update:open', false);
}

// Handle reset button
function handleReset(): void {
    const feed = form.data.feed;
    const tabId = form.data.tab_id;

    form.reset();

    // Keep the current tab and browse mode (online/local) so reset doesn't "kick" the user out.
    form.data.feed = feed;
    form.data.tab_id = tabId;
    form.data.page = 1;
    emit('reset');
}

function ensureRandomSeed(): void {
    if (form.data.feed !== 'local') {
        return;
    }

    if (form.data.serviceFilters.sort !== 'random') {
        return;
    }

    const raw = form.data.serviceFilters.seed;
    const seed = typeof raw === 'number' ? raw : Number(raw);
    if (Number.isFinite(seed) && seed > 0) {
        return;
    }

    // Typesense requires a positive integer seed.
    form.data.serviceFilters.seed = Math.floor(Date.now() / 1000);
}

watch(
    () => [form.data.feed, form.data.serviceFilters.sort],
    () => ensureRandomSeed(),
    { immediate: true }
);

const selectedLocalPreset = ref<string>('');
const selectedLocalPresetLabel = computed(() => {
    if (!selectedLocalPreset.value) {
        return null;
    }
    return localPresets.value.find((p) => p.value === selectedLocalPreset.value)?.label ?? selectedLocalPreset.value;
});

function applyLocalPreset(value: string): void {
    selectedLocalPreset.value = value;

    const preset = localPresets.value.find((p) => p.value === value);
    if (!preset) {
        return;
    }

    // Apply only the preset keys; user may already have other serviceFilters.
    form.data.serviceFilters = {
        ...form.data.serviceFilters,
        ...preset.filters,
    };

    form.data.page = 1;
    ensureRandomSeed();
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
            <div class="flex-1 p-6 overflow-y-auto space-y-6">
                <!-- Service Filter (online only) -->
                <div v-if="form.data.feed === 'online'" class="form-field">
                    <label class="form-label">Service</label>
                    <Select :model-value="form.data.service" @update:model-value="(v) => updateService(v as string)">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices.filter((s) => s.key !== 'local')" :key="service.key" :value="service.key">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Local mode fields -->
                <template v-if="form.data.feed === 'local' && activeSchema">
                    <div class="form-field">
                        <label class="form-label">Preset</label>
                        <Select :model-value="selectedLocalPreset" @update:model-value="(v) => applyLocalPreset(v as string)">
                            <SelectTrigger class="w-full">
                                <span class="truncate">
                                    {{ selectedLocalPresetLabel || 'Select a preset…' }}
                                </span>
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="preset in localPresets" :key="preset.value" :value="preset.value">
                                    {{ preset.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p class="form-help">
                            Presets set sensible defaults. You can tweak fields below after applying.
                        </p>
                    </div>

                    <!-- Limit (global) -->
                    <div class="form-field">
                        <label class="form-label">Limit</label>
                        <Select v-model="form.data.limit">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="20">20</SelectItem>
                                <SelectItem value="40">40</SelectItem>
                                <SelectItem value="60">60</SelectItem>
                                <SelectItem value="80">80</SelectItem>
                                <SelectItem value="100">100</SelectItem>
                                <SelectItem value="200">200</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!-- Source (global) -->
                    <div v-if="localSourceField" class="form-field">
                        <label class="form-label">
                            {{ localSourceField.label }}
                        </label>
                        <Select :model-value="form.data.source" @update:model-value="(v) => (form.data.source = v as string)">
                            <SelectTrigger class="w-full">
                                <SelectValue :placeholder="localSourceField.placeholder || 'Select…'" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in (localSourceField.options || [])"
                                    :key="String(opt.value)"
                                    :value="opt.value as any"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <p v-if="shouldShowDescriptionBelow(localSourceField)" class="form-help">{{ localSourceField.description }}</p>
                    </div>

                    <div v-for="field in visibleServiceFields" :key="field.uiKey" class="form-field">
                        <label class="form-label">
                            {{ field.label }}
                        </label>

                        <div v-if="field.type === 'checkbox-group'" class="space-y-2">
                                <div class="flex flex-wrap gap-2">
                                <Checkbox
                                    v-for="opt in (field.options || [])"
                                    :key="String(opt.value)"
                                    :model-value="checkboxGroupSelection(field).includes(String(opt.value))"
                                    :disabled="isCheckboxGroupDisabled(field)"
                                    @update:model-value="(checked: boolean) => setCheckboxGroupValue(field, String(opt.value), checked)"
                                >
                                    {{ opt.label }}
                                </Checkbox>
                            </div>
                        </div>

                        <div v-else-if="field.type === 'boolean'" class="flex items-center justify-between">
                            <span class="form-inline-help">{{ field.description || '' }}</span>
                            <Switch
                                :model-value="coerceBoolean(valueOrDefault(field))"
                                @update:model-value="(v: boolean) => updateServiceFilterValue(field.uiKey, v)"
                            />
                        </div>

                        <Select
                            v-else-if="field.type === 'select'"
                            :model-value="(valueOrDefault(field) ?? null) as any"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue :placeholder="field.placeholder || 'Select…'" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in (field.options || [])"
                                    :key="String(opt.value)"
                                    :value="opt.value as any"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Input
                            v-else-if="field.type === 'number'"
                            :model-value="(valueOrDefault(field) ?? '') as any"
                            type="number"
                            :placeholder="placeholderForField(field)"
                            :min="field.min"
                            :max="field.max"
                            :step="field.step"
                            :class="inputClass"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <RadioGroup
                            v-else-if="field.type === 'radio'"
                            :model-value="String(valueOrDefault(field) ?? '')"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                            class="flex flex-wrap items-center gap-4"
                        >
                            <div v-for="opt in (field.options || [])" :key="String(opt.value)" class="flex items-center gap-2">
                                <RadioGroupItem :value="String(opt.value)" />
                                <span class="form-option-label">{{ opt.label }}</span>
                            </div>
                        </RadioGroup>

                        <Input
                            v-else
                            :model-value="(valueOrDefault(field) ?? '') as any"
                            type="text"
                            :placeholder="placeholderForField(field)"
                            :class="inputClass"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <p v-if="shouldShowDescriptionBelow(field)" class="form-help">{{ field.description }}</p>
                    </div>
                </template>

                <!-- Service fields (online services only). If no service selected, show nothing. -->
                <template v-if="form.data.feed === 'online' && selectedServiceDef">
                    <!-- Limit (global across all services) -->
                    <div class="form-field">
                        <label class="form-label">Limit</label>
                        <Select v-model="form.data.limit">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="20">20</SelectItem>
                                <SelectItem value="40">40</SelectItem>
                                <SelectItem value="60">60</SelectItem>
                                <SelectItem value="80">80</SelectItem>
                                <SelectItem value="100">100</SelectItem>
                                <SelectItem value="200">200</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-for="field in visibleServiceFields" :key="field.uiKey" class="form-field">
                        <label class="form-label">
                            {{ field.label }}
                        </label>

                        <div v-if="field.type === 'boolean'" class="flex items-center justify-between">
                            <span class="form-inline-help">{{ field.description || '' }}</span>
                            <Switch
                                :model-value="coerceBoolean(valueOrDefault(field))"
                                @update:model-value="(v: boolean) => updateServiceFilterValue(field.uiKey, v)"
                            />
                        </div>

                        <Select
                            v-else-if="field.type === 'select'"
                            :model-value="(valueOrDefault(field) ?? null) as any"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        >
                            <SelectTrigger class="w-full">
                                <SelectValue :placeholder="field.placeholder || 'Select…'" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opt in (field.options || [])"
                                    :key="String(opt.value)"
                                    :value="opt.value as any"
                                >
                                    {{ opt.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <Input
                            v-else-if="field.type === 'number'"
                            :model-value="(valueOrDefault(field) ?? '') as any"
                            type="number"
                            :placeholder="placeholderForField(field)"
                            :min="field.min"
                            :max="field.max"
                            :step="field.step"
                            :class="inputClass"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <RadioGroup
                            v-else-if="field.type === 'radio'"
                            :model-value="String(valueOrDefault(field) ?? '')"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                            class="flex flex-wrap items-center gap-4"
                        >
                            <div v-for="opt in (field.options || [])" :key="String(opt.value)" class="flex items-center gap-2">
                                <RadioGroupItem :value="String(opt.value)" />
                                <span class="form-option-label">{{ opt.label }}</span>
                            </div>
                        </RadioGroup>

                        <Input
                            v-else
                            :model-value="(valueOrDefault(field) ?? '') as any"
                            type="text"
                            :placeholder="placeholderForField(field)"
                            :class="inputClass"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <p v-if="shouldShowDescriptionBelow(field)" class="form-help">{{ field.description }}</p>
                    </div>
                </template>
            </div>
            <SheetFooter>
                <Button variant="destructive" @click="handleReset">
                    Reset
                </Button>
                <Button variant="default" @click="handleApply">
                    Apply
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
