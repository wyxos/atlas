import { computed, watch, type Ref } from 'vue';
import { useBrowseForm } from '@/composables/useBrowseForm';
import type { ServiceFilterField, ServiceOption } from '@/lib/browseCatalog';
import {
    LOCAL_TAB_FILTER_PRESET_GROUPS,
    LOCAL_TAB_FILTER_PRESETS,
    getLocalSourceField,
    getVisibleTabFilterFields,
    isTabFilterFieldDisabled,
} from '@/utils/tabFilter';

interface UseTabFilterStateOptions {
    availableServices: Ref<ServiceOption[]>;
    localDef: Ref<ServiceOption | null | undefined>;
    onApply: () => void;
    onReset: () => void;
    onOpenChange: (value: boolean) => void;
}

export function useTabFilterState(options: UseTabFilterStateOptions) {
    const form = useBrowseForm();

    const selectedServiceDef = computed(() => {
        if (!form.data.service) {
            return null;
        }

        if (form.data.feed === 'online' && form.data.service === 'local') {
            return null;
        }

        return options.availableServices.value.find((service) => service.key === form.data.service) ?? null;
    });

    const activeSchema = computed(() => {
        if (form.data.feed === 'local') {
            return options.localDef.value?.schema ?? null;
        }

        return selectedServiceDef.value?.schema ?? null;
    });

    const visibleServiceFields = computed(() => getVisibleTabFilterFields(activeSchema.value, form.data.feed));
    const localSourceField = computed(() =>
        form.data.feed === 'local' ? getLocalSourceField(activeSchema.value) : null,
    );
    const localPresets = computed(() => (form.data.feed === 'local' ? LOCAL_TAB_FILTER_PRESETS : []));
    const localPresetGroups = computed(() => (form.data.feed === 'local' ? LOCAL_TAB_FILTER_PRESET_GROUPS : []));
    const isLocalFeed = computed(() => form.data.feed === 'local');
    const isOnlineFeed = computed(() => form.data.feed === 'online');

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

    function updateService(nextService: string): void {
        if (nextService === 'local') {
            return;
        }

        const defaults = options.availableServices.value.find((service) => service.key === nextService)?.defaults;
        form.setService(nextService, defaults);
    }

    function updateServiceFilterValue(uiKey: string, value: unknown): void {
        form.data.serviceFilters[uiKey] = value;
    }

    function isFieldDisabled(field: ServiceFilterField): boolean {
        return isTabFilterFieldDisabled(field, form.data.feed, form.data.serviceFilters);
    }

    function handleApply(): void {
        options.onApply();
        options.onOpenChange(false);
    }

    function handleReset(): void {
        const feed = form.data.feed;
        const tabId = form.data.tab_id;

        form.reset();
        form.data.feed = feed;
        form.data.tab_id = tabId;
        form.data.page = 1;
        options.onReset();
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
        () => [form.data.feed, form.data.serviceFilters.sort],
        () => ensureRandomSeed(),
        { immediate: true },
    );

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

        return localPresets.value.find((preset) => preset.value === selectedLocalPreset.value)?.label ?? selectedLocalPreset.value;
    });

    function applyLocalPreset(value: string): void {
        selectedLocalPreset.value = value;

        const preset = LOCAL_TAB_FILTER_PRESETS.find((item) => item.value === value);
        if (!preset) {
            return;
        }

        form.data.serviceFilters = {
            ...form.data.serviceFilters,
            not_found: 'no',
            ...preset.filters,
        };
        form.data.page = 1;

        ensureRandomSeed();
    }

    return {
        form,
        derived: {
            isLocalFeed,
            isOnlineFeed,
            selectedServiceDef,
            activeSchema,
            visibleServiceFields,
            localSourceField,
            localPresets,
            localPresetGroups,
            selectedLocalPreset,
            selectedLocalPresetLabel,
        },
        models: {
            localPageInput,
        },
        actions: {
            updateService,
            updateServiceFilterValue,
            isFieldDisabled,
            handleApply,
            handleReset,
            applyLocalPreset,
        },
    };
}
