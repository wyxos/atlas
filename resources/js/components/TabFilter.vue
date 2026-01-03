<script setup lang="ts">
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger, SheetFooter } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { SlidersHorizontal } from 'lucide-vue-next';
import { useBrowseForm } from '@/composables/useBrowseForm';
import { Masonry } from '@wyxos/vibe';
import Input from '@/components/ui/input/Input.vue';
import type { ServiceOption, ServiceFilterField } from '@/composables/useBrowseService';
import { computed } from 'vue';

interface Props {
    open: boolean;
    availableServices: ServiceOption[];
    masonry?: InstanceType<typeof Masonry> | null;
}

const props = withDefaults(defineProps<Props>(), {
    open: false,
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
    return props.availableServices.find((s) => s.key === form.data.service) ?? null;
});

const visibleServiceFields = computed(() => {
    const schema = selectedServiceDef.value?.schema;
    if (!schema?.fields?.length) {
        return [] as ServiceFilterField[];
    }

    return schema.fields.filter((f) => f.type !== 'hidden' && f.uiKey !== 'page' && f.uiKey !== 'limit');
});

function updateService(nextService: string): void {
    const defaults = props.availableServices.find((s) => s.key === nextService)?.defaults;
    form.setService(nextService, defaults);
}

function updateServiceFilterValue(uiKey: string, value: unknown): void {
    form.data.serviceFilters[uiKey] = value;
}

// Handle apply button
function handleApply(): void {
    emit('apply');
    emit('update:open', false);
}

// Handle reset button
function handleReset(): void {
    form.reset();
    emit('reset');
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
                <!-- Service Filter -->
                <div class="space-y-2">
                    <label class="text-sm font-medium text-regal-navy-100">Service</label>
                    <Select :model-value="form.data.service" @update:model-value="(v) => updateService(v as string)">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a service..." />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="service in availableServices" :key="service.key" :value="service.key">
                                {{ service.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <!-- Service fields (online services only). If no service selected, show nothing. -->
                <template v-if="form.data.feed === 'online' && selectedServiceDef">
                    <!-- Limit (global across all services) -->
                    <div class="space-y-2">
                        <label class="text-sm font-medium text-regal-navy-100">Limit</label>
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

                    <div v-for="field in visibleServiceFields" :key="field.uiKey" class="space-y-2">
                        <label class="text-sm font-medium text-regal-navy-100">
                            {{ field.label }}
                        </label>

                        <div v-if="field.type === 'boolean'" class="flex items-center justify-between">
                            <span class="text-sm text-twilight-indigo-200">{{ field.description || '' }}</span>
                            <Switch
                                :model-value="Boolean(form.data.serviceFilters[field.uiKey])"
                                @update:model-value="(v: boolean) => updateServiceFilterValue(field.uiKey, v)"
                            />
                        </div>

                        <Select
                            v-else-if="field.type === 'select'"
                            :model-value="(form.data.serviceFilters[field.uiKey] ?? null) as any"
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
                            :model-value="(form.data.serviceFilters[field.uiKey] ?? '') as any"
                            type="number"
                            :placeholder="field.placeholder"
                            :min="field.min"
                            :max="field.max"
                            :step="field.step"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <Input
                            v-else
                            :model-value="(form.data.serviceFilters[field.uiKey] ?? '') as any"
                            type="text"
                            :placeholder="field.placeholder"
                            @update:model-value="(v) => updateServiceFilterValue(field.uiKey, v)"
                        />

                        <p v-if="field.description" class="text-xs text-twilight-indigo-300">{{ field.description }}</p>
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
