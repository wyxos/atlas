<script setup lang="ts">
import { computed } from 'vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import Checkbox from '@/components/ui/Checkbox.vue';
import Input from '@/components/ui/input/Input.vue';
import type { ServiceFilterField } from '@/composables/useBrowseService';
import { coerceBoolean } from '@/utils/coerceBoolean';
import {
    getTabFilterCheckboxGroupSelection,
    getTabFilterFieldPlaceholder,
    getTabFilterValueOrDefault,
    shouldShowTabFilterDescriptionBelow,
    toggleTabFilterCheckboxGroupValue,
} from '@/utils/tabFilter';

interface Props {
    field: ServiceFilterField;
    modelValue?: unknown;
    disabled?: boolean;
    inputClass?: string;
}

const props = withDefaults(defineProps<Props>(), {
    modelValue: undefined,
    disabled: false,
    inputClass: 'text-twilight-indigo-100 placeholder:text-twilight-indigo-300',
});

const emit = defineEmits<{
    'update:modelValue': [value: unknown];
}>();

const resolvedValue = computed(() => getTabFilterValueOrDefault(props.field, props.modelValue));
const placeholder = computed(() => getTabFilterFieldPlaceholder(props.field));
const showDescriptionBelow = computed(() => shouldShowTabFilterDescriptionBelow(props.field));
const checkboxSelection = computed(() => getTabFilterCheckboxGroupSelection(props.field, props.modelValue));

function updateCheckboxGroupValue(value: string, checked: boolean): void {
    emit('update:modelValue', toggleTabFilterCheckboxGroupValue(props.field, props.modelValue, value, checked));
}
</script>

<template>
    <div class="form-field">
        <label v-if="field.type !== 'checkbox'" class="form-label">{{ field.label }}</label>

        <div v-if="field.type === 'checkbox-group'" class="space-y-2">
            <div class="flex flex-wrap gap-2">
                <Checkbox
                    v-for="option in field.options ?? []"
                    :key="String(option.value)"
                    :model-value="checkboxSelection.includes(String(option.value))"
                    :disabled="disabled"
                    @update:model-value="(checked: boolean) => updateCheckboxGroupValue(String(option.value), checked)"
                >
                    {{ option.label }}
                </Checkbox>
            </div>
        </div>

        <div v-else-if="field.type === 'boolean'" class="flex items-center justify-between">
            <span class="form-inline-help">{{ field.description || '' }}</span>
            <Switch
                :model-value="coerceBoolean(resolvedValue)"
                :disabled="disabled"
                @update:model-value="(value: boolean) => emit('update:modelValue', value)"
            />
        </div>

        <Checkbox
            v-else-if="field.type === 'checkbox'"
            :model-value="coerceBoolean(resolvedValue)"
            :disabled="disabled"
            @update:model-value="(value: boolean) => emit('update:modelValue', value)"
        >
            {{ field.description || field.label }}
        </Checkbox>

        <Select
            v-else-if="field.type === 'select'"
            :model-value="(resolvedValue ?? null) as any"
            @update:model-value="(value) => emit('update:modelValue', value)"
        >
            <SelectTrigger class="w-full">
                <SelectValue :placeholder="placeholder || 'Select…'" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="option in field.options ?? []"
                    :key="String(option.value)"
                    :value="option.value as any"
                >
                    {{ option.label }}
                </SelectItem>
            </SelectContent>
        </Select>

        <Input
            v-else-if="field.type === 'number'"
            :model-value="(resolvedValue ?? '') as any"
            type="number"
            :placeholder="placeholder"
            :min="field.min"
            :max="field.max"
            :step="field.step"
            :class="inputClass"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />

        <RadioGroup
            v-else-if="field.type === 'radio'"
            :model-value="String(resolvedValue ?? '')"
            @update:model-value="(value) => emit('update:modelValue', value)"
            class="flex flex-wrap items-center gap-4"
        >
            <div v-for="option in field.options ?? []" :key="String(option.value)" class="flex items-center gap-2">
                <RadioGroupItem :value="String(option.value)" />
                <span class="form-option-label">{{ option.label }}</span>
            </div>
        </RadioGroup>

        <Input
            v-else
            :model-value="(resolvedValue ?? '') as any"
            type="text"
            :placeholder="placeholder"
            :class="inputClass"
            @update:model-value="(value) => emit('update:modelValue', value)"
        />

        <p v-if="showDescriptionBelow" class="form-help">{{ field.description }}</p>
    </div>
</template>
