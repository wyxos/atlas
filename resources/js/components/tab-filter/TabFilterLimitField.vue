<script setup lang="ts">
import { computed } from 'vue';
import SearchableDropdown from '@/components/ui/SearchableDropdown.vue';

interface Props {
    modelValue: string;
    options: readonly string[];
}

const props = defineProps<Props>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const dropdownOptions = computed(() => props.options.map((option) => ({
    label: option,
    value: option,
})));
</script>

<template>
    <div class="form-field">
        <label class="form-label">Limit</label>

        <SearchableDropdown
            :model-value="modelValue"
            :options="dropdownOptions"
            search-placeholder="Search limits..."
            @update:model-value="(value) => emit('update:modelValue', String(value))"
        />
    </div>
</template>
